<?php
/**
 * Media Downloader - configuration
 *
 * This file is read by PHP only. Nothing in it is ever sent to the browser,
 * so it is the right place for API keys. Windows paths can use forward
 * slashes (C:/ffmpeg/bin/ffmpeg.exe) to avoid escaping backslashes.
 */
defined('MEDIA_DL') || exit;

return [

    'app_name' => 'Media Downloader',

    // ------------------------------------------------------------------
    // Temporary files
    // ------------------------------------------------------------------

    // Folder for processed files. It must be writable by Apache/PHP.
    // The default is the project's own temp/ folder (web access is blocked
    // by temp/.htaccess). For extra safety you can point it outside htdocs,
    // for example 'C:/xampp/media-downloader-temp'.
    'temp_dir' => dirname(__DIR__) . '/temp',

    // How long a finished file stays on the server if it is never saved.
    'file_ttl_seconds' => 900, // 15 minutes

    // Delete the file as soon as it has been downloaded once.
    'delete_after_download' => true,

    // ------------------------------------------------------------------
    // FFmpeg and yt-dlp (needed for YouTube, MP3 extraction and conversions)
    // ------------------------------------------------------------------

    // Use 'ffmpeg' / 'ffprobe' if in PATH, or specify exact Windows paths.
    'ffmpeg_path'  => 'C:/ffmpeg/bin/ffmpeg.exe',
    'ffprobe_path' => 'C:/ffmpeg/bin/ffprobe.exe',
    'ytdlp_path'   => 'C:/ffmpeg/bin/yt-dlp.exe',
    'node_path'    => 'C:/Program Files/nodejs/node.exe',

    // MP3 quality used when audio has to be converted.
    'mp3_bitrate' => '192k',

    // ------------------------------------------------------------------
    // Limits (0 = unlimited)
    // ------------------------------------------------------------------

    'max_download_bytes'   => 0, // 0 = unlimited (download files of any size)
    'max_output_bytes'     => 0, // 0 = unlimited (produce converted files of any size)
    'max_duration_seconds' => 0, // 0 = unlimited (any media duration allowed)

    'download_timeout'   => 0,   // 0 = unlimited (no timeout on fetching/merging source)
    'probe_timeout'      => 60,  // seconds allowed for inspecting a file
    'convert_timeout'    => 0,   // 0 = unlimited (no timeout on FFmpeg conversions)
    'request_time_limit' => 0,   // 0 = unlimited (overall execution time limit disabled)

    'max_concurrent_jobs' => 10, // simultaneous downloads/conversions on this server
    'rate_limit_requests' => 0,  // 0 = rate limit disabled (download without limits)
    'rate_limit_window'   => 60, // ... within this many seconds

    // ------------------------------------------------------------------
    // Where media may come from
    // ------------------------------------------------------------------

    // Only these URL schemes are accepted. Add 'http' only if you must.
    'allowed_schemes' => ['https'],
    'allowed_ports'   => [80, 443],

    // Only links on these hosts can be downloaded. "*.example.com" matches
    // any subdomain of example.com (but not example.com itself).
    // The defaults are sites that publish open-licence sample media, plus YouTube.
    'allowed_hosts' => [
        'download.blender.org',
        'test-videos.co.uk',
        'samplelib.com',
        'upload.wikimedia.org',
        'archive.org',
        '*.archive.org',
        'youtube.com',
        '*.youtube.com',
        'youtu.be',
        '*.youtu.be',
        'youtube-nocookie.com',
        '*.youtube-nocookie.com',
    ],

    // File types the direct-link provider accepts (taken from the URL path).
    'video_extensions' => ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'ogv'],
    'audio_extensions' => ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'flac'],

    // Sites that never allow third-party downloads.
    'unsupported_platforms' => [],

    // SSRF protection blocks hosts that resolve to private/internal addresses
    // (127.0.0.1, 192.168.x.x, 10.x.x.x ...). For local testing ONLY you can
    // set this to true and add 'localhost' to allowed_hosts. Turn it off again
    // afterwards.
    'allow_private_ips' => false,

    // Sent to the media host. Some hosts ask for a descriptive value.
    'user_agent' => 'MediaDownloader/1.0 (+local XAMPP)',

    // Optional: full path to a CA bundle (cacert.pem) if cURL reports
    // "SSL certificate problem". Leave empty if php.ini already sets curl.cainfo.
    // Example: 'C:/xampp/php/extras/ssl/cacert.pem'
    'ca_bundle' => '',

    // ------------------------------------------------------------------
    // Optional: an authorized resolver API (disabled by default)
    // ------------------------------------------------------------------
    // Use this if you have a service you are authorized to use that turns a
    // page link into direct media links. The request/response format is
    // described in README.md ("Adding an authorized media source").
    // Direct file links work without this section.
    'resolver_api' => [
        'enabled'  => false,
        'endpoint' => '',                      // https://api.example.com/v1/resolve
        'api_key'  => getenv('MEDIA_RESOLVER_API_KEY') ?: '',
        'key_header' => 'Authorization',       // header that carries the key
        'key_prefix' => 'Bearer ',             // text placed before the key
        'handles_hosts' => [],                 // page hosts this API is allowed to resolve
        'timeout'  => 20,
    ],
];
