<?php
define('MEDIA_DL', true);
require __DIR__ . '/includes/bootstrap.php';

send_security_headers(true);
header('Cache-Control: no-store');

$csrf        = csrf_token();
$ffmpegReady = ffmpeg_ready();
$appName     = (string) config('app_name', 'Media Downloader');
$hosts       = array_values(array_filter((array) config('allowed_hosts', []), 'is_string'));
$videoTypes  = array_map('strtoupper', (array) config('video_extensions', []));
$audioTypes  = array_map('strtoupper', (array) config('audio_extensions', []));
$timeLimit   = (int) config('request_time_limit', 0);
$timeoutMs   = $timeLimit > 0 ? ($timeLimit + 30) * 1000 : 0;

function e($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function asset($path)
{
    $file = __DIR__ . '/' . $path;
    return e($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?> - Download 4K Video & MP3 Audio</title>
    <meta name="description" content="Download YouTube videos in 4K, 2K, 1080p or extract crystal-clear 320kbps MP3 audio with instant quality detection and FFmpeg merging.">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#090c15">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <link rel="icon" href="assets/images/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="assets/fonts/bricolage-grotesque-latin-wdth-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/instrument-sans-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
    <a class="skip-link" href="#media-url">Skip to the downloader</a>

    <!-- Background Atmospheric Glows -->
    <div class="ambient-glow ambient-glow-1" aria-hidden="true"></div>
    <div class="ambient-glow ambient-glow-2" aria-hidden="true"></div>

    <!-- Header -->
    <header class="site-header">
        <a class="brand" href="./" aria-label="Media Downloader Home">
            <span class="brand-icon-wrapper">
                <img src="assets/images/logo.svg" alt="" width="36" height="36">
            </span>
            <span class="brand-name"><?= e($appName) ?></span>
        </a>

        <div class="header-right">
            <button type="button" id="help-drawer-open" class="btn-help-header" aria-haspopup="dialog" aria-expanded="false" aria-controls="help-drawer" title="Open Help & Supported Formats Guide">
                <svg class="help-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>Help & Supported Formats</span>
            </button>

            <div class="engine <?= $ffmpegReady ? 'is-ready' : 'is-missing' ?>" id="engine-status" title="<?= $ffmpegReady ? 'FFmpeg is ready for video merging and MP3 conversion' : 'FFmpeg not detected' ?>">
                <span class="engine-dot" aria-hidden="true"></span>
                <span class="engine-text">
                    <?php if ($ffmpegReady): ?>
                        FFmpeg ready
                    <?php else: ?>
                        FFmpeg missing<span class="engine-detail">: conversion off</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </header>

    <main class="layout" id="main">

        <!-- Left Intro / Hero Section -->
        <section class="hero" aria-labelledby="hero-title">
            <div class="hero-badge">
                <span class="hero-badge-dot"></span>
                <span>All-in-One Video & Audio Downloader by Louise Victor</span>
            </div>

            <h1 id="hero-title">
                Download Your <span class="gradient-text">Media</span>
            </h1>

            <p class="hero-sub">
                Save YouTube videos and direct media files in up to <strong>4K Ultra HD</strong>, or extract crystal-clear <strong>320kbps MP3</strong> audio tracks in seconds.
            </p>

            <!-- Theme Visual Element: Media Feature Showcase -->
            <div class="visual-showcase" aria-hidden="true">
                <div class="showcase-header">
                    <div class="showcase-decorations">
                        <span class="dot dot-red"></span>
                        <span class="dot dot-yellow"></span>
                        <span class="dot dot-green"></span>
                    </div>
                    <span class="showcase-tag">Ultra HD Engine Active</span>
                </div>
                <div class="showcase-body">
                    <div class="showcase-pill">
                        <span class="pill-icon">🎬</span>
                        <div class="pill-meta">
                            <strong>4K & 2K Ultra HD</strong>
                            <small>VP9 / AV1 auto-merged</small>
                        </div>
                    </div>
                    <div class="showcase-pill">
                        <span class="pill-icon">🎵</span>
                        <div class="pill-meta">
                            <strong>MP3 Studio Audio</strong>
                            <small>Up to 320 kbps fidelity</small>
                        </div>
                    </div>
                    <div class="showcase-pill">
                        <span class="pill-icon">⚡</span>
                        <div class="pill-meta">
                            <strong>Resolution Detect</strong>
                            <small>Instant source scan</small>
                        </div>
                    </div>
                    <div class="showcase-pill">
                        <span class="pill-icon">🔒</span>
                        <div class="pill-meta">
                            <strong>Private & Single-Use</strong>
                            <small>Zero logs & auto-cleanup</small>
                        </div>
                    </div>
                </div>
            </div>

        </section>

        <!-- Right Downloader Deck Section -->
        <section class="deck" aria-label="Media Downloader Deck">

            <!-- Deck Display: VU Level Meter & Status -->
            <div class="deck-display">
                <div class="meter" id="meter" data-state="idle" aria-hidden="true"></div>
                <div class="deck-status">
                    <div class="status-left">
                        <span class="status-indicator-dot" aria-hidden="true"></span>
                        <p id="status-text" role="status">Paste a link to begin.</p>
                    </div>
                    <button type="button" id="cancel-btn" class="link-btn" hidden>Cancel</button>
                </div>
            </div>

            <!-- Main Form -->
            <form id="download-form" class="deck-form" novalidate data-timeout="<?= (int) $timeoutMs ?>" data-csrf="<?= e($csrf) ?>">

                <!-- URL Field -->
                <div class="field">
                    <div class="field-header">
                        <label id="url-label" for="media-url">Media URL</label>
                        <span class="field-badge">YouTube or Direct Link</span>
                    </div>

                    <div class="url-input-group">
                        <span class="url-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        </span>
                        <input id="media-url" name="url" type="text" inputmode="url" autocomplete="off"
                               autocapitalize="off" spellcheck="false" maxlength="2048"
                               placeholder="Paste YouTube or direct media link (e.g. https://...)"
                               aria-describedby="url-hint url-error" aria-required="true">
                        <button type="button" id="paste-btn" class="btn-paste" aria-label="Paste link from clipboard" title="Paste from clipboard">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <span class="paste-label">Paste</span>
                        </button>
                    </div>

                    <p class="field-error" id="url-error" hidden></p>
                    <p class="hint" id="url-hint">Supports YouTube videos, Shorts, and direct media files (.mp4, .mp3, .webm).</p>

                    <!-- Detected Link Card (Populated dynamically) -->
                    <div class="detected-info" id="detected-info" hidden>
                        <div class="detected-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                        </div>
                        <div class="detected-meta">
                            <strong id="detected-title">Checking video...</strong>
                            <small id="detected-resolution">Scanning available resolutions...</small>
                        </div>
                        <span class="detected-badge" id="detected-badge">Auto</span>
                    </div>
                </div>

                <!-- Format Selection Cards -->
                <fieldset class="formats">
                    <legend>Choose Format</legend>

                    <label class="format-option">
                        <input type="radio" name="format" value="mp4" checked>
                        <span class="format-card">
                            <span class="format-icon-wrap format-icon-video">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="4"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                            </span>
                            <span class="format-text">
                                <strong>MP4 Video</strong>
                                <small>Full HD, 2K & 4K with audio</small>
                            </span>
                            <span class="format-check" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                        </span>
                    </label>

                    <label class="format-option">
                        <input type="radio" name="format" value="mp3">
                        <span class="format-card">
                            <span class="format-icon-wrap format-icon-audio">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                            </span>
                            <span class="format-text">
                                <strong>MP3 Audio</strong>
                                <small>Soundtrack only (up to 320k)</small>
                            </span>
                            <span class="format-check" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                        </span>
                    </label>
                </fieldset>

                <!-- Video Quality Selection (Shown when MP4 is selected) -->
                <div class="field" id="quality-field" hidden>
                    <div class="field-header">
                        <label for="quality">Video Quality</label>
                        <span class="quality-detect-status" id="quality-detect-status">Auto-detected</span>
                    </div>
                    <div class="select-wrapper">
                        <select id="quality" name="quality"></select>
                    </div>
                    <p class="hint quality-hint" id="quality-hint">Select your preferred video resolution. Unavailable options are disabled automatically.</p>
                </div>

                <!-- Primary Submit Download Button -->
                <button type="submit" id="submit-btn" class="btn btn-primary">
                    <span class="btn-spinner" aria-hidden="true"></span>
                    <span class="btn-icon" id="submit-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </span>
                    <span id="submit-label">Download MP4</span>
                </button>
            </form>

            <!-- Error Notice Box -->
            <div id="error-box" class="notice" role="alert" tabindex="-1" hidden>
                <div class="notice-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div class="notice-body">
                    <h2 id="error-title"></h2>
                    <p id="error-message"></p>
                </div>
            </div>

            <!-- Ready / Result Box -->
            <section id="result" class="result" aria-labelledby="result-title" tabindex="-1" hidden>
                <div class="result-head">
                    <span class="result-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </span>
                    <div class="result-headline">
                        <h2 id="result-title">Your file is ready</h2>
                        <p id="result-meta" class="result-meta"></p>
                    </div>
                </div>

                <div class="result-file-card">
                    <span class="file-card-icon" aria-hidden="true">📁</span>
                    <span id="result-name" class="result-name"></span>
                </div>

                <div class="result-actions">
                    <a id="result-link" class="btn btn-save" href="#">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <span id="result-link-label">Save File</span>
                    </a>
                    <button type="button" id="reset-btn" class="btn btn-quiet">Download Another</button>
                </div>
                <p id="result-note" class="hint"></p>
            </section>
        </section>

    </main>

    <!-- Right-Side Help & Supported Formats Popup Drawer -->
    <div id="help-drawer-backdrop" class="drawer-backdrop" aria-hidden="true"></div>
    <aside id="help-drawer" class="help-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title" aria-hidden="true">
        <div class="drawer-header">
            <div class="drawer-header-title">
                <span class="drawer-header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </span>
                <div>
                    <h2 id="drawer-title">Help & Supported Formats</h2>
                    <p class="drawer-subtitle">Specifications, supported sites & quality guide</p>
                </div>
            </div>
            <button type="button" id="help-drawer-close" class="drawer-close-btn" aria-label="Close help drawer" title="Close (Esc)">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <span class="esc-badge" aria-hidden="true">ESC</span>
            </button>
        </div>

        <div class="drawer-body">
            <!-- 1. Supported Links & Sites -->
            <section class="drawer-section">
                <div class="section-title">
                    <span class="section-icon">🌐</span>
                    <h3>Supported Sites & Links</h3>
                </div>
                <p>Paste any public video or audio link. YouTube is natively supported:</p>
                <div class="drawer-pill-group">
                    <span class="tag-chip">youtube.com/watch?v=...</span>
                    <span class="tag-chip">youtu.be/...</span>
                    <span class="tag-chip">youtube.com/shorts/...</span>
                    <span class="tag-chip">youtube.com/live/...</span>
                </div>
                <p class="section-subtext">You can also paste direct links to approved media files:</p>
                <div class="media-types-box">
                    <div><strong>Video:</strong> <?= e(implode(', ', $videoTypes)) ?></div>
                    <div><strong>Audio:</strong> <?= e(implode(', ', $audioTypes)) ?></div>
                </div>
                <?php if ($hosts): ?>
                    <h4 class="chips-title">Approved Domains</h4>
                    <ul class="host-chips">
                        <?php foreach ($hosts as $host): ?>
                            <li><?= e($host) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <!-- 2. Video Formats & 4K/2K Quality -->
            <section class="drawer-section">
                <div class="section-title">
                    <span class="section-icon">🎬</span>
                    <h3>Video Quality & 4K/2K Resolutions</h3>
                </div>
                <p>When you select <strong>MP4 Video</strong>, the system selects or offers resolutions up to the maximum original upload:</p>
                <ul class="drawer-spec-list">
                    <li>
                        <span class="res-tag res-4k">2160p (4K)</span>
                        <div class="res-desc">
                            <strong>Ultra HD (3840&times;2160)</strong>
                            <small>VP9 / AV1 codec merged into MP4 via FFmpeg</small>
                        </div>
                    </li>
                    <li>
                        <span class="res-tag res-2k">1440p (2K)</span>
                        <div class="res-desc">
                            <strong>Quad HD (2560&times;1440)</strong>
                            <small>High bitrate 2K video with clean audio</small>
                        </div>
                    </li>
                    <li>
                        <span class="res-tag res-hd">1080p</span>
                        <div class="res-desc">
                            <strong>Full HD (1920&times;1080)</strong>
                            <small>Crisp video with universal device compatibility</small>
                        </div>
                    </li>
                    <li>
                        <span class="res-tag res-sd">720p / 480p / 360p</span>
                        <div class="res-desc">
                            <strong>Standard &amp; Mobile</strong>
                            <small>Smaller file size for fast saving</small>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- 3. MP3 Audio Quality -->
            <section class="drawer-section">
                <div class="section-title">
                    <span class="section-icon">🎧</span>
                    <h3>MP3 Audio Track Extraction</h3>
                </div>
                <p>Choosing <strong>MP3 Audio</strong> extracts the soundtrack and converts it via FFmpeg with high clarity:</p>
                <ul class="drawer-spec-list">
                    <li>
                        <span class="res-tag res-audio">320 kbps</span>
                        <div class="res-desc">
                            <strong>Studio Quality</strong>
                            <small>Maximum audio fidelity, rich bass and highs</small>
                        </div>
                    </li>
                    <li>
                        <span class="res-tag res-audio">192 kbps</span>
                        <div class="res-desc">
                            <strong>High Quality (Recommended)</strong>
                            <small>Standard for music tracks with great balance</small>
                        </div>
                    </li>
                    <li>
                        <span class="res-tag res-audio">128k / 64k</span>
                        <div class="res-desc">
                            <strong>Standard &amp; Compact</strong>
                            <small>Ideal for podcasts, speech, and lightweight files</small>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- 4. Quality Auto-Detection -->
            <section class="drawer-section">
                <div class="section-title">
                    <span class="section-icon">⚡</span>
                    <h3>Automatic Quality Detection</h3>
                </div>
                <p>As soon as you paste a YouTube link, the server automatically queries yt-dlp in the background to detect the highest available resolution uploaded by the creator. Higher resolutions that do not exist are disabled in the dropdown automatically.</p>
            </section>

            <!-- 5. Troubleshooting & FAQ -->
            <section class="drawer-section">
                <div class="section-title">
                    <span class="section-icon">⚠️</span>
                    <h3>Frequently Asked Questions</h3>
                </div>
                <div class="faq-card">
                    <strong>Why is 4K not selectable for my video?</strong>
                    <p>4K is only available if the creator uploaded the original video in 4K resolution. The system detects the video source and disables resolutions higher than the source upload.</p>
                </div>
                <div class="faq-card">
                    <strong>Why does 4K take longer to process?</strong>
                    <p>4K files are large. YouTube stores video and audio on separate streams, so the server must download both full streams and merge them into an MP4 with FFmpeg.</p>
                </div>
                <div class="faq-card">
                    <strong>Private or age-restricted videos:</strong>
                    <p>Videos that require a YouTube login or age verification cannot be downloaded without session cookies.</p>
                </div>
            </section>

            <div class="drawer-footer-note">
                <p>&copy; <?= date('Y') ?> <?= e($appName) ?>. Only download content you own or have permission to use.</p>
            </div>
        </div>
    </aside>

    <script src="<?= asset('assets/js/script.js') ?>" defer></script>
</body>
</html>
