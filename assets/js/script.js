'use strict';

/**
 * Media Downloader - Client Logic
 * Handles interactive quality detection, accessible format cards,
 * clipboard paste, real-time status feedback, and download processing.
 */
(() => {
    const $ = (id) => document.getElementById(id);

    // Form elements
    const form = $('download-form');
    const urlInput = $('media-url');
    const urlError = $('url-error');
    const pasteBtn = $('paste-btn');
    const qualityField = $('quality-field');
    const qualitySelect = $('quality');
    const qualityDetectStatus = $('quality-detect-status');
    const qualityHint = $('quality-hint');
    const submitBtn = $('submit-btn');
    const submitLabel = $('submit-label');
    const cancelBtn = $('cancel-btn');
    const statusText = $('status-text');
    const meter = $('meter');

    // Live preview elements
    const detectedInfo = $('detected-info');
    const detectedTitle = $('detected-title');
    const detectedResolution = $('detected-resolution');
    const detectedBadge = $('detected-badge');

    // Result & Error elements
    const errorBox = $('error-box');
    const errorTitle = $('error-title');
    const errorMessage = $('error-message');
    const resultBox = $('result');
    const resultMeta = $('result-meta');
    const resultName = $('result-name');
    const resultLink = $('result-link');
    const resultLinkLabel = $('result-link-label');
    const resultNote = $('result-note');
    const resetBtn = $('reset-btn');

    const requestTimeoutMs = Number(form.dataset.timeout) || 0;
    const csrfToken = form.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const MAX_URL_LENGTH = 2048;
    const IDLE_STATUS = 'Paste a link to begin.';

    let busy = false;
    let activeRequest = null;
    let currentFile = null;
    let infoDebounceTimer = null;
    let infoAbortController = null;
    let detectedInfoData = null;

    /* ------------------------------------------------------------------
       Level Meter Animation
       ------------------------------------------------------------------ */
    const BAR_COUNT = 48;

    function buildMeter() {
        if (!meter || meter.children.length > 0) return;
        const fragment = document.createDocumentFragment();
        for (let i = 0; i < BAR_COUNT; i++) {
            const x = i / (BAR_COUNT - 1);
            const envelope = 0.3 + 0.7 * Math.pow(Math.sin(Math.PI * x), 0.8);
            const wobble = 0.5 + 0.5 * Math.abs(Math.sin(i * 1.7) * Math.cos(i * 0.53 + 1.1));
            const height = Math.min(0.96, Math.max(0.14, envelope * wobble * 1.05)) * 100;

            const bar = document.createElement('span');
            bar.className = 'bar';
            bar.style.setProperty('--i', String(i));
            bar.style.setProperty('--h', height.toFixed(1) + '%');
            bar.style.setProperty('--lo', Math.max(8, height * 0.28).toFixed(1) + '%');
            bar.style.setProperty('--hi', Math.min(98, height * 1.15 + 18).toFixed(1) + '%');
            bar.style.setProperty('--dur', 520 + ((i * 37) % 7) * 60 + 'ms');
            bar.style.setProperty('--delay', '-' + ((i * 53) % 600) + 'ms');
            fragment.appendChild(bar);
        }
        meter.appendChild(fragment);
    }

    const setMeter = (state) => {
        if (meter && meter.dataset.state !== state) {
            meter.dataset.state = state;
        }
    };

    /* ------------------------------------------------------------------
       Status & UI Helpers
       ------------------------------------------------------------------ */
    function setStatus(text, tone) {
        if (!statusText) return;
        statusText.textContent = text;
        if (tone) {
            statusText.dataset.tone = tone;
        } else {
            delete statusText.dataset.tone;
        }
    }

    const selectedFormat = () => form.querySelector('input[name="format"]:checked')?.value || 'mp4';

    function updateSubmitLabel() {
        const fmt = selectedFormat().toUpperCase();
        if (submitLabel) {
            submitLabel.textContent = `Download ${fmt}`;
        }
    }

    function showFieldError(message) {
        if (!urlError) return;
        urlError.textContent = message;
        urlError.hidden = false;
        urlInput.setAttribute('aria-invalid', 'true');
        urlInput.focus();
    }

    function clearFieldError() {
        if (!urlError) return;
        urlError.hidden = true;
        urlError.textContent = '';
        urlInput.removeAttribute('aria-invalid');
    }

    function showError(title, message) {
        if (!errorBox) return;
        errorTitle.textContent = title;
        errorMessage.textContent = message;
        errorBox.hidden = false;
        setMeter('error');
        setStatus("Couldn't finish download.", 'error');
        errorBox.focus({ preventScroll: true });
        window.requestAnimationFrame(() => {
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    }

    function hideError() {
        if (!errorBox) return;
        errorBox.hidden = true;
        errorTitle.textContent = '';
        errorMessage.textContent = '';
    }

    function hideResult() {
        if (!resultBox) return;
        resultBox.hidden = true;
        currentFile = null;
        resultLink.removeAttribute('aria-disabled');
        resultLink.setAttribute('href', '#');
        resultLinkLabel.textContent = 'Save File';
    }

    function showResult(file) {
        const downloadUrl = String(file.download_url || '');
        if (downloadUrl.indexOf('api/download.php?token=') !== 0) {
            showError('Unexpected reply from server', 'The download link was not in the expected format.');
            return;
        }
        currentFile = file;
        const minutes = Math.max(1, Math.round((Number(file.expires_in) || 900) / 60));

        resultName.textContent = file.name;
        resultMeta.textContent = `${file.format} • ${file.size_label}${file.converted ? ' • Converted' : ''}`;
        resultLink.setAttribute('href', downloadUrl);
        resultNote.textContent = file.single_use
            ? `The file is deleted from the server once you save it, or automatically after ${minutes} minutes.`
            : `The file is deleted from the server after ${minutes} minutes.`;

        resultBox.hidden = false;
        setMeter('done');
        setStatus('Your file is ready to save!', 'ok');
        resultBox.focus({ preventScroll: true });
        window.requestAnimationFrame(() => {
            resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    }

    function setBusy(isBusy) {
        busy = isBusy;
        form.setAttribute('aria-busy', String(isBusy));
        submitBtn.disabled = isBusy;
        submitBtn.classList.toggle('is-busy', isBusy);
        cancelBtn.hidden = !isBusy;
        form.querySelectorAll('input, select, #paste-btn').forEach((el) => { el.disabled = isBusy; });

        if (isBusy) {
            submitLabel.textContent = 'Processing Media...';
            setMeter('working');
            setStatus('Downloading and merging streams. High-resolution files may take a minute...');
        } else {
            updateSubmitLabel();
        }
    }

    /* ------------------------------------------------------------------
       URL Validation & Parsing
       ------------------------------------------------------------------ */
    function isYouTubeUrl(val) {
        return /(?:youtu\.be\/|youtube\.com\/(?:watch|shorts|embed|v|live))/i.test(String(val || '').trim());
    }

    function checkUrl(raw) {
        const value = raw.trim();
        const fail = (message) => ({ ok: false, message });

        if (!value) {
            return fail('Enter a media URL to continue.');
        }
        if (value.length > MAX_URL_LENGTH) {
            return fail(`That link is too long. Links can be up to ${MAX_URL_LENGTH} characters.`);
        }
        if (/\s/.test(value)) {
            return fail('Remove any spaces from the link.');
        }

        let url;
        try {
            url = new URL(value);
        } catch {
            return /^[\w-]+(\.[\w-]+)+(\/|$)/.test(value)
                ? fail('Add https:// to the start of the link.')
                : fail("That doesn't look like a valid web address. It should start with https://");
        }
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return fail('Only http:// and https:// links are supported.');
        }
        if (url.username || url.password) {
            return fail("Links containing usernames or passwords are not supported.");
        }
        if (url.hostname.indexOf('.') === -1 && url.hostname !== 'localhost') {
            return fail('The website name in the link looks incomplete.');
        }
        return { ok: true, value };
    }

    /* ------------------------------------------------------------------
       Quality Dropdown Management
       ------------------------------------------------------------------ */
    const ALL_RESOLUTIONS = [
        { value: 'best',   height: 9999, label: '🏆 Best Available (Auto Detect)' },
        { value: '2160p',  height: 2160, label: '4K Ultra HD (2160p)' },
        { value: '1440p',  height: 1440, label: '2K Quad HD (1440p)' },
        { value: '1080p',  height: 1080, label: '1080p Full HD' },
        { value: '720p',   height: 720,  label: '720p HD' },
        { value: '480p',   height: 480,  label: '480p SD' },
        { value: '360p',   height: 360,  label: '360p Low' },
    ];

    function updateQualityHint() {
        if (!qualityHint) return;
        const val = qualitySelect.value;
        const maxHeight = detectedInfoData?.max_height || 9999;
        const heightLabel = maxHeight >= 2160 ? '4K Ultra HD' :
                            maxHeight >= 1440 ? '2K Quad HD' :
                            maxHeight >= 1080 ? '1080p Full HD' :
                            maxHeight >= 720  ? '720p HD' : (maxHeight < 9999 ? `${maxHeight}p` : '');

        if (val === 'best') {
            if (maxHeight >= 2160) {
                qualityHint.textContent = 'Source supports full 4K Ultra HD. All resolutions are available for download.';
            } else if (maxHeight < 9999) {
                qualityHint.textContent = `Source supports up to ${heightLabel}. Resolutions higher than this are disabled.`;
            } else {
                qualityHint.textContent = 'Select your preferred resolution or choose Best Available.';
            }
        } else if (val === '2160p') {
            qualityHint.textContent = '4K Ultra HD (2160p) selected. Video will be merged with pristine high-fidelity audio.';
        } else if (val === '1440p') {
            qualityHint.textContent = '2K Quad HD (1440p) selected. High visual clarity with crisp merged audio.';
        } else if (val === '1080p') {
            qualityHint.textContent = '1080p Full HD selected. Balanced download speed and great quality.';
        } else if (val === '720p') {
            qualityHint.textContent = '720p HD selected. Faster download and smaller file size.';
        } else if (val === '480p') {
            qualityHint.textContent = '480p Standard Definition selected. Fast download for lower bandwidth.';
        } else if (val === '360p') {
            qualityHint.textContent = '360p Low Resolution selected. Minimum file size.';
        } else {
            const selectedOpt = qualitySelect.options[qualitySelect.selectedIndex];
            if (selectedOpt && selectedOpt.textContent) {
                qualityHint.textContent = `Selected: ${selectedOpt.textContent}`;
            }
        }
    }

    function populateQualities(maxHeight = 9999, detectedTitleText = '') {
        const currentSelected = qualitySelect.value;
        qualitySelect.replaceChildren();

        ALL_RESOLUTIONS.forEach((res) => {
            const opt = document.createElement('option');
            opt.value = res.value;

            const isSupported = res.value === 'best' || res.height <= maxHeight;
            if (isSupported) {
                opt.textContent = res.label;
            } else {
                opt.textContent = `${res.label} — (Unavailable on source)`;
                opt.disabled = true;
            }
            qualitySelect.appendChild(opt);
        });

        // Ensure a valid enabled option is selected
        if (currentSelected && qualitySelect.querySelector(`option[value="${currentSelected}"]:not(:disabled)`)) {
            qualitySelect.value = currentSelected;
        } else {
            qualitySelect.value = 'best';
        }

        // Update detection badge & hint
        if (maxHeight < 9999) {
            const heightLabel = maxHeight >= 2160 ? '4K Ultra HD' :
                                maxHeight >= 1440 ? '2K Quad HD' :
                                maxHeight >= 1080 ? '1080p Full HD' :
                                maxHeight >= 720  ? '720p HD' : `${maxHeight}p`;
            if (qualityDetectStatus) {
                qualityDetectStatus.textContent = `Max: ${heightLabel}`;
            }
        } else {
            if (qualityDetectStatus) qualityDetectStatus.textContent = 'Auto-detect';
        }

        updateQualityHint();
    }

    function updateQualityVisibility() {
        const fmt = selectedFormat();
        const url = urlInput.value.trim();

        // As specified in the requirements: "Hide this section when MP3 Audio is selected"
        if (fmt === 'mp3') {
            qualityField.hidden = true;
            return;
        }

        // When MP4 is selected:
        if (isYouTubeUrl(url)) {
            qualityField.hidden = false;
            if (qualitySelect.options.length === 0) {
                populateQualities(detectedInfoData?.max_height || 9999);
            }
        } else if (url && checkUrl(url).ok) {
            // Direct media link
            qualityField.hidden = false;
            qualitySelect.replaceChildren();
            const opt = document.createElement('option');
            opt.value = 'best';
            opt.textContent = 'Original Source Resolution';
            qualitySelect.appendChild(opt);
            if (qualityDetectStatus) qualityDetectStatus.textContent = 'Source native';
            if (qualityHint) qualityHint.textContent = 'Direct media files are downloaded at their native uploaded resolution.';
        } else {
            qualityField.hidden = true;
        }
    }

    /* ------------------------------------------------------------------
       Async Background Link Inspection (api/info.php)
       ------------------------------------------------------------------ */
    async function inspectUrl(url) {
        if (!isYouTubeUrl(url)) {
            if (detectedInfo) detectedInfo.hidden = true;
            updateQualityVisibility();
            return;
        }

        // Show inspecting state
        if (detectedInfo) {
            detectedInfo.hidden = false;
            detectedTitle.textContent = 'Checking YouTube video...';
            detectedResolution.textContent = 'Scanning available resolutions...';
            detectedBadge.textContent = 'Scanning';
        }

        if (infoAbortController) {
            infoAbortController.abort();
        }
        infoAbortController = new AbortController();

        try {
            const resp = await fetch('api/info.php', {
                method: 'POST',
                credentials: 'same-origin',
                signal: infoAbortController.signal,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ url }),
            });

            if (!resp.ok) {
                if (detectedInfo) detectedInfo.hidden = true;
                populateQualities(9999);
                updateQualityVisibility();
                return;
            }
            const data = await resp.json();

            if (data && data.ok && data.is_youtube) {
                detectedInfoData = data;
                const maxHeight = Number(data.max_height) || 1080;
                const heightTag = maxHeight >= 2160 ? '4K' :
                                  maxHeight >= 1440 ? '2K' :
                                  maxHeight >= 1080 ? '1080p' : `${maxHeight}p`;

                if (detectedInfo) {
                    detectedInfo.hidden = false;
                    detectedTitle.textContent = data.title || 'YouTube Video';
                    detectedResolution.textContent = `Original video supports up to ${heightTag} resolution`;
                    detectedBadge.textContent = heightTag;
                }

                populateQualities(maxHeight, data.title);
                updateQualityVisibility();
            } else {
                if (detectedInfo) detectedInfo.hidden = true;
                populateQualities(9999);
                updateQualityVisibility();
            }
        } catch (e) {
            if (e?.name !== 'AbortError') {
                if (detectedInfo) detectedInfo.hidden = true;
                // If inspection failed, still allow default qualities
                populateQualities(9999);
                updateQualityVisibility();
            }
        }
    }

    function onUrlChanged() {
        clearFieldError();
        const url = urlInput.value.trim();

        if (infoDebounceTimer) {
            clearTimeout(infoDebounceTimer);
        }

        if (!url) {
            if (detectedInfo) detectedInfo.hidden = true;
            detectedInfoData = null;
            updateQualityVisibility();
            return;
        }

        infoDebounceTimer = setTimeout(() => {
            inspectUrl(url);
        }, 350);
    }

    /* ------------------------------------------------------------------
       Server Processing (api/process.php)
       ------------------------------------------------------------------ */
    async function sendRequest(payload) {
        const controller = new AbortController();
        let timedOut = false;
        let timer = null;
        if (requestTimeoutMs > 0) {
            timer = window.setTimeout(() => {
                timedOut = true;
                controller.abort();
            }, requestTimeoutMs);
        }
        activeRequest = controller;

        try {
            const response = await fetch('api/process.php', {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify(payload),
            });

            const raw = await response.text();
            let data = null;
            try { data = JSON.parse(raw); } catch { data = null; }
            return { response, data };
        } catch (error) {
            console.error('sendRequest error:', error);
            if (error && error.name === 'AbortError') {
                return { aborted: true, timedOut };
            }
            return { networkError: true, error };
        } finally {
            if (timer) window.clearTimeout(timer);
            activeRequest = null;
        }
    }

    async function handleSubmit(event) {
        event.preventDefault();
        if (busy) return;

        clearFieldError();
        hideError();
        hideResult();

        const check = checkUrl(urlInput.value);
        if (!check.ok) {
            setMeter('idle');
            setStatus(IDLE_STATUS);
            showFieldError(check.message);
            return;
        }

        const payload = {
            url: check.value,
            format: selectedFormat(),
            quality: qualityField.hidden ? '' : qualitySelect.value,
        };

        setBusy(true);
        const outcome = await sendRequest(payload);
        setBusy(false);

        if (outcome.aborted) {
            if (outcome.timedOut) {
                showError('Download timed out', 'The server could not finish downloading the file in time. Please try a shorter video or lower resolution.');
            } else {
                setMeter('idle');
                setStatus('Download cancelled.');
                urlInput.focus();
            }
            return;
        }

        if (outcome.networkError) {
            const detail = outcome.error?.message ? ` (${outcome.error.message})` : '';
            showError("Couldn't reach server", 'Check that Apache is running in the Wamp/XAMPP control panel, then try again.' + detail);
            return;
        }

        const { response, data } = outcome;
        if (!data || typeof data !== 'object') {
            showError('Server communication error', 'The server returned an unexpected response. Please check the PHP error log.');
            return;
        }

        if (data.ok === false || !response.ok) {
            const problem = data.error || {};
            showError(problem.title || 'Download failed', problem.message || 'Could not complete media download.');
            return;
        }

        if (data.needs_quality) {
            qualityField.hidden = false;
            qualitySelect.replaceChildren();
            (data.qualities || []).forEach((q) => {
                const opt = document.createElement('option');
                opt.value = q.value;
                opt.textContent = q.label;
                qualitySelect.appendChild(opt);
            });
            setStatus(data.message || 'Choose a quality option and click Download again.');
            qualitySelect.focus();
            return;
        }

        if (data.ok && data.file) {
            showResult(data.file);
            return;
        }

        showError('Unexpected server response', 'The server completed the request but did not return a download file.');
    }

    /* ------------------------------------------------------------------
       Event Listeners & Initialization
       ------------------------------------------------------------------ */
    form.addEventListener('submit', handleSubmit);

    form.querySelectorAll('input[name="format"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            updateSubmitLabel();
            updateQualityVisibility();
        });
    });

    urlInput.addEventListener('input', onUrlChanged);
    qualitySelect.addEventListener('change', updateQualityHint);

    pasteBtn.addEventListener('click', async () => {
        if (!navigator.clipboard || !navigator.clipboard.readText) {
            showFieldError('Browser blocked paste access. Press Ctrl+V in the field instead.');
            return;
        }
        try {
            const text = (await navigator.clipboard.readText()).trim();
            if (!text) {
                showFieldError('Clipboard is empty. Copy a link first.');
                return;
            }
            urlInput.value = text;
            clearFieldError();
            onUrlChanged();
            urlInput.focus();

            // Tactile feedback
            const pasteLabel = pasteBtn.querySelector('.paste-label');
            if (pasteLabel) {
                pasteLabel.textContent = 'Pasted!';
                pasteBtn.classList.add('is-pasted');
                setTimeout(() => {
                    pasteLabel.textContent = 'Paste';
                    pasteBtn.classList.remove('is-pasted');
                }, 1200);
            }
        } catch {
            showFieldError('Clipboard permission denied. Press Ctrl+V in the field instead.');
        }
    });

    cancelBtn.addEventListener('click', () => {
        if (activeRequest) {
            activeRequest.abort();
        }
    });

    resetBtn.addEventListener('click', () => {
        hideResult();
        hideError();
        clearFieldError();
        if (detectedInfo) detectedInfo.hidden = true;
        detectedInfoData = null;
        urlInput.value = '';
        qualityField.hidden = true;
        qualitySelect.replaceChildren();
        setMeter('idle');
        setStatus(IDLE_STATUS);
        urlInput.focus({ preventScroll: true });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    resultLink.addEventListener('click', () => {
        if (resultLink.getAttribute('aria-disabled') === 'true') {
            return;
        }
        if (currentFile && currentFile.single_use) {
            window.setTimeout(() => {
                resultLink.setAttribute('aria-disabled', 'true');
                resultLinkLabel.textContent = 'Saved';
                resultNote.textContent = 'This single-use link has been downloaded. Click "Download Another" for a new file.';
            }, 1200);
        }
    });

    /* ------------------------------------------------------------------
       Help & Supported Formats Drawer Controls
       ------------------------------------------------------------------ */
    const helpDrawerOpen = $('help-drawer-open');
    const helpDrawerClose = $('help-drawer-close');
    const helpDrawerBackdrop = $('help-drawer-backdrop');
    const helpDrawer = $('help-drawer');

    let previousActiveElement = null;

    function openHelpDrawer() {
        if (!helpDrawer || !helpDrawerBackdrop) return;
        previousActiveElement = document.activeElement;
        helpDrawer.classList.add('is-open');
        helpDrawerBackdrop.classList.add('is-open');
        helpDrawer.setAttribute('aria-hidden', 'false');
        if (helpDrawerOpen) helpDrawerOpen.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        if (helpDrawerClose) helpDrawerClose.focus();
    }

    function closeHelpDrawer() {
        if (!helpDrawer || !helpDrawerBackdrop) return;
        helpDrawer.classList.remove('is-open');
        helpDrawerBackdrop.classList.remove('is-open');
        helpDrawer.setAttribute('aria-hidden', 'true');
        if (helpDrawerOpen) helpDrawerOpen.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        if (previousActiveElement && typeof previousActiveElement.focus === 'function') {
            previousActiveElement.focus();
        }
    }

    if (helpDrawerOpen) helpDrawerOpen.addEventListener('click', openHelpDrawer);
    if (helpDrawerClose) helpDrawerClose.addEventListener('click', closeHelpDrawer);
    if (helpDrawerBackdrop) helpDrawerBackdrop.addEventListener('click', closeHelpDrawer);

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && helpDrawer && helpDrawer.classList.contains('is-open')) {
            closeHelpDrawer();
        }
    });

    // Start
    buildMeter();
    updateSubmitLabel();
    updateQualityVisibility();
})();
