<?php
/**
 * POST /api/info.php
 * Body (JSON): { "url": "https://..." }
 * Header:      X-CSRF-Token (issued by index.php)
 *
 * Inspects a media URL to detect available video resolutions, title, and metadata.
 * Uses caching so repeated checks for the same video return instantly.
 */
define('MEDIA_DL', true);
require __DIR__ . '/../includes/bootstrap.php';

send_security_headers();

// Handle fatal errors gracefully
register_shutdown_function(function () {
    $last = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if ($last && in_array($last['type'], $fatal, true) && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => ['title' => 'Server error', 'message' => 'Failed to inspect link.']]);
    }
});

function handle_info_request()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new MediaException('method_not_allowed', 'Method not allowed', 'Only POST is accepted.', 405);
    }

    if (!app_session_start()) {
        throw new MediaException('session_failed', 'Session problem', 'Session could not be started.', 500);
    }
    if (!csrf_is_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        throw new MediaException('csrf', 'Session expired', 'Reload the page and try again.', 403);
    }
    session_write_close();

    $input = read_json_body();
    $rawUrl = isset($input['url']) && is_string($input['url']) ? trim($input['url']) : '';
    if ($rawUrl === '') {
        throw new MediaException('empty_url', 'Enter a URL', 'Paste a link to inspect.', 400);
    }

    $u = parse_media_url($rawUrl);

    // 1. YouTube link detection
    $ytPatterns = [
        'youtube.com',
        '*.youtube.com',
        'youtu.be',
        '*.youtu.be',
        'youtube-nocookie.com',
        '*.youtube-nocookie.com',
    ];

    if (host_matches($u['host'], $ytPatterns)) {
        $ytdlp = binary_path(config('ytdlp_path', 'yt-dlp'));
        if (!$ytdlp) {
            return [200, [
                'ok'         => true,
                'is_youtube' => true,
                'title'      => 'YouTube Video',
                'heights'    => [2160, 1440, 1080, 720, 480, 360],
                'max_height' => 2160,
            ]];
        }

        // Cache check (10 min TTL)
        $cacheDir  = temp_dir();
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'info_' . md5($u['url']) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 600)) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['ok'])) {
                return [200, $cached];
            }
        }

        $nodePath = binary_path(config('node_path', 'node')) ?: 'C:/Program Files/nodejs/node.exe';
        $args = [
            $ytdlp,
            '--no-warnings',
            '--no-playlist',
            '--remote-components', 'ejs:github',
            '-J',
            $u['url']
        ];
        if ($nodePath && is_file($nodePath)) {
            $args[] = '--js-runtimes';
            $args[] = 'node:' . str_replace('\\', '/', $nodePath);
        }

        $run = run_command($args, 15);
        if (!empty($run['out'])) {
            $json = json_decode($run['out'], true);
            if (is_array($json)) {
                $heights = [];
                if (!empty($json['formats']) && is_array($json['formats'])) {
                    foreach ($json['formats'] as $f) {
                        if (!empty($f['height']) && is_numeric($f['height'])) {
                            $heights[] = (int) $f['height'];
                        }
                    }
                }
                $heights = array_values(array_unique($heights));
                rsort($heights);

                $maxHeight = !empty($heights) ? $heights[0] : 1080;
                $title     = !empty($json['title']) ? (string) $json['title'] : 'YouTube Video';
                $duration  = !empty($json['duration']) ? (int) $json['duration'] : null;

                $res = [
                    'ok'         => true,
                    'is_youtube' => true,
                    'title'      => $title,
                    'heights'    => $heights,
                    'max_height' => $maxHeight,
                    'duration'   => $duration,
                ];

                @file_put_contents($cacheFile, json_encode($res), LOCK_EX);
                return [200, $res];
            }
        }

        // Fallback if yt-dlp timeout or parse error
        return [200, [
            'ok'         => true,
            'is_youtube' => true,
            'title'      => $u['name'] ?: 'YouTube Video',
            'heights'    => [2160, 1440, 1080, 720, 480, 360],
            'max_height' => 2160,
        ]];
    }

    // 2. Direct media file link
    $extensions = array_merge((array) config('video_extensions', []), (array) config('audio_extensions', []));
    if (in_array($u['extension'], $extensions, true)) {
        assert_url_allowed($u);
        $isVideo = in_array($u['extension'], (array) config('video_extensions', []), true);
        return [200, [
            'ok'         => true,
            'is_direct'  => true,
            'is_video'   => $isVideo,
            'extension'  => $u['extension'],
            'title'      => $u['name'] ?: 'Direct Media File',
            'heights'    => ['source'],
            'max_height' => 'source',
        ]];
    }

    // 3. Other link
    return [200, [
        'ok'        => true,
        'is_other'  => true,
        'title'     => $u['name'] ?: 'Media Link',
    ]];
}

try {
    list($status, $payload) = handle_info_request();
} catch (MediaException $e) {
    $status  = $e->httpStatus;
    $payload = error_payload($e->errorCode, $e->title, $e->getMessage());
} catch (Throwable $e) {
    error_log('[media-downloader] info.php: ' . $e->getMessage());
    $status  = 500;
    $payload = error_payload('server_error', 'Inspection failed', 'Could not inspect link.');
}

json_out($payload, $status);
