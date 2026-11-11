<?php
/**
 * Media Downloader - media providers
 *
 * A provider answers one question: "for this link, which direct media file
 * should the server fetch?". Adding a new authorized source later means adding
 * one class here and listing it in media_providers().
 */
defined('MEDIA_DL') || exit;

interface MediaProvider
{
    /** Can this provider handle the (already validated) URL? */
    public function supports(array $url);

    /**
     * Return the direct file to download.
     * Throws MediaException, or QualityRequiredException when a choice is needed.
     *
     * @return array{url:string, title:string, extension:string}
     */
    public function resolve(array $url, $format, $quality);
}

// ----------------------------------------------------------------------
// Provider 1: direct links to media files on approved hosts (works out of the box)
// ----------------------------------------------------------------------

class DirectMediaProvider implements MediaProvider
{
    public function supports(array $u)
    {
        $extensions = array_merge((array) config('video_extensions', []), (array) config('audio_extensions', []));
        return host_matches($u['host'], (array) config('allowed_hosts', []))
            && in_array($u['extension'], $extensions, true);
    }

    public function resolve(array $u, $format, $quality)
    {
        if ($format === 'mp4' && in_array($u['extension'], (array) config('audio_extensions', []), true)) {
            throw new MediaException(
                'no_video_track',
                'No video in this file',
                "That link is an audio file, so it can't be saved as MP4. Choose MP3 instead.",
                422
            );
        }
        return ['url' => $u['url'], 'title' => $u['name'], 'extension' => $u['extension']];
    }
}

// ----------------------------------------------------------------------
// Provider 2: an authorized resolver API (disabled until configured)
// ----------------------------------------------------------------------

class ResolverApiProvider implements MediaProvider
{
    protected function settings()
    {
        return (array) config('resolver_api', []);
    }

    public function supports(array $u)
    {
        $s = $this->settings();
        return !empty($s['enabled'])
            && !empty($s['endpoint'])
            && !empty($s['api_key'])
            && host_matches($u['host'], (array) (isset($s['handles_hosts']) ? $s['handles_hosts'] : []));
    }

    public function resolve(array $u, $format, $quality)
    {
        $data = $this->callApi($u['url']);

        $options = [];
        foreach ((array) (isset($data['formats']) ? $data['formats'] : []) as $f) {
            if (!is_array($f) || empty($f['url']) || !is_string($f['url'])) {
                continue;
            }
            $type = isset($f['type']) ? strtolower((string) $f['type']) : '';
            if ($type !== 'video' && $type !== 'audio') {
                continue;
            }
            $options[] = [
                'type'      => $type,
                'quality'   => isset($f['quality']) ? trim((string) $f['quality']) : '',
                'url'       => $f['url'],
                'container' => isset($f['container']) ? strtolower((string) $f['container']) : '',
            ];
        }

        $wanted   = $format === 'mp4' ? 'video' : 'audio';
        $matching = array_values(array_filter($options, function ($o) use ($wanted) { return $o['type'] === $wanted; }));
        if (!$matching && $format === 'mp3') {
            // No audio-only file offered: take a video and let FFmpeg extract the sound.
            $matching = array_values(array_filter($options, function ($o) { return $o['type'] === 'video'; }));
        }
        if (!$matching) {
            throw new MediaException(
                'format_unavailable',
                'That format is not available',
                'This source does not offer ' . ($format === 'mp4' ? 'MP4 video' : 'MP3 audio') . ' for this link.',
                422
            );
        }

        // Quality choices are only shown when the source really offers several.
        $labels = [];
        foreach ($matching as $o) {
            if ($o['quality'] !== '' && !in_array($o['quality'], $labels, true)) {
                $labels[] = $o['quality'];
            }
        }
        if (count($labels) > 1 && $quality === '') {
            throw new QualityRequiredException(array_map(function ($label) {
                return ['value' => $label, 'label' => $label];
            }, $labels));
        }

        $chosen = $matching[0];
        if ($quality !== '') {
            $chosen = null;
            foreach ($matching as $o) {
                if ($o['quality'] === $quality) {
                    $chosen = $o;
                    break;
                }
            }
            if ($chosen === null) {
                throw new MediaException('quality_unavailable', 'That quality is not available', 'Pick one of the qualities offered for this link.', 422);
            }
        }

        $title = isset($data['title']) && is_string($data['title']) ? $data['title'] : $u['name'];
        return ['url' => $chosen['url'], 'title' => $title, 'extension' => $chosen['container']];
    }

    /** POST {"url": "..."} to the configured endpoint and return the decoded JSON. */
    protected function callApi($pageUrl)
    {
        $s        = $this->settings();
        $endpoint = (string) $s['endpoint'];
        if (stripos($endpoint, 'https://') !== 0) {
            throw new MediaException('resolver_config', 'Resolver API is not set up correctly', 'The resolver endpoint in includes/config.php must start with https://.', 500);
        }
        if (!function_exists('curl_init')) {
            throw new MediaException('curl_missing', 'PHP cURL is not enabled', 'Enable "extension=curl" in php.ini and restart Apache (see README).', 500);
        }

        $clean   = function ($text) { return preg_replace('/[\r\n]+/', '', (string) $text); };
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            $clean(isset($s['key_header']) ? $s['key_header'] : 'Authorization') . ': '
                . $clean(isset($s['key_prefix']) ? $s['key_prefix'] : '') . $clean($s['api_key']),
        ];

        $body = '';
        $ch   = curl_init($endpoint);
        $options = [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode(['url' => $pageUrl]),
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => max(5, (int) (isset($s['timeout']) ? $s['timeout'] : 20)),
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_PROXY           => '',
            CURLOPT_USERAGENT       => (string) config('user_agent', 'MediaDownloader/1.0'),
            CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > 1024 * 1024 ? 0 : strlen($chunk); // ignore replies over 1 MB
            },
        ];
        $caBundle = (string) config('ca_bundle', '');
        if ($caBundle !== '' && is_file($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($status === 401 || $status === 403) {
            throw new MediaException('resolver_auth', 'The media service refused the request', 'Its API key may be missing or wrong. Check "resolver_api" in includes/config.php.', 502);
        }
        if ($status === 404) {
            throw new MediaException('resolver_not_found', 'Nothing found for that link', 'The media service has no downloadable media for this link.', 404);
        }
        if ($errno !== 0 || $status !== 200) {
            error_log('[media-downloader] Resolver API failed. HTTP ' . $status . ', cURL ' . $errno . ' ' . $error);
            throw new MediaException('resolver_error', "The media service didn't respond", 'The service that finds the media could not be reached. Try again later.', 502);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new MediaException('resolver_error', 'Unexpected reply from the media service', 'The service answered in a format this tool does not understand.', 502);
        }
        return $data;
    }
}

// ----------------------------------------------------------------------
// Provider 3: YouTube video & audio downloader (via yt-dlp & FFmpeg)
// ----------------------------------------------------------------------

class YouTubeMediaProvider implements MediaProvider
{
    public function supports(array $u)
    {
        $patterns = [
            'youtube.com',
            '*.youtube.com',
            'youtu.be',
            '*.youtu.be',
            'youtube-nocookie.com',
            '*.youtube-nocookie.com',
        ];
        return host_matches($u['host'], $patterns);
    }

    public function resolve(array $u, $format, $quality)
    {
        $ytdlp = binary_path(config('ytdlp_path', 'yt-dlp'));
        if (!$ytdlp) {
            throw new MediaException(
                'ytdlp_missing',
                'Downloader engine missing',
                'yt-dlp was not found on the server. Please check the setup.',
                500
            );
        }

        $nodePath  = binary_path(config('node_path', 'node')) ?: 'C:/Program Files/nodejs/node.exe';
        $ffmpegBin = binary_path(config('ffmpeg_path', 'ffmpeg'));
        $ffmpegDir = $ffmpegBin ? dirname($ffmpegBin) : 'C:/ffmpeg/bin';

        return [
            'url'               => $u['url'],
            'title'             => $u['name'] ?: 'YouTube Video',
            'extension'         => $format === 'mp3' ? 'mp3' : 'mp4',
            'custom_downloader' => function ($requestedFormat, $chosenQuality, $token, $deadline) use ($ytdlp, $u, $nodePath, $ffmpegDir) {
                return $this->downloadMedia($ytdlp, $u['url'], $requestedFormat, $chosenQuality, $token, $nodePath, $ffmpegDir, $deadline);
            },
        ];
    }

    public function downloadMedia($ytdlp, $url, $format, $quality, $token, $nodePath, $ffmpegDir, $deadline)
    {
        $dir = temp_dir();
        $targetExt = $format === 'mp3' ? 'mp3' : 'mp4';
        $outputPath = $dir . DIRECTORY_SEPARATOR . $token . '.' . $targetExt;
        $outputTemplate = $dir . DIRECTORY_SEPARATOR . $token . '.%(ext)s';

        $args = [
            $ytdlp,
            '--no-warnings',
            '--no-playlist',
            '--remote-components', 'ejs:github',
            '--concurrent-fragments', '4',
            '--retries', '10',
            '--fragment-retries', '10',
            '--no-simulate',
            '--print', 'TITLE:%(title)s',
        ];

        if ($nodePath && is_file($nodePath)) {
            $args[] = '--js-runtimes';
            $args[] = 'node:' . str_replace('\\', '/', $nodePath);
        }
        if ($ffmpegDir && is_dir($ffmpegDir)) {
            $args[] = '--ffmpeg-location';
            $args[] = str_replace('\\', '/', $ffmpegDir);
        }

        if ($format === 'mp3') {
            // Allow the user-chosen bitrate (e.g. "192k") to override the server default.
            $validBitrates = ['64k', '96k', '128k', '192k', '256k', '320k'];
            if (in_array($quality, $validBitrates, true)) {
                $bitrate = $quality;
            } else {
                $bitrate = (string) config('mp3_bitrate', '192k');
                if (!preg_match('/^\d{2,3}k$/', $bitrate)) {
                    $bitrate = '192k';
                }
            }
            array_push($args,
                '-x',
                '--audio-format', 'mp3',
                '--audio-quality', $bitrate,
                '-o', $outputTemplate,
                $url
            );
        } else {
            // MP4 format — build a resolution-aware format selector.
            // YouTube's 2K/4K content is typically VP9 or AV1 (not H.264), so we
            // use codec-agnostic selectors and let FFmpeg merge/remux into MP4.
            if ($quality === '' || $quality === 'best') {
                // Grab the absolute best available video + best audio, any codec.
                $formatSelector = 'bestvideo+bestaudio/best';
            } else {
                $cleanQ = preg_replace('/[^0-9]/', '', $quality);
                $h = ($cleanQ !== '' && (int)$cleanQ > 0) ? (int)$cleanQ : 1080;

                if ($h >= 1440) {
                    // 2K / 4K: YouTube rarely has H.264 at these heights; accept any codec.
                    // Prefer VP9 or AV1 over H.264 because that's what YouTube actually has.
                    $formatSelector =
                        "bestvideo[height<={$h}]+bestaudio[ext=m4a]" .
                        "/bestvideo[height<={$h}]+bestaudio" .
                        "/best[height<={$h}]" .
                        "/bestvideo+bestaudio" .
                        "/best";
                } else {
                    // ≤1080p: H.264 MP4 is available, prefer it for maximum compatibility.
                    $formatSelector =
                        "bestvideo[height<={$h}][ext=mp4]+bestaudio[ext=m4a]" .
                        "/bestvideo[height<={$h}]+bestaudio[ext=m4a]" .
                        "/bestvideo[height<={$h}]+bestaudio" .
                        "/best[height<={$h}]" .
                        "/best";
                }
            }
            array_push($args,
                '-f', $formatSelector,
                '--merge-output-format', 'mp4',
                '-o', $outputTemplate,
                $url
            );
        }

        $timeout = seconds_left($deadline, config('download_timeout', 0));
        $run = run_command($args, $timeout);

        if ($run['timed_out']) {
            @unlink($outputPath);
            throw new MediaException('download_timeout', 'Download took too long', 'The YouTube download exceeded the time limit on this server.', 504);
        }

        // Parse title from stdout
        $title = '';
        if (!empty($run['out'])) {
            foreach (explode("\n", $run['out']) as $line) {
                $line = trim($line);
                if (strpos($line, 'TITLE:') === 0) {
                    $title = trim(substr($line, 6));
                    break;
                }
            }
        }

        // Locate downloaded file (in case extension differed before final remux)
        if (!is_file($outputPath) || filesize($outputPath) === 0) {
            $candidates = glob($dir . DIRECTORY_SEPARATOR . $token . '.*');
            if (is_array($candidates)) {
                foreach ($candidates as $cand) {
                    if (preg_match('/\.(mp4|mp3|mkv|webm|m4a)$/i', $cand) && filesize($cand) > 0) {
                        if ($cand !== $outputPath) {
                            @rename($cand, $outputPath);
                        }
                        break;
                    }
                }
            }
        }

        if (!is_file($outputPath) || filesize($outputPath) === 0) {
            $errDetail = trim($run['err']) ?: trim($run['out']);
            error_log('[media-downloader] yt-dlp error: ' . substr($errDetail, 0, 800));
            throw new MediaException(
                'ytdlp_failed',
                "Couldn't download this YouTube video",
                'The download failed (' . substr($errDetail, 0, 200) . '). The video may be private, age-restricted, or unavailable.',
                422
            );
        }

        $maxBytes = (int) config('max_output_bytes', 0);
        if ($maxBytes > 0 && filesize($outputPath) > $maxBytes) {
            @unlink($outputPath);
            throw new MediaException('file_too_large', 'File is too large', 'The downloaded file exceeds the server limit of ' . human_size($maxBytes) . '.', 413);
        }

        return [
            'path'  => $outputPath,
            'title' => $title ?: 'YouTube Media',
        ];
    }
}

// ----------------------------------------------------------------------
// Choosing a provider
// ----------------------------------------------------------------------

/** Order matters: the first provider that supports the link wins. */
function media_providers()
{
    return [new YouTubeMediaProvider(), new ResolverApiProvider(), new DirectMediaProvider()];
}

/** @return array{url:string, title:string, extension:string} */
function pick_source(array $u, $format, $quality)
{
    foreach (media_providers() as $provider) {
        if ($provider->supports($u)) {
            return $provider->resolve($u, $format, $quality);
        }
    }
    throw explain_unsupported_link($u);
}

/** Build the most helpful message for a link no provider can handle. */
function explain_unsupported_link(array $u)
{
    if (in_array($u['extension'], ['m3u8', 'mpd', 'ism', 'f4m'], true)) {
        return new MediaException(
            'streaming_not_supported',
            'Streaming playlists are not supported',
            'Links ending in .' . $u['extension'] . ' point to a streaming playlist, not a single file. This tool only downloads direct media files.',
            422
        );
    }
    try {
        assert_url_allowed($u);
    } catch (MediaException $e) {
        return $e;
    }
    return new MediaException(
        'not_direct_media',
        "That isn't a direct media link",
        'The link must point straight to a media file, such as one ending in .mp4, .webm or .mp3.',
        422
    );
}
