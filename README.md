# Media Downloader

A small, database-free website that saves a **direct video or audio link** as **MP4 video** or **MP3 audio**. It runs on your own computer with XAMPP (Apache + PHP) and uses FFmpeg for conversion.

- Frontend: HTML5, CSS3, vanilla JavaScript (no frameworks, fonts bundled locally)
- Backend: PHP 7.4+ (XAMPP with PHP 8.x recommended), cURL, FFmpeg
- No database, no login, no registration, no download history

## What it does

**Works out of the box:**
1. **Modern Dark UI:** Responsive layout with dark navy, charcoal, and purple tones, complemented by yellow-orange and pink gradient accents. Includes an animated level meter, real-time status feedback, and an accessible collapsible help accordion.
2. **YouTube downloads with 4K & 2K support:** paste any YouTube link (`youtube.com/watch`, `youtu.be`, Shorts) and download as **MP4 video** (with auto-detected quality choices: 4K Ultra HD 2160p, 2K Quad HD 1440p, 1080p, 720p, 480p, 360p) or extract as **MP3 audio** (64 kbps to 320 kbps studio quality). Powered by `yt-dlp` and `FFmpeg`.
3. **Auto Quality Detection:** Inspects video links to scan the actual available resolutions. Options higher than the source upload are clearly disabled, preventing failed downloads.
4. **Direct media files:** direct links to `.mp4`, `.webm`, `.mov`, `.mkv`, `.avi`, `.mp3`, `.m4a`, `.wav`, `.ogg`, `.flac` and more on approved hosts in `includes/config.php`. Direct files are fetched, converted if needed, and handed to you through a one-time link.
5. **No database, no logins:** clean temporary file management with automatic TTL expiration.

---

## 1. Install XAMPP and start Apache

1. Download XAMPP for Windows from <https://www.apachefriends.org> and install it (the default folder is `C:\xampp`). Choose a version with PHP 7.4 or newer.
2. Open the **XAMPP Control Panel** and click **Start** next to **Apache**. You do **not** need MySQL.
3. Open <http://localhost/> in a browser. If you see the XAMPP dashboard, Apache is working.
   - If Apache will not start, another program (often Skype, IIS or another web server) is using port 80. Either stop that program or change Apache's port under **Config > httpd.conf** (`Listen 8080`). The site address then becomes `http://localhost:8080/media-downloader/`.

## 2. Put the project in `htdocs`

Copy the whole `media-downloader` folder to:

```
C:\xampp\htdocs\media-downloader
```

so that `C:\xampp\htdocs\media-downloader\index.php` exists. The `.htaccess` files in the project rely on XAMPP's default setting `AllowOverride All` for `htdocs`; leave that as it is.

## 3. Configure PHP and the temporary folder

Open the PHP settings: in the Control Panel click **Config** next to Apache, then **PHP (php.ini)**. Check that these lines are **not** commented out (no `;` at the start):

```ini
extension=curl
extension=openssl
```

(`extension=mbstring` is also recommended; it is on by default in XAMPP.) Save the file and restart Apache.

The defaults for memory and session settings work as they are. The script sets its own time limits, controlled by `request_time_limit` in `includes/config.php`.

### Temporary folder

Processed files are stored for a short time in `media-downloader\temp\`, with random names. Apache must be able to write there (it normally can). Access from the web is blocked by `temp\.htaccess`, and files can only be fetched through `api/download.php` with a random token.

To keep temporary files **outside** the web folder (recommended), create a folder such as `C:\xampp\media-downloader-temp` and set it in `includes/config.php`:

```php
'temp_dir' => 'C:/xampp/media-downloader-temp',
```

Files are deleted right after they are downloaded, or after `file_ttl_seconds` (15 minutes by default). Expired files are removed whenever the next request comes in.

### SSL certificates (important on a fresh XAMPP)

PHP needs a certificate bundle to download from `https://` addresses. If you ever see **"Couldn't verify the website's certificate"**:

1. Download `cacert.pem` from <https://curl.se/docs/caextract.html>.
2. Save it as `C:\xampp\php\extras\ssl\cacert.pem` (create the folders if they do not exist).
3. Either add these lines to `php.ini` and restart Apache:
   ```ini
   curl.cainfo = "C:\xampp\php\extras\ssl\cacert.pem"
   openssl.cafile = "C:\xampp\php\extras\ssl\cacert.pem"
   ```
   or set `'ca_bundle' => 'C:/xampp/php/extras/ssl/cacert.pem'` in `includes/config.php`.

## 4. Install and configure FFmpeg

FFmpeg is required for **MP3** (unless the source is already an MP3) and for turning WebM/MKV/AVI/MOV into **MP4**. Sources that already are H.264/AAC MP4 files are passed through unchanged and do not need FFmpeg.

1. Go to <https://ffmpeg.org/download.html>, choose **Windows**, and download a "full" or "release" build (for example from gyan.dev or BtbN).
2. Extract it to `C:\ffmpeg` so that `C:\ffmpeg\bin\ffmpeg.exe` and `C:\ffmpeg\bin\ffprobe.exe` exist.
3. Tell the site where they are. Pick one:
   - **Easiest and most reliable:** set the full paths in `includes/config.php`:
     ```php
     'ffmpeg_path'  => 'C:/ffmpeg/bin/ffmpeg.exe',
     'ffprobe_path' => 'C:/ffmpeg/bin/ffprobe.exe',
     ```
   - **Or** add `C:\ffmpeg\bin` to the **System** `Path` variable (Windows search > "Edit the system environment variables" > Environment Variables > System variables > Path > New). Restart Apache afterwards, or reboot if it still is not picked up. Apache can only see the *System* Path, not a user-only Path.
4. Check it from a Command Prompt:
   ```
   ffmpeg -version
   ffmpeg -encoders | findstr "libx264 libmp3lame"
   ```
   Both `libx264` and `libmp3lame` should be listed (the standard Windows builds include them).
5. Reload the website. The badge at the top right shows **FFmpeg ready** when both programs were found.

## 5. Configure the media sources

Everything is in `includes/config.php`. The most important settings:

| Setting | What it does |
| --- | --- |
| `allowed_hosts` | The only sites links may point to. `*.example.com` allows subdomains. **Only add sites whose content you are allowed to download.** The defaults are open-licence sample sites. |
| `allowed_schemes` | `['https']` by default. Add `'http'` only if you must. |
| `video_extensions` / `audio_extensions` | File types accepted by the direct-link provider. |
| `max_download_bytes` | Largest source file (default 200 MB). |
| `max_output_bytes`, `max_duration_seconds` | Limits for converted files (300 MB, 1 hour). |
| `download_timeout`, `convert_timeout`, `request_time_limit` | Time limits in seconds. |
| `max_concurrent_jobs` | How many downloads/conversions may run at once (default 2). |
| `rate_limit_requests` / `rate_limit_window` | Requests allowed per browser session in a time window. |
| `delete_after_download`, `file_ttl_seconds` | When temporary files are removed. |
| `mp3_bitrate` | MP3 quality when converting (default `192k`). |
| `user_agent` | Sent to media hosts. Some hosts want a descriptive value. |
| `allow_private_ips` | Leave `false`. See "Testing without internet" below. |

## 6. Open the website

With Apache running, open:

**<http://localhost/media-downloader/>**

---

## 7. Testing

### A successful download

1. Open the site and paste this open-licence sample (Big Buck Bunny, published by the Blender Foundation):
   ```
   https://download.blender.org/peach/bigbuckbunny_movies/BigBuckBunny_320x180.mp4
   ```
   If the link has moved, any direct file link on a host listed in `allowed_hosts` works the same way.
2. Choose **MP4 Video** and click **Download MP4**. The meter animates while the server works. When it finishes, **Save file** appears.
3. Choose **MP3 Audio** and repeat. This converts with FFmpeg (the badge must say "FFmpeg ready").
4. Click **Save file**. Afterwards the link is retired and the file is removed from `temp`.

### Testing without internet (local files)

1. Create `C:\xampp\htdocs\samples\clip.mp4` (any short MP4).
2. **Temporarily** edit `includes/config.php`:
   ```php
   'allowed_schemes' => ['https', 'http'],
   'allowed_hosts'   => ['localhost'],
   'allow_private_ips' => true,
   ```
3. Use `http://localhost/samples/clip.mp4` as the link.
4. **Put the three settings back afterwards.** `allow_private_ips` switches off protection against requests to your own machine and network for the hosts you listed.

### Error handling checks

| Try this | Expected result |
| --- | --- |
| Click Download with an empty box | "Enter a media URL to continue." under the field, nothing is sent |
| Type `hello` or `example.com/a.mp4` | A message asking for a proper web address |
| Paste a `youtube.com` or `youtu.be` link | "YouTube downloads aren't supported" with an explanation; no file, no download button |
| A link on a site not in `allowed_hosts` | "That site isn't supported" |
| A page address such as `.../index.html` on an allowed host | "That isn't a direct media link" |
| An `.mp3` link with **MP4** selected | "No video in this file" |
| Set `max_download_bytes` to `1024 * 1024` and use a larger file | "File is too large" |
| Rename `ffmpeg_path` to something wrong and ask for MP3 from a WebM | "FFmpeg is needed for this file" (and the badge shows "FFmpeg not found") |
| Stop Apache while the page is open and click Download | "Couldn't reach the server" with a hint to check Apache |
| Press **Cancel** during a long download | The page stops waiting and returns to normal (leftover temporary files expire on their own) |

---

## Adding an authorized media source

The backend asks a **provider** which direct file to fetch for a link. Two providers are included (`includes/providers.php`):

1. **`DirectMediaProvider`** (active): direct file links on `allowed_hosts`.
2. **`ResolverApiProvider`** (disabled until configured): calls an HTTP API **you are authorized to use** that turns a page link into direct media links.

There is no built-in connection to YouTube or any other third-party service, and none is claimed.

### Configuring the resolver API

In `includes/config.php`:

```php
'resolver_api' => [
    'enabled'       => true,
    'endpoint'      => 'https://api.your-service.example/v1/resolve',
    'api_key'       => 'YOUR-KEY',            // or set the MEDIA_RESOLVER_API_KEY environment variable
    'key_header'    => 'Authorization',
    'key_prefix'    => 'Bearer ',
    'handles_hosts' => ['media.your-service.example'],  // page links this API may resolve
    'timeout'       => 20,
],
```

The key stays on the server; it is never placed in JavaScript or HTML.

The adapter sends `POST {"url": "<the link the visitor pasted>"}` and expects JSON like this:

```json
{
  "title": "Optional title",
  "formats": [
    { "type": "video", "container": "mp4", "quality": "720p",    "url": "https://cdn.your-service.example/a720.mp4" },
    { "type": "video", "container": "mp4", "quality": "360p",    "url": "https://cdn.your-service.example/a360.mp4" },
    { "type": "audio", "container": "mp3", "quality": "128kbps", "url": "https://cdn.your-service.example/a.mp3" }
  ]
}
```

- This request/response format is the one **this project defines**. Real services differ, so adapt `ResolverApiProvider::callApi()` (and, if needed, `resolve()`) to your service's documented format.
- If a source returns several qualities, the page shows a **Quality** menu. With a single quality, nothing extra is shown.
- The returned file URLs go through the same checks as any other link, so the CDN host must also be listed in `allowed_hosts`.

### Writing your own provider

Create a class that implements `MediaProvider` (`supports()` and `resolve()`) and add it to `media_providers()`. Everything after that (SSRF protection, size limits, FFmpeg, temporary files, one-time links) is shared.

---

## How it works

1. `index.php` renders the page and issues a CSRF token tied to the visitor's PHP session.
2. `script.js` validates the link and posts JSON to `api/process.php`.
3. The server re-validates everything, then: picks a provider, downloads the file (size and time limits, checked redirects), inspects it with `ffprobe`, keeps it as is or converts it with `ffmpeg`, and stores the result under a random 32-character name in the temp folder.
4. The page shows a one-time link to `api/download.php`, which streams the file and deletes it.

## Security measures

- **Allow-lists:** only approved URL schemes, ports and hosts; YouTube links get an explanation instead of an attempt.
- **SSRF protection:** hosts are resolved by the server, every address must be public (private, loopback, link-local and similar ranges are refused), the connection is pinned to the checked addresses, redirects are followed manually and re-checked at every hop.
- **No shell:** programs are started with an argument array (`proc_open`), never a command string, so input cannot become commands. File names on disk are server-generated.
- **Untrusted files:** the container type is detected from the file's first bytes and forced for FFmpeg, FFmpeg is limited to local files, and only the first video/audio streams are used. Keep FFmpeg up to date.
- **Path traversal / arbitrary files:** downloads need a 32-hex-character token; the path is built from the token and a fixed extension list. Only files matching that pattern are ever cleaned up.
- **Limits:** download size, output size, media duration, time per stage, concurrent jobs, and a per-session rate limit.
- **Access control:** `temp/` and `includes/` are blocked by `.htaccess`, directory listing is off, and the JSON API requires a CSRF token. The page sends a strict Content Security Policy and loads nothing from other sites.
- **Secrets:** `config.php` is never sent to the browser; PHP errors are logged, not displayed.

This project is built for **use on your own computer**. If you ever put it on a public server, add HTTPS, authentication, and stronger rate limiting first. It also cannot tell whether a file is DRM-protected: do not point it at content you are not allowed to download.

## Project structure

```
media-downloader/
├── index.php                  page (HTML rendered by PHP)
├── .htaccess                  no directory listing, hides dotfiles
├── assets/
│   ├── css/style.css          dark theme, layout, level meter
│   ├── js/script.js           validation, requests, UI states
│   ├── fonts/                 Bricolage Grotesque + Instrument Sans (SIL OFL licence files included)
│   └── images/                logo.svg, favicon.svg
├── api/
│   ├── process.php            validate, fetch, convert, create one-time link
│   └── download.php           stream a finished file by token, then delete it
├── includes/
│   ├── config.php             all settings (server-side only)
│   ├── bootstrap.php          loads everything, error handling
│   ├── helpers.php            sessions, CSRF, limits, temp files, safe process runner
│   ├── media.php              URL checks, SSRF-safe download, file inspection, FFmpeg
│   ├── providers.php          provider interface, direct links, resolver API
│   └── .htaccess              blocks direct access
├── temp/                      short-lived files (.gitkeep, .htaccess)
└── README.md
```

## Troubleshooting

| Problem | Fix |
| --- | --- |
| Blank page or HTTP 500 | Read `C:\xampp\apache\logs\error.log` and `C:\xampp\php\logs\php_error_log`. A 500 on every page can mean `.htaccess` overrides are disabled: set `AllowOverride All` for `htdocs` in `httpd.conf`. |
| "PHP cURL is not enabled" | Enable `extension=curl` in `php.ini`, restart Apache. |
| "Couldn't verify the website's certificate" | Set up `cacert.pem` (section 3). |
| Badge says "FFmpeg not found" | Set the full paths in `config.php`, or add `C:\ffmpeg\bin` to the **System** Path and restart Apache (section 4). |
| "This page has expired" | Reload the page (the session cookie was lost or the PHP session folder is not writable). |
| "Couldn't find that website" | The server has no internet access or cannot resolve DNS. |
| "Access refused" (HTTP 403) from a host | That host does not allow this download. Try a different `user_agent` only if the host's terms permit automated downloads. |
| Slow or timed-out conversions | Use shorter files or raise `convert_timeout` and `request_time_limit`. |
| "The server is busy" | Wait for the other jobs to finish, or raise `max_concurrent_jobs`. |

Developed and tested on PHP 8.3 with FFmpeg 6.1, including the full download, conversion, error and clean-up flows. The Windows-specific details (paths, PATH handling, certificates) are covered above; if something behaves differently on your XAMPP, check the two log files first.

## Legal and licence notes

Only download content you own or have permission to use. You are responsible for the sites you add to `allowed_hosts`.

The bundled fonts, **Bricolage Grotesque** and **Instrument Sans**, are licensed under the SIL Open Font License 1.1 (see `assets/fonts/LICENSE-*.txt`).
