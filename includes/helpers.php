<?php
/**
 * Media Downloader - general helpers
 * Configuration, sessions, CSRF, JSON output, temp files and safe process execution.
 */
defined('MEDIA_DL') || exit;

/**
 * A problem the visitor should be told about in plain language.
 * Anything else (a PHP error, for example) is logged and shown as a generic failure.
 */
class MediaException extends Exception
{
    public $errorCode;
    public $title;
    public $httpStatus;

    public function __construct($errorCode, $title, $message, $httpStatus = 422)
    {
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->title      = $title;
        $this->httpStatus = $httpStatus;
    }
}

/** Thrown when the source offers several qualities and the visitor must pick one. */
class QualityRequiredException extends Exception
{
    public $qualities;

    public function __construct(array $qualities)
    {
        parent::__construct('Quality selection required');
        $this->qualities = $qualities;
    }
}

// ----------------------------------------------------------------------
// Configuration
// ----------------------------------------------------------------------

/** Read a value from includes/config.php. Use dots for nested keys: config('resolver_api.enabled'). */
function config($key, $default = null)
{
    static $settings = null;
    if ($settings === null) {
        $settings = require __DIR__ . '/config.php';
    }
    $value = $settings;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

// ----------------------------------------------------------------------
// Sessions, CSRF and rate limiting (no database: PHP's own session files)
// ----------------------------------------------------------------------

function app_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('MEDIADLSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return @session_start();
}

function csrf_token()
{
    app_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_is_valid($sent)
{
    return is_string($sent)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

/** Returns 0 if the request may continue, otherwise the number of seconds to wait. */
function rate_limit_wait_seconds()
{
    $max = (int) config('rate_limit_requests', 0);
    if ($max <= 0) {
        return 0; // Disabled / unlimited
    }
    $window = max(1, (int) config('rate_limit_window', 60));
    $now    = time();

    $hits = isset($_SESSION['hits']) && is_array($_SESSION['hits']) ? $_SESSION['hits'] : [];
    $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
        return is_int($t) && $t > $now - $window;
    }));

    if (count($hits) >= $max) {
        $_SESSION['hits'] = $hits;
        return max(1, $hits[0] + $window - $now);
    }
    $hits[] = $now;
    $_SESSION['hits'] = $hits;
    return 0;
}

// ----------------------------------------------------------------------
// HTTP output
// ----------------------------------------------------------------------

function send_security_headers($withContentSecurityPolicy = false)
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    if ($withContentSecurityPolicy) {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; "
            . "script-src 'self'; connect-src 'self'; font-src 'self'; base-uri 'none'; "
            . "form-action 'self'; frame-ancestors 'none'");
    }
}

function json_out(array $data, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function error_payload($errorCode, $title, $message)
{
    return ['ok' => false, 'error' => ['code' => $errorCode, 'title' => $title, 'message' => $message]];
}

function read_json_body($maxBytes = 8192)
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '' || strlen($raw) > $maxBytes) {
        throw new MediaException('bad_request', 'Request not understood', 'The request was empty or too large. Reload the page and try again.', 400);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new MediaException('bad_request', 'Request not understood', 'The request was not valid JSON. Reload the page and try again.', 400);
    }
    return $data;
}

// ----------------------------------------------------------------------
// Small utilities
// ----------------------------------------------------------------------

function human_size($bytes)
{
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) (int) $bytes : number_format($bytes, $bytes >= 100 ? 0 : 1)) . ' ' . $units[$i];
}

/** Turn a title into a safe download name such as "My clip.mp4". */
function safe_display_name($title, $extension)
{
    $title = preg_replace('/[^\p{L}\p{N} ._()-]+/u', ' ', (string) $title);
    $title = preg_replace('/\s+/u', ' ', (string) $title);
    $title = trim((string) $title, " ._-");
    if ($title === '') {
        $title = 'media';
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 80, 'UTF-8') : substr($title, 0, 80);
    return $title . '.' . $extension;
}

/** True if $host equals one of the patterns. "*.example.com" matches subdomains only. */
function host_matches($host, array $patterns)
{
    $host = strtolower((string) $host);
    foreach ($patterns as $pattern) {
        $pattern = strtolower(trim((string) $pattern));
        if ($pattern === '') {
            continue;
        }
        if (strlen($pattern) > 2 && $pattern[0] === '*' && $pattern[1] === '.') {
            $suffix = substr($pattern, 1); // ".example.com"
            if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        } elseif ($host === $pattern) {
            return true;
        }
    }
    return false;
}

function random_token()
{
    return bin2hex(random_bytes(16)); // 32 hex characters
}

/** Windows paths with forward slashes are accepted by FFmpeg and avoid escaping trouble. */
function ffmpeg_path_arg($path)
{
    return 'file:' . str_replace('\\', '/', $path);
}

// ----------------------------------------------------------------------
// Temporary files
// ----------------------------------------------------------------------

function temp_dir()
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $configured = (string) config('temp_dir');
    if ($configured !== '' && !is_dir($configured)) {
        @mkdir($configured, 0775, true);
    }
    $real = $configured === '' ? false : realpath($configured);
    if ($real === false || !is_dir($real) || !is_writable($real)) {
        throw new MediaException(
            'temp_unavailable',
            'Server storage problem',
            'The temporary folder is missing or not writable. Check "temp_dir" in includes/config.php.',
            500
        );
    }
    $dir = $real;
    return $dir;
}

/** Delete expired files. Only files whose names match our own pattern are ever touched. */
function cleanup_temp_files()
{
    try {
        $dir = temp_dir();
    } catch (Exception $e) {
        return;
    }
    $cutoff = time() - max(60, (int) config('file_ttl_seconds', 900));
    $names  = @scandir($dir);
    if (!is_array($names)) {
        return;
    }
    foreach ($names as $name) {
        if (!preg_match('/^[a-f0-9]{32}\.(mp4|mp3|json|src|out|err)$/', $name)) {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && !is_link($path) && @filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

/** Limit how many downloads/conversions run at once. Returns a lock handle or null. */
function acquire_job_slot()
{
    $dir = temp_dir();
    $max = max(1, (int) config('max_concurrent_jobs', 2));
    for ($i = 1; $i <= $max; $i++) {
        $handle = @fopen($dir . DIRECTORY_SEPARATOR . 'slot-' . $i . '.lock', 'c');
        if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }
        if ($handle) {
            fclose($handle);
        }
    }
    return null;
}

function release_job_slot($handle)
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// ----------------------------------------------------------------------
// FFmpeg and safe process execution
// ----------------------------------------------------------------------

/** Find an executable from a config value such as "ffmpeg" or "C:/ffmpeg/bin/ffmpeg.exe". */
function binary_path($configured)
{
    static $cache = [];
    $configured = trim((string) $configured);
    if ($configured === '') {
        return null;
    }
    if (array_key_exists($configured, $cache)) {
        return $cache[$configured];
    }

    $isWindows  = DIRECTORY_SEPARATOR === '\\';
    $candidates = [];
    if (strpbrk($configured, '/\\') !== false) {
        $candidates[] = $configured;
        if ($isWindows && !preg_match('/\.exe$/i', $configured)) {
            $candidates[] = $configured . '.exe';
        }
    } else {
        $folders = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        if ($isWindows) {
            $folders[] = 'C:\\ffmpeg\\bin';
            $folders[] = 'C:\\Users\\Louis\\AppData\\Local\\Programs\\Python\\Python313\\Scripts';
        }
        foreach ($folders as $folder) {
            $folder = rtrim(trim($folder, " \t\"'"), '/\\');
            if ($folder === '') {
                continue;
            }
            $candidates[] = $folder . DIRECTORY_SEPARATOR . $configured;
            if ($isWindows) {
                $candidates[] = $folder . DIRECTORY_SEPARATOR . $configured . '.exe';
            }
        }
    }

    $found = null;
    foreach ($candidates as $candidate) {
        if (@is_file($candidate) && @is_executable($candidate)) {
            $found = $candidate;
            break;
        }
    }
    $cache[$configured] = $found;
    return $found;
}

function ffmpeg_ready()
{
    return binary_path(config('ffmpeg_path')) !== null && binary_path(config('ffprobe_path')) !== null;
}

function ytdlp_ready()
{
    return binary_path(config('ytdlp_path', 'yt-dlp')) !== null;
}

/**
 * Run a program WITHOUT a shell. The command is an array, so user-controlled text
 * can never be interpreted as extra commands. Output goes to temp files (pipes can
 * block on Windows) and the process is killed when the time limit is reached.
 *
 * @return array{code:int, out:string, err:string, timed_out:bool}
 */
function run_command(array $command, $timeoutSeconds)
{
    $dir     = temp_dir();
    $id      = random_token();
    $outFile = $dir . DIRECTORY_SEPARATOR . $id . '.out';
    $errFile = $dir . DIRECTORY_SEPARATOR . $id . '.err';

    $spec  = [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']];
    $pipes = [];
    $proc  = @proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        @unlink($outFile);
        @unlink($errFile);
        return ['code' => -1, 'out' => '', 'err' => 'Could not start the program.', 'timed_out' => false];
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $timeout    = (int) $timeoutSeconds;
    $hasTimeout = $timeout > 0;
    $deadline   = $hasTimeout ? (microtime(true) + $timeout) : 0;
    $timedOut   = false;
    $exitCode   = -1;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }
        if ($hasTimeout && microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($proc, 9);
            break;
        }
        usleep(100000);
    }
    proc_close($proc);

    $out = (string) @file_get_contents($outFile, false, null, 0, 2 * 1024 * 1024);
    $err = (string) @file_get_contents($errFile, false, null, 0, 64 * 1024);
    @unlink($outFile);
    @unlink($errFile);

    return ['code' => $exitCode, 'out' => $out, 'err' => $err, 'timed_out' => $timedOut];
}
