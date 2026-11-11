<?php
/**
 * Media Downloader - URL checks, safe downloading, file inspection and conversion
 */
defined('MEDIA_DL') || exit;

// ----------------------------------------------------------------------
// 1. Validating the URL the visitor typed
// ----------------------------------------------------------------------

/**
 * Check a URL and break it into parts. Throws MediaException with a friendly message.
 * This does NOT check the allow-list; see assert_url_allowed().
 *
 * @return array{url:string, scheme:string, host:string, port:int, path:string, query:string, extension:string, name:string}
 */
function parse_media_url($raw)
{
    $raw = trim((string) $raw);

    if ($raw === '') {
        throw new MediaException('empty_url', 'Enter a media URL', 'Paste a link to a video or audio file to continue.', 400);
    }
    if (strlen($raw) > 2048) {
        throw new MediaException('url_too_long', 'Link is too long', 'Links can be up to 2048 characters long.', 400);
    }
    if (preg_match('/[\x00-\x20\x7f]/', $raw)) {
        throw new MediaException('invalid_url', 'Invalid link', 'The link contains spaces or hidden characters. Copy it again and paste it in.', 400);
    }

    $parts = parse_url($raw);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        throw new MediaException('invalid_url', 'Invalid link', "That doesn't look like a web address. It should start with https://", 400);
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new MediaException('invalid_url', 'Invalid link', "Links that contain a username or password aren't supported.", 400);
    }

    $scheme = strtolower($parts['scheme']);
    $host   = strtolower(rtrim($parts['host'], '.'));
    $hostOk = strlen($host) <= 253
        && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host);
    if (!$hostOk) {
        throw new MediaException('invalid_url', 'Invalid link', 'The website name in the link is not valid. Names with special characters must use their ASCII (punycode) form.', 400);
    }

    if (host_matches($host, (array) config('unsupported_platforms', []))) {
        throw new MediaException(
            'platform_not_supported',
            "Downloads aren't supported for this site",
            "This platform doesn't support third-party media downloads. Paste a direct media link or YouTube video instead.",
            422
        );
    }

    $allowedSchemes = array_map('strtolower', (array) config('allowed_schemes', ['https']));
    if (!in_array($scheme, $allowedSchemes, true)) {
        $list = implode(' or ', array_map(function ($s) { return $s . '://'; }, $allowedSchemes));
        throw new MediaException('scheme_not_allowed', 'Link type not supported', 'Only ' . $list . ' links are supported.', 400);
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, array_map('intval', (array) config('allowed_ports', [80, 443])), true)) {
        throw new MediaException('port_not_allowed', 'Port not allowed', 'Links that use that network port are not supported.', 400);
    }

    // Percent-encode any raw non-ASCII bytes so the request line is always plain ASCII.
    $encode = function ($m) { return rawurlencode($m[0]); };
    $path   = preg_replace_callback('/[^\x21-\x7e]/', $encode, isset($parts['path']) ? $parts['path'] : '');
    $query  = preg_replace_callback('/[^\x21-\x7e]/', $encode, isset($parts['query']) ? $parts['query'] : '');
    if ($path === '') {
        $path = '/';
    }

    $url = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $port : '') . $path . ($query !== '' ? '?' . $query : '');

    return [
        'url'       => $url,
        'scheme'    => $scheme,
        'host'      => $host,
        'port'      => $port,
        'path'      => $path,
        'query'     => $query,
        'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        'name'      => rawurldecode(pathinfo($path, PATHINFO_FILENAME)),
    ];
}

/** The host must be on the administrator's allow-list (config 'allowed_hosts'). */
function assert_url_allowed(array $u)
{
    if (!host_matches($u['host'], (array) config('allowed_hosts', []))) {
        throw new MediaException(
            'source_not_supported',
            "That site isn't supported",
            'This server only downloads from sites on its approved list. Open "What can I download?" to see them, '
            . 'or ask whoever runs this server to add the host in includes/config.php.',
            403
        );
    }
}

// ----------------------------------------------------------------------
// 2. Network safety (SSRF protection)
// ----------------------------------------------------------------------

function is_public_ip($ip)
{
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
        return false;
    }
    $long = ip2long($ip);
    if ($long === false) {
        return false; // only IPv4 addresses are used by this tool
    }
    // Extra ranges PHP's filter does not cover: CGNAT, IETF protocol, benchmarking, multicast.
    $blocked = [['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4]];
    foreach ($blocked as $range) {
        $mask = -1 << (32 - $range[1]);
        if (($long & $mask) === (ip2long($range[0]) & $mask)) {
            return false;
        }
    }
    return true;
}

/** Look the host up ourselves and make sure every address is a public one. */
function resolve_host_ips($host)
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = @gethostbynamel($host);
        if (!$ips) {
            throw new MediaException(
                'dns_failed',
                "Couldn't find that website",
                'The server could not look up "' . $host . '". Check the link and your internet connection.',
                502
            );
        }
    }
    $ips = array_values(array_unique($ips));

    if (!config('allow_private_ips', false)) {
        foreach ($ips as $ip) {
            if (!is_public_ip($ip)) {
                throw new MediaException(
                    'blocked_address',
                    'Address not allowed',
                    'That link points to a private or internal network address, which is blocked for security.',
                    403
                );
            }
        }
    }
    return $ips;
}

function content_type_is_media($type)
{
    $type = strtolower(trim((string) $type));
    if (strpos($type, 'video/') === 0 || strpos($type, 'audio/') === 0) {
        return true;
    }
    return in_array($type, ['application/octet-stream', 'binary/octet-stream', 'application/mp4', 'application/ogg', 'application/x-matroska'], true);
}

function resolve_redirect_url(array $base, $location)
{
    $location = trim((string) $location);
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
        return $location;
    }
    $defaultPort = $base['scheme'] === 'https' ? 443 : 80;
    $origin = $base['scheme'] . '://' . $base['host'] . ((int) $base['port'] !== $defaultPort ? ':' . $base['port'] : '');
    if (strpos($location, '//') === 0) {
        return $base['scheme'] . ':' . $location;
    }
    if ($location !== '' && $location[0] === '/') {
        return $origin . $location;
    }
    $folder = substr($base['path'], 0, (int) strrpos($base['path'], '/') + 1);
    return $origin . rtrim($folder, '/') . '/' . $location;
}

/**
 * Download one URL into $destPath. Redirects are followed by hand (max 3) so every
 * hop is checked against the allow-list and resolved to public addresses again.
 *
 * @return array{bytes:int, content_type:string}
 */
function download_media_file($startUrl, $destPath, $timeoutSeconds)
{
    $maxBytes = (int) config('max_download_bytes', 0);
    $url      = $startUrl;

    for ($hop = 0; $hop <= 3; $hop++) {
        try {
            $u = parse_media_url($url);
            assert_url_allowed($u);
        } catch (MediaException $e) {
            if ($hop === 0) {
                throw $e;
            }
            throw new MediaException('redirect_blocked', 'Redirect blocked', 'The link redirects to a place this server is not allowed to download from.', 403);
        }

        $ips    = resolve_host_ips($u['host']);
        $result = curl_fetch_once($u, $ips, $destPath, $maxBytes, $timeoutSeconds);
        if ($result['redirect'] === null) {
            return ['bytes' => $result['bytes'], 'content_type' => $result['content_type']];
        }
        $url = resolve_redirect_url($u, $result['redirect']);
    }

    throw new MediaException('too_many_redirects', 'Too many redirects', 'The link redirected too many times, so it was not followed.', 502);
}

function curl_fetch_once(array $u, array $ips, $destPath, $maxBytes, $timeoutSeconds)
{
    if (!function_exists('curl_init')) {
        throw new MediaException('curl_missing', 'PHP cURL is not enabled', 'Enable "extension=curl" in php.ini and restart Apache (see README).', 500);
    }

    $handle      = null;
    $bytes       = 0;
    $status      = 0;
    $headers     = [];
    $tooLarge    = false;
    $notMedia    = false;
    $contentType = '';

    $ch = curl_init($u['url']);
    $options = [
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        // Pin the host to the addresses we already checked (blocks DNS rebinding).
        CURLOPT_RESOLVE         => [$u['host'] . ':' . $u['port'] . ':' . implode(',', $ips)],
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => (int) $timeoutSeconds > 0 ? (int) $timeoutSeconds : 0,
        CURLOPT_LOW_SPEED_LIMIT => 1024,
        CURLOPT_LOW_SPEED_TIME  => (int) $timeoutSeconds > 0 ? 20 : 0,
        CURLOPT_USERAGENT       => (string) config('user_agent', 'MediaDownloader/1.0'),
        CURLOPT_HTTPHEADER      => ['Accept: */*'],
        CURLOPT_PROXY           => '',
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$status, &$headers) {
            $length = strlen($line);
            $line   = trim($line);
            if (stripos($line, 'HTTP/') === 0) {
                $bits    = explode(' ', $line, 3);
                $status  = isset($bits[1]) ? (int) $bits[1] : 0;
                $headers = [];
            } elseif (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return $length;
        },
        CURLOPT_WRITEFUNCTION   => function ($ch, $data) use (&$handle, &$bytes, &$status, &$headers, &$tooLarge, &$notMedia, &$contentType, $destPath, $maxBytes) {
            if ($status !== 200) {
                return 0; // error page or redirect body: stop, we only need the headers
            }
            if ($handle === null) {
                $type = isset($headers['content-type']) ? trim(explode(';', $headers['content-type'])[0]) : '';
                if (!content_type_is_media($type)) {
                    $notMedia = true;
                    $contentType = $type;
                    return 0;
                }
                $contentType = $type;
                if ($maxBytes > 0 && isset($headers['content-length']) && (int) $headers['content-length'] > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $handle = @fopen($destPath, 'wb');
                if (!$handle) {
                    return 0;
                }
            }
            $bytes += strlen($data);
            if ($maxBytes > 0 && $bytes > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            if (fwrite($handle, $data) !== strlen($data)) {
                return 0; // disk full
            }
            return strlen($data);
        },
    ];

    $caBundle = (string) config('ca_bundle', '');
    if ($caBundle !== '' && is_file($caBundle)) {
        $options[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $options);

    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($handle) {
        fclose($handle);
    }

    if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
        @unlink($destPath);
        return ['redirect' => $headers['location'], 'bytes' => 0, 'content_type' => ''];
    }
    if ($tooLarge) {
        @unlink($destPath);
        throw new MediaException('file_too_large', 'File is too large', 'The file is bigger than the ' . human_size($maxBytes) . ' limit set on this server.', 413);
    }
    if ($notMedia) {
        @unlink($destPath);
        throw new MediaException(
            'not_media',
            "That link isn't a media file",
            'The server answered with "' . ($contentType !== '' ? $contentType : 'unknown type') . '" instead of video or audio. Use a direct link to the file itself, not a web page.',
            422
        );
    }
    if ($status === 404 || $status === 410) {
        @unlink($destPath);
        throw new MediaException('source_not_found', 'File not found', 'The website says this file does not exist (HTTP ' . $status . '). Check the link.', 404);
    }
    if ($status === 401 || $status === 403) {
        @unlink($destPath);
        throw new MediaException('source_forbidden', 'Access refused', 'The website refused the download (HTTP ' . $status . '). The file may need a login or may not allow direct downloads, and this tool does not work around that.', 403);
    }
    if ($status !== 0 && $status !== 200) {
        @unlink($destPath);
        throw new MediaException('source_error', 'The website returned an error', 'The website answered with HTTP ' . $status . '. Try again later.', 502);
    }
    if ($errno !== 0) {
        @unlink($destPath);
        if ($errno === 28) {
            throw new MediaException('download_timeout', 'Download took too long', 'The website was too slow, so the download was stopped.', 504);
        }
        if (in_array($errno, [35, 51, 58, 60, 77, 83, 90, 91], true)) {
            throw new MediaException('ssl_error', "Couldn't verify the website's certificate", 'PHP has no certificate bundle configured (common on a fresh XAMPP). See "SSL certificate problem" in README.md.', 502);
        }
        error_log('[media-downloader] cURL error ' . $errno . ': ' . $error);
        throw new MediaException('connect_failed', "Couldn't reach the website", 'The server could not download the file. Check the link and your internet connection.', 502);
    }
    if ($bytes === 0) {
        @unlink($destPath);
        throw new MediaException('empty_file', 'Empty file', 'The website returned an empty file.', 422);
    }

    return ['redirect' => null, 'bytes' => $bytes, 'content_type' => $contentType];
}

// ----------------------------------------------------------------------
// 3. Looking inside the downloaded file
// ----------------------------------------------------------------------

/**
 * Identify the container from the first bytes of the file. The result is also the
 * FFmpeg demuxer name we force, so FFmpeg never auto-detects odd formats
 * (playlists, concat scripts ...) from an untrusted file. Returns null if unknown.
 */
function sniff_container($path)
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return null;
    }
    $b = (string) fread($handle, 16);
    fclose($handle);
    if (strlen($b) < 12) {
        return null;
    }

    if (in_array(substr($b, 4, 4), ['ftyp', 'moov', 'mdat', 'free', 'wide', 'skip'], true)) {
        return 'mov'; // MP4, M4A, M4V, MOV
    }
    if (substr($b, 0, 4) === "\x1A\x45\xDF\xA3") {
        return 'matroska'; // MKV and WebM
    }
    if (substr($b, 0, 4) === 'OggS') {
        return 'ogg';
    }
    if (substr($b, 0, 4) === 'fLaC') {
        return 'flac';
    }
    if (substr($b, 0, 4) === 'RIFF') {
        $kind = substr($b, 8, 4);
        if ($kind === 'AVI ') {
            return 'avi';
        }
        if ($kind === 'WAVE') {
            return 'wav';
        }
        return null;
    }
    if (substr($b, 0, 3) === 'ID3') {
        return 'mp3';
    }
    $b0 = ord($b[0]);
    $b1 = ord($b[1]);
    if ($b0 === 0xFF && ($b1 & 0xE0) === 0xE0) {
        if (($b1 & 0xF6) === 0xF0) {
            return 'aac'; // ADTS AAC
        }
        if ((($b1 >> 1) & 3) !== 0 && (($b1 >> 3) & 3) !== 1) {
            return 'mp3';
        }
    }
    return null;
}

/** Ask ffprobe what is inside the file. */
function probe_media($path, $container, $timeoutSeconds)
{
    $ffprobe = binary_path(config('ffprobe_path'));
    $run = run_command([
        $ffprobe, '-v', 'error',
        '-protocol_whitelist', 'file',
        '-f', $container,
        '-print_format', 'json', '-show_format', '-show_streams',
        ffmpeg_path_arg($path),
    ], $timeoutSeconds);

    if ($run['timed_out']) {
        throw new MediaException('probe_timeout', 'Took too long', 'Inspecting the file took too long, so it was stopped.', 504);
    }
    $data = json_decode($run['out'], true);
    if ($run['code'] !== 0 || !is_array($data) || empty($data['streams']) || !is_array($data['streams'])) {
        throw new MediaException('unreadable_media', "Couldn't read this file", "The file downloaded, but it isn't a valid video or audio file (or it uses a format that can't be read).", 422);
    }

    $info = [
        'duration'    => isset($data['format']['duration']) ? (float) $data['format']['duration'] : 0.0,
        'has_video'   => false,
        'has_audio'   => false,
        'video_codec' => '',
        'audio_codec' => '',
        'video_index' => null,
        'audio_index' => null,
    ];
    foreach ($data['streams'] as $stream) {
        $type  = isset($stream['codec_type']) ? $stream['codec_type'] : '';
        $index = isset($stream['index']) ? (int) $stream['index'] : 0;
        $cover = !empty($stream['disposition']['attached_pic']); // album art is not a video track
        if ($type === 'video' && !$cover && !$info['has_video']) {
            $info['has_video']   = true;
            $info['video_codec'] = isset($stream['codec_name']) ? $stream['codec_name'] : '';
            $info['video_index'] = $index;
        } elseif ($type === 'audio' && !$info['has_audio']) {
            $info['has_audio']   = true;
            $info['audio_codec'] = isset($stream['codec_name']) ? $stream['codec_name'] : '';
            $info['audio_index'] = $index;
        }
    }
    return $info;
}

// ----------------------------------------------------------------------
// 4. Producing the MP4 / MP3 file
// ----------------------------------------------------------------------

/** Seconds we may still spend, capped. 0 means unlimited. */
function seconds_left($deadline, $cap)
{
    $cap = (int) $cap;
    if ($deadline <= 0 && $cap <= 0) {
        return 0; // Unlimited
    }
    if ($deadline <= 0) {
        return $cap;
    }
    $left = (int) floor($deadline - microtime(true));
    if ($left < 3) {
        throw new MediaException('timeout', 'Took too long', 'Processing ran out of time. Try a shorter file.', 504);
    }
    if ($cap <= 0) {
        return $left;
    }
    return min($cap, $left);
}

function ffmpeg_base_args($container, $sourcePath)
{
    return [
        binary_path(config('ffmpeg_path')),
        '-nostdin', '-hide_banner', '-loglevel', 'error',
        '-protocol_whitelist', 'file',
        '-f', $container,
        '-i', ffmpeg_path_arg($sourcePath),
    ];
}

function ffmpeg_mp4_command(array $info, $container, $sourcePath, $outPath, $mode)
{
    $args = ffmpeg_base_args($container, $sourcePath);
    array_push($args, '-map', '0:' . $info['video_index']);
    if ($info['has_audio']) {
        array_push($args, '-map', '0:' . $info['audio_index']);
    }
    array_push($args, '-sn', '-dn', '-map_metadata', '-1');

    if ($mode === 'smart' && $info['video_codec'] === 'h264') {
        array_push($args, '-c:v', 'copy');
    } else {
        array_push($args, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2');
    }
    if ($info['has_audio']) {
        if ($mode === 'smart' && $info['audio_codec'] === 'aac') {
            array_push($args, '-c:a', 'copy');
        } else {
            array_push($args, '-c:a', 'aac', '-b:a', '160k');
        }
    }
    array_push($args, '-movflags', '+faststart');
    $maxBytes = (int) config('max_output_bytes', 0);
    if ($maxBytes > 0) {
        array_push($args, '-fs', (string) $maxBytes);
    }
    array_push($args, '-f', 'mp4', '-y', ffmpeg_path_arg($outPath));
    return $args;
}

function ffmpeg_mp3_command(array $info, $container, $sourcePath, $outPath)
{
    $bitrate = (string) config('mp3_bitrate', '192k');
    if (!preg_match('/^\d{2,3}k$/', $bitrate)) {
        $bitrate = '192k';
    }
    $args = ffmpeg_base_args($container, $sourcePath);
    array_push($args, '-map', '0:' . $info['audio_index'], '-vn', '-sn', '-dn', '-map_metadata', '-1',
        '-c:a', 'libmp3lame', '-b:a', $bitrate);
    $maxBytes = (int) config('max_output_bytes', 0);
    if ($maxBytes > 0) {
        array_push($args, '-fs', (string) $maxBytes);
    }
    array_push($args, '-f', 'mp3', '-y', ffmpeg_path_arg($outPath));
    return $args;
}

/**
 * Turn the downloaded source file into the requested format.
 * Files that already are MP4 (H.264/AAC) or MP3 are kept as they are.
 *
 * @return array{path:string, converted:bool}
 */
function build_output($sourcePath, $format, $sourceExt, $token, $deadline)
{
    $outPath   = temp_dir() . DIRECTORY_SEPARATOR . $token . '.' . $format;
    $container = sniff_container($sourcePath);
    if ($container === null) {
        throw new MediaException('unreadable_media', "Couldn't read this file", "The file downloaded, but it isn't a video or audio format this tool recognises.", 422);
    }

    $ready = ffmpeg_ready();
    $info  = $ready ? probe_media($sourcePath, $container, seconds_left($deadline, config('probe_timeout', 20))) : null;

    if ($info !== null) {
        if ($format === 'mp4' && !$info['has_video']) {
            throw new MediaException('no_video_track', 'No video in this file', "This file only contains audio, so it can't be saved as MP4. Choose MP3 instead.", 422);
        }
        if ($format === 'mp3' && !$info['has_audio']) {
            throw new MediaException('no_audio_track', 'No audio in this file', "This file doesn't contain an audio track, so it can't be saved as MP3.", 422);
        }
    }

    // Can the downloaded file be handed over unchanged?
    $keep = false;
    if ($format === 'mp4' && $container === 'mov' && in_array($sourceExt, ['mp4', 'm4v'], true)) {
        $keep = $info === null
            || ($info['video_codec'] === 'h264' && (!$info['has_audio'] || in_array($info['audio_codec'], ['aac', 'mp3'], true)));
    } elseif ($format === 'mp3' && $container === 'mp3' && $sourceExt === 'mp3') {
        $keep = $info === null || $info['audio_codec'] === 'mp3';
    }

    if ($keep) {
        if (!@rename($sourcePath, $outPath)) {
            if (!@copy($sourcePath, $outPath)) {
                throw new MediaException('storage_error', 'Server storage problem', 'The file could not be saved in the temporary folder.', 500);
            }
            @unlink($sourcePath);
        }
        return ['path' => $outPath, 'converted' => false];
    }

    // Conversion needed.
    if ($info === null) {
        throw new MediaException(
            'ffmpeg_missing',
            'FFmpeg is needed for this file',
            'This file has to be converted to ' . strtoupper($format) . ', but FFmpeg was not found on the server. See section 4 of README.md.',
            501
        );
    }
    $maxDuration = (int) config('max_duration_seconds', 3600);
    if ($maxDuration > 0 && $info['duration'] > $maxDuration) {
        throw new MediaException('too_long', 'Media is too long to convert', 'Conversion is limited to ' . round($maxDuration / 60) . ' minutes of media on this server.', 413);
    }

    if ($format === 'mp4') {
        $commands = [ffmpeg_mp4_command($info, $container, $sourcePath, $outPath, 'smart')];
        $encode   = ffmpeg_mp4_command($info, $container, $sourcePath, $outPath, 'encode');
        if ($encode !== $commands[0]) {
            $commands[] = $encode; // fall back to a full re-encode if stream-copy fails
        }
    } else {
        $commands = [ffmpeg_mp3_command($info, $container, $sourcePath, $outPath)];
    }

    $done    = false;
    $lastErr = '';
    foreach ($commands as $command) {
        @unlink($outPath);
        $run = run_command($command, seconds_left($deadline, config('convert_timeout', 240)));
        if ($run['timed_out']) {
            @unlink($outPath);
            throw new MediaException('timeout', 'Conversion took too long', 'Converting this file took longer than the limit set on this server.', 504);
        }
        if ($run['code'] === 0 && is_file($outPath) && filesize($outPath) > 0) {
            $done = true;
            break;
        }
        $lastErr = $run['err'];
    }
    if (!$done) {
        @unlink($outPath);
        error_log('[media-downloader] FFmpeg failed: ' . substr($lastErr, 0, 800));
        throw new MediaException('conversion_failed', "Couldn't convert this file", 'FFmpeg was unable to convert the file to ' . strtoupper($format) . '. The file may be damaged or use an unsupported codec.', 422);
    }
    $maxBytes = (int) config('max_output_bytes', 0);
    if ($maxBytes > 0 && filesize($outPath) >= $maxBytes) {
        @unlink($outPath);
        throw new MediaException('output_too_large', 'Converted file is too large', 'The converted file would be bigger than the ' . human_size($maxBytes) . ' limit set on this server.', 413);
    }

    return ['path' => $outPath, 'converted' => true];
}
