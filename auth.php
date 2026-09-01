<?php
declare(strict_types=1);

/**
 * Single-author authentication.
 *
 * Credentials live in one JSON file (AUTH_FILE), created by setup.php:
 *   {"username": "...", "hash": "$2y$...", "updated_at": "..."}
 *
 * There is no user table and no registration page by design.
 */

// --------------------------------------------------------------------------
// Credentials file
// --------------------------------------------------------------------------

/** @return array{username: string, hash: string}|null */
function auth_credentials(): ?array
{
    if (!is_readable(AUTH_FILE)) {
        return null;
    }

    $raw = file_get_contents(AUTH_FILE);
    if ($raw === false) {
        return null;
    }

    $data = json_decode(trim($raw), true);
    if (!is_array($data) || empty($data['username']) || empty($data['hash'])) {
        return null;
    }

    return ['username' => (string) $data['username'], 'hash' => (string) $data['hash']];
}

function auth_is_configured(): bool
{
    return auth_credentials() !== null;
}

/** Write the credentials file with restrictive permissions. */
function auth_write_credentials(string $username, string $passwordHash): void
{
    $payload = json_encode(
        ['username' => $username, 'hash' => $passwordHash, 'updated_at' => now_utc()],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    $temp = AUTH_FILE . '.tmp';
    if (file_put_contents($temp, $payload . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write ' . AUTH_FILE);
    }
    @chmod($temp, 0600);
    if (!rename($temp, AUTH_FILE)) {
        @unlink($temp);
        throw new RuntimeException('Cannot replace ' . AUTH_FILE);
    }
}

// --------------------------------------------------------------------------
// Login throttling (per server, not per IP: there is only one account)
// --------------------------------------------------------------------------

function throttle_read(): array
{
    if (!is_readable(THROTTLE_FILE)) {
        return ['failures' => 0, 'locked_until' => 0];
    }
    $data = json_decode((string) file_get_contents(THROTTLE_FILE), true);
    return [
        'failures'     => (int) ($data['failures'] ?? 0),
        'locked_until' => (int) ($data['locked_until'] ?? 0),
    ];
}

function throttle_write(array $state): void
{
    @file_put_contents(THROTTLE_FILE, json_encode($state), LOCK_EX);
}

/** Seconds remaining on a lockout, or 0 if login is currently allowed. */
function throttle_seconds_remaining(): int
{
    $state = throttle_read();
    $remaining = $state['locked_until'] - time();
    return $remaining > 0 ? $remaining : 0;
}

function throttle_record_failure(): void
{
    $state = throttle_read();
    $state['failures']++;

    if ($state['failures'] >= LOGIN_MAX_FAILURES) {
        $state['locked_until'] = time() + LOGIN_LOCKOUT_SECONDS;
        $state['failures'] = 0;
    }
    throttle_write($state);
}

function throttle_clear(): void
{
    throttle_write(['failures' => 0, 'locked_until' => 0]);
}

// --------------------------------------------------------------------------
// Session
// --------------------------------------------------------------------------

/** Verify a username/password pair and start an authenticated session. */
function auth_attempt(string $username, string $password): bool
{
    $credentials = auth_credentials();
    if ($credentials === null) {
        return false;
    }

    $userOk = hash_equals($credentials['username'], $username);
    $passOk = password_verify($password, $credentials['hash']);

    // Compare both regardless of the first result, then require both.
    if (!$userOk || !$passOk) {
        usleep(random_int(150000, 400000));
        return false;
    }

    if (password_needs_rehash($credentials['hash'], PASSWORD_DEFAULT)) {
        auth_write_credentials($credentials['username'], password_hash($password, PASSWORD_DEFAULT));
    }

    session_regenerate_id(true);
    $_SESSION['author']     = $credentials['username'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_seen']  = time();

    return true;
}

function auth_user(): ?string
{
    if (empty($_SESSION['author'])) {
        return null;
    }

    $lastSeen = (int) ($_SESSION['last_seen'] ?? 0);
    if (time() - $lastSeen > SESSION_IDLE_SECONDS) {
        auth_logout();
        return null;
    }

    $_SESSION['last_seen'] = time();
    return (string) $_SESSION['author'];
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/** Guard for every admin page. Sends unauthenticated visitors to the login form. */
function require_login(): void
{
    if (auth_user() !== null) {
        return;
    }

    $target = $_SERVER['REQUEST_URI'] ?? '';
    $query  = ($target !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')
        ? '?next=' . urlencode($target)
        : '';

    redirect('login.php' . $query);
}
