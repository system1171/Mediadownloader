<?php
/**
 * GET /api/download.php?token=<32 hex characters>
 *
 * The only way a processed file leaves the server. The token is random and
 * single-purpose, the file name on disk is built by the server (never taken
 * from the request) and nothing outside the temp folder can be reached.
 */
define('MEDIA_DL', true);
require __DIR__ . '/../includes/bootstrap.php';

send_security_headers();

function download_fail($status)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "This download link has expired or does not exist.\nGo back to the page and create the file again.";
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    download_fail(405);
}

$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    download_fail(404);
}

try {
    $dir = temp_dir();
} catch (MediaException $e) {
    download_fail(500);
}
cleanup_temp_files();

$metaPath = $dir . DIRECTORY_SEPARATOR . $token . '.json';
$meta     = is_file($metaPath) ? json_decode((string) @file_get_contents($metaPath), true) : null;
$types    = ['mp4' => 'video/mp4', 'mp3' => 'audio/mpeg'];

if (!is_array($meta)
    || !isset($meta['ext'], $meta['name'], $meta['expires'], $types[$meta['ext']])
    || (int) $meta['expires'] < time()) {
    download_fail(404);
}

$file = $dir . DIRECTORY_SEPARATOR . $token . '.' . $meta['ext'];
if (!is_file($file) || is_link($file)) {
    download_fail(404);
}
$size = (int) filesize($file);

$asciiName = preg_replace('/[^A-Za-z0-9._() -]/', '_', (string) $meta['name']);
header('Content-Type: ' . $types[$meta['ext']]);
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode((string) $meta['name']));
header('Cache-Control: no-store');
header('Accept-Ranges: none');

if ($method === 'HEAD') {
    exit;
}

@set_time_limit(0);
ignore_user_abort(true);
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    @ob_end_clean();
}

$handle = fopen($file, 'rb');
if ($handle) {
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        flush();
        if (connection_aborted()) {
            break;
        }
    }
    fclose($handle);
}

// Remove the file after a complete download (an interrupted one can be retried until it expires).
if (!connection_aborted() && config('delete_after_download', true)) {
    @unlink($file);
    @unlink($metaPath);
}
