<?php
declare(strict_types=1);

/**
 * Configuration and bootstrap. Every admin page includes this first.
 *
 * Requires PHP 8.1+ with pdo_sqlite, fileinfo and session.
 */

// --------------------------------------------------------------------------
// Site settings
// --------------------------------------------------------------------------

const APP_NAME = 'Blog admin';

/** Times are stored as UTC and shown/entered in this zone. */
const APP_TIMEZONE = 'America/Los_Angeles';

// --------------------------------------------------------------------------
// Paths
// --------------------------------------------------------------------------

define('APP_ROOT', __DIR__);

/** Database + credentials. Move this outside the web root if your host allows. */
define('DATA_DIR', APP_ROOT . '/data');

/** Media files. Must stay web-readable so the public blog can serve them. */
define('UPLOAD_DIR', APP_ROOT . '/uploads');

/** URL path corresponding to UPLOAD_DIR, relative to the admin pages. */
define('UPLOAD_URL', 'uploads');

define('DB_FILE', DATA_DIR . '/blog.sqlite');
define('AUTH_FILE', DATA_DIR . '/author.auth');
define('THROTTLE_FILE', DATA_DIR . '/login-throttle.json');

// --------------------------------------------------------------------------
// Limits
// --------------------------------------------------------------------------

const MAX_MEDIA_PER_ENTRY = 4;
const MAX_LINKS_PER_ENTRY = 4;
const MAX_UPLOAD_BYTES = 33554432;      // 32 MB per file
const SESSION_IDLE_SECONDS = 28800;     // 8 hours
const LOGIN_MAX_FAILURES = 8;
const LOGIN_LOCKOUT_SECONDS = 900;      // 15 minutes

/**
 * Upload whitelist, keyed by the MIME type that fileinfo reports.
 * Value is [kind, canonical extension]. Anything not listed is rejected.
 *
 * Note: fileinfo usually reports .m4a audio as video/mp4, so an m4a file will
 * be filed under video. Add a byte-level check here if that matters to you.
 */
const ALLOWED_UPLOAD_TYPES = [
    'image/jpeg'      => ['image', 'jpg'],
    'image/png'       => ['image', 'png'],
    'image/gif'       => ['image', 'gif'],
    'image/webp'      => ['image', 'webp'],
    'image/avif'      => ['image', 'avif'],
    'audio/mpeg'      => ['audio', 'mp3'],
    'audio/ogg'       => ['audio', 'ogg'],
    'audio/wav'       => ['audio', 'wav'],
    'audio/x-wav'     => ['audio', 'wav'],
    'audio/flac'      => ['audio', 'flac'],
    'audio/x-flac'    => ['audio', 'flac'],
    'audio/mp4'       => ['audio', 'm4a'],
    'video/mp4'       => ['video', 'mp4'],
    'video/webm'      => ['video', 'webm'],
    'video/ogg'       => ['video', 'ogv'],
    'video/quicktime' => ['video', 'mov'],
];

const MEDIA_KINDS = ['image' => 'Images', 'audio' => 'Sound', 'video' => 'Video'];

// --------------------------------------------------------------------------
// Gopher output
// --------------------------------------------------------------------------

/**
 * Where the rendered gopher site is written. Publishing creates a
 * <GOPHER_DIR>/releases/<token> directory per run and atomically swaps the
 * <GOPHER_DIR>/current symlink onto it, so GOPHER_DIR itself is never
 * renamed and can safely be a bind mount or Docker volume. Point your
 * gopher server at <GOPHER_DIR>/current, which always resolves to the latest
 * publish.
 */
define('GOPHER_DIR', APP_ROOT . '/gopher');

/** Host and port your gopher server answers on. These are written into every menu line. */
const GOPHER_HOST = 'localhost';
const GOPHER_PORT = 70;

/**
 * Selector prefix, if the blog does not sit at the gopher root.
 * '' means the blog is the root; '/blog' means selectors read /blog/entries/...
 */
const GOPHER_SELECTOR_BASE = '';

/** Heading printed at the top of every menu. */
const GOPHER_TITLE = 'A gopher blog';

const GOPHER_ENTRIES_PER_PAGE  = 30;
const GOPHER_WRAP_COLUMNS      = 72;   // auto-wrapped entries are rewrapped to this
const GOPHER_LINK_PREVIEW_CHARS = 40;  // characters of the text file shown in its menu line
const GOPHER_DISPLAY_WIDTH     = 70;   // menu display strings are truncated to this
const GOPHER_DATE_FORMAT       = 'Y-m-d H:i';

/**
 * Write text files with CRLF line endings, which is what RFC 1436 specifies.
 * Set false if your server converts line endings itself and you end up with
 * doubled carriage returns.
 */
const GOPHER_TEXT_CRLF = true;

/** Gopher item type per media kind. */
const GOPHER_MEDIA_TYPES = ['image' => 'I', 'audio' => 's', 'video' => ';'];

// --------------------------------------------------------------------------
// Bootstrap
// --------------------------------------------------------------------------

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
date_default_timezone_set('UTC');   // all internal date maths is UTC

/**
 * Create a directory if missing, and drop in an .htaccess guard.
 * $guard: 'deny' blocks all web access, 'nophp' blocks script execution only.
 */
function ensure_dir(string $dir, string $guard = ''): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create directory: ' . $dir);
    }

    $htaccess = $dir . '/.htaccess';
    if ($guard === '' || file_exists($htaccess)) {
        return;
    }

    $rules = $guard === 'deny'
        ? "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
        : "# Never execute anything in here as a script.\n"
        . "<FilesMatch \"\\.(?i:ph(p[0-9]?|tml|ar)|cgi|pl|py|s?html)$\">\n"
        . "  Require all denied\n</FilesMatch>\n"
        . "RemoveHandler .php .phtml .phar .cgi .pl .py\n";

    @file_put_contents($htaccess, $rules);
}

ensure_dir(DATA_DIR, 'deny');
ensure_dir(UPLOAD_DIR, 'nophp');

if (session_status() === PHP_SESSION_NONE) {
    session_name('blogadmin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once APP_ROOT . '/helpers.php';
require_once APP_ROOT . '/db.php';
require_once APP_ROOT . '/auth.php';
