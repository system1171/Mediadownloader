<?php
/**
 * POST /api/process.php
 * Body (JSON): { "url": "https://...", "format": "mp4" | "mp3", "quality": "optional" }
 * Header:      X-CSRF-Token (issued by index.php)
 *
 * Validates the request, fetches the media from an approved source, converts it
 * with FFmpeg when needed and answers with a one-time download link.
 */
define('MEDIA_DL', true);
require __DIR__ . '/../includes/bootstrap.php';

send_security_headers();

// If PHP dies with a fatal error, still answer in JSON so the page can show a message.
register_shutdown_function(function () {
    $last = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if ($last && in_array($last['type'], $fatal, true) && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(error_payload('server_error', 'Something went wrong on the server', 'The request could not be completed. Check the PHP error log for details.'));
    }
});

/** Handles the whole request. Returns [httpStatus, payload]. */
function handle_process_request()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new MediaException('method_not_allowed', 'Method not allowed', 'This address only accepts POST requests.', 405);
    }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        throw new MediaException('bad_request', 'Request not understood', 'Send the request as JSON.', 415);
    }

    // Session: CSRF check and rate limit, then release the session lock before slow work.
    if (!app_session_start()) {
        throw new MediaException('session_failed', 'Session problem', 'PHP could not start a session. Check session.save_path in php.ini.', 500);
    }
    if (!csrf_is_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        throw new MediaException('csrf', 'This page has expired', 'Reload the page and try again.', 403);
    }
    $wait = rate_limit_wait_seconds();
    session_write_close();
    if ($wait > 0) {
        throw new MediaException('rate_limited', 'Slow down a little', 'Too many requests. Try again in ' . $wait . ' seconds.', 429);
    }

    // Validate input.
    $input  = read_json_body();
    $format = isset($input['format']) && is_string($input['format']) ? strtolower($input['format']) : '';
    if (!in_array($format, ['mp4', 'mp3'], true)) {
        throw new MediaException('bad_format', 'Choose a format', 'Select MP4 video or MP3 audio.', 400);
    }
    $quality = isset($input['quality']) && is_string($input['quality']) ? trim($input['quality']) : '';
    if ($quality !== '' && !preg_match('/^[A-Za-z0-9 ._@\/-]{1,40}$/', $quality)) {
        throw new MediaException('bad_quality', 'Invalid quality', 'Pick one of the qualities offered.', 400);
    }
    $urlParts = parse_media_url(isset($input['url']) && is_string($input['url']) ? $input['url'] : '');

    // Housekeeping and limits.
    cleanup_temp_files();
    $limit = (int) config('request_time_limit', 0);
    if ($limit > 0) {
        @set_time_limit($limit + 30);
        $deadline = microtime(true) + $limit;
    } else {
        @set_time_limit(0);
        $deadline = 0;
    }

    $slot = acquire_job_slot();
    if ($slot === null) {
        throw new MediaException('busy', 'The server is busy', 'Other downloads are being processed right now. Wait a moment and try again.', 503);
    }

    $token      = random_token();
    $dir        = temp_dir();
    $sourcePath = $dir . DIRECTORY_SEPARATOR . $token . '.src';
    $outputPath = null;
    $finished   = false;

    try {
        $source = pick_source($urlParts, $format, $quality);

        if (isset($source['custom_downloader']) && is_callable($source['custom_downloader'])) {
            $downloadResult = call_user_func($source['custom_downloader'], $format, $quality, $token, $deadline);
            $outputPath = $downloadResult['path'];
            $title      = !empty($downloadResult['title']) ? $downloadResult['title'] : $source['title'];
            $built      = ['path' => $outputPath, 'converted' => true];
        } else {
            download_media_file($source['url'], $sourcePath, seconds_left($deadline, config('download_timeout', 120)));
            $built      = build_output($sourcePath, $format, $source['extension'], $token, $deadline);
            $outputPath = $built['path'];
            $title      = $source['title'];
        }

        $size       = (int) filesize($outputPath);
        $name       = safe_display_name($title, $format);
        $ttl        = max(60, (int) config('file_ttl_seconds', 900));

        $meta = [
            'name'    => $name,
            'ext'     => $format,
            'size'    => $size,
            'created' => time(),
            'expires' => time() + $ttl,
        ];
        if (@file_put_contents($dir . DIRECTORY_SEPARATOR . $token . '.json', json_encode($meta), LOCK_EX) === false) {
            throw new MediaException('storage_error', 'Server storage problem', 'The file could not be saved in the temporary folder.', 500);
        }
        $finished = true;

        return [200, [
            'ok'   => true,
            'file' => [
                'name'         => $name,
                'format'       => strtoupper($format),
                'size'         => $size,
                'size_label'   => human_size($size),
                'download_url' => 'api/download.php?token=' . $token,
                'expires_in'   => $ttl,
                'single_use'   => (bool) config('delete_after_download', true),
                'converted'    => $built['converted'],
            ],
        ]];
    } finally {
        @unlink($sourcePath);
        if (!$finished && $outputPath !== null) {
            @unlink($outputPath);
        }
        release_job_slot($slot);
    }
}

try {
    list($status, $payload) = handle_process_request();
} catch (QualityRequiredException $e) {
    $status  = 200;
    $payload = [
        'ok'            => true,
        'needs_quality' => true,
        'qualities'     => $e->qualities,
        'message'       => 'This source offers several qualities. Choose one, then press Download again.',
    ];
} catch (MediaException $e) {
    $status  = $e->httpStatus;
    $payload = error_payload($e->errorCode, $e->title, $e->getMessage());
} catch (Throwable $e) {
    error_log('[media-downloader] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $status  = 500;
    $payload = error_payload('server_error', 'Something went wrong on the server', 'The request could not be completed. Check the PHP error log for details.');
}

json_out($payload, $status);
