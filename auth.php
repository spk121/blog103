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

    // Create the temp file owner-only from the first byte. Removing any stale
    // copy first matters: rewriting an existing file keeps its old mode.
    $temp = AUTH_FILE . '.tmp';
    @unlink($temp);

    $oldUmask = umask(0077);
    try {
        $written = file_put_contents($temp, $payload . "\n", LOCK_EX);
    } finally {
        umask($oldUmask);
    }

    if ($written === false || !chmod($temp, 0600)) {
        @unlink($temp);
        throw new RuntimeException('Cannot write ' . AUTH_FILE);
    }
    if (!rename($temp, AUTH_FILE)) {
        @unlink($temp);
        throw new RuntimeException('Cannot replace ' . AUTH_FILE);
    }
}

// --------------------------------------------------------------------------
// Login throttling (per server, not per IP: there is only one account)
//
// Every attempt is counted *before* the password is checked, under an
// exclusive lock, and a successful sign-in clears the count. If the throttle
// file cannot be read or written, an exception is thrown and the password is
// never checked, so a broken throttle refuses sign-ins instead of silently
// allowing unlimited guesses.
// --------------------------------------------------------------------------

function throttle_decode(string|false $raw): array
{
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'failures'     => (int) (is_array($data) ? ($data['failures'] ?? 0) : 0),
        'locked_until' => (int) (is_array($data) ? ($data['locked_until'] ?? 0) : 0),
    ];
}

/**
 * Open the throttle file, lock it, and pass the current state to $change.
 * Whatever $change returns is written back (null means leave it as is).
 */
function throttle_locked(int $lock, ?callable $change): array
{
    $handle = @fopen(THROTTLE_FILE, $change === null ? 'r' : 'c+');
    if ($handle === false) {
        if ($change === null && !file_exists(THROTTLE_FILE)) {
            return throttle_decode(false);   // nothing recorded yet
        }
        throw new RuntimeException('Cannot open ' . THROTTLE_FILE);
    }

    try {
        if (!flock($handle, $lock)) {
            throw new RuntimeException('Cannot lock ' . THROTTLE_FILE);
        }

        $raw = stream_get_contents($handle);
        if ($raw === false) {
            throw new RuntimeException('Cannot read ' . THROTTLE_FILE);
        }
        $state = throttle_decode($raw);
        $next  = $change === null ? null : $change($state);

        if ($next !== null && $next !== $state) {
            $json = json_encode($next, JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || !rewind($handle)
                || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Cannot write ' . THROTTLE_FILE);
            }
            $state = $next;
        }

        flock($handle, LOCK_UN);
        return $state;
    } finally {
        fclose($handle);
    }
}

/** Seconds remaining on a lockout, or 0 if login is currently allowed. */
function throttle_seconds_remaining(): int
{
    $remaining = throttle_locked(LOCK_SH, null)['locked_until'] - time();
    return $remaining > 0 ? $remaining : 0;
}

/**
 * Count a sign-in attempt. Returns false, without counting, while a lockout
 * is running. The attempt that reaches LOGIN_MAX_FAILURES arms the lockout;
 * if that attempt succeeds, throttle_clear() disarms it again.
 */
function throttle_begin_attempt(): bool
{
    $allowed = false;

    throttle_locked(LOCK_EX, static function (array $state) use (&$allowed): array {
        if ($state['locked_until'] > time()) {
            return $state;
        }
        $allowed = true;
        $state['failures']++;
        if ($state['failures'] >= LOGIN_MAX_FAILURES) {
            $state['locked_until'] = time() + LOGIN_LOCKOUT_SECONDS;
            $state['failures'] = 0;
        }
        return $state;
    });

    return $allowed;
}

function throttle_clear(): void
{
    throttle_locked(LOCK_EX, static fn(): array => ['failures' => 0, 'locked_until' => 0]);
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
    unset($_SESSION['csrf']);          // a fresh token for the signed-in session
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
