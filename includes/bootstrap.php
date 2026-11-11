<?php
/**
 * Media Downloader - bootstrap
 * Every entry point (index.php, api/*.php) defines MEDIA_DL and then loads this file.
 */
defined('MEDIA_DL') || exit;

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('Media Downloader needs PHP 7.4 or newer. XAMPP with PHP 8.x is recommended.');
}

// Never show PHP errors to visitors (they can reveal file paths). They go to the PHP error log.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/helpers.php';
require __DIR__ . '/media.php';
require __DIR__ . '/providers.php';
