<?php
declare(strict_types=1);

/**
 * Small shared utilities: output escaping, CSRF, flash messages,
 * date conversion between UTC storage and local display.
 */

// --------------------------------------------------------------------------
// Output
// --------------------------------------------------------------------------

/** Escape for HTML text and attribute contexts. */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// --------------------------------------------------------------------------
// Request input
// --------------------------------------------------------------------------

/** Trimmed string from POST. Arrays and other non-scalars become ''. */
function post_str(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? null;
    return is_string($value) ? trim($value) : $default;
}

/** Trimmed string from an array-valued POST field, e.g. link_url[2]. */
function post_arr(string $key, int|string $index, string $default = ''): string
{
    $value = $_POST[$key][$index] ?? null;
    return is_string($value) ? trim($value) : $default;
}

function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;
    return is_scalar($value) ? (int) $value : $default;
}

function get_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? null;
    return is_scalar($value) ? (int) $value : $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// --------------------------------------------------------------------------
// CSRF
// --------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Abort the request unless the POST carries the session's token. */
function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('That form expired. Go back, reload the page and try again.');
    }
}

// --------------------------------------------------------------------------
// Flash messages
// --------------------------------------------------------------------------

/** $type is one of: ok, warn, error. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

// --------------------------------------------------------------------------
// Dates: stored as 'Y-m-d H:i:s' in UTC, shown in APP_TIMEZONE
// --------------------------------------------------------------------------

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function fmt_datetime(?string $utc, string $format = 'j M Y, H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    } catch (Exception) {
        return '—';
    }
    return $dt->setTimezone(new DateTimeZone(APP_TIMEZONE))->format($format);
}

/** Format a stored UTC timestamp for a <input type="datetime-local"> value. */
function dt_to_input(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d\TH:i');
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    } catch (Exception) {
        return '';
    }
    return $dt->setTimezone(new DateTimeZone(APP_TIMEZONE))->format('Y-m-d\TH:i');
}

/** Parse a datetime-local value (local time) back into a UTC storage string. */
function dt_from_input(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $zone = new DateTimeZone(APP_TIMEZONE);
    foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value, $zone);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
    }
    return null;
}

// --------------------------------------------------------------------------
// UTF-8 aware string handling (mbstring is used when present, not required)
// --------------------------------------------------------------------------

function u_strlen(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }
    $count = preg_match_all('/./us', $text);
    return $count === false ? strlen($text) : $count;
}

function u_substr(string $text, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, $start, $length, 'UTF-8');
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }

    $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
    return implode('', $slice);
}

// --------------------------------------------------------------------------
// Misc
// --------------------------------------------------------------------------

function fmt_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    $unit  = 'KB';
    foreach ($units as $candidate) {
        $unit = $candidate;
        if ($value < 1024) {
            break;
        }
        $value /= 1024;
    }
    return round($value, $value < 10 ? 1 : 0) . ' ' . $unit;
}

/** Accept only absolute http(s) URLs. Returns null if the URL is unusable. */
function clean_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $host = parse_url($url, PHP_URL_HOST);
    return ($host === null || $host === false || $host === '') ? null : $url;
}

/** Shorten a string for table display without cutting mid-entity. */
function excerpt(?string $text, int $length = 90): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    if ($text === '') {
        return '';
    }
    return u_strlen($text) <= $length ? $text : u_substr($text, 0, $length - 1) . '…';
}
