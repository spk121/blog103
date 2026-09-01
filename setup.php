<?php
declare(strict_types=1);

/**
 * Create or replace the author's credentials. Command line only.
 *
 *   php setup.php                      prompts for username and password
 *   php setup.php alice 'passphrase'   non-interactive (leaks to shell history)
 *
 * Run it again at any time to change the username or password.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("setup.php runs from the command line only.\n");
}

require __DIR__ . '/config.php';

function prompt(string $question, bool $hidden = false): string
{
    fwrite(STDOUT, $question);

    if (!$hidden || !function_exists('shell_exec') || stripos(PHP_OS_FAMILY, 'win') === 0) {
        $answer = fgets(STDIN);
        return $answer === false ? '' : trim($answer);
    }

    shell_exec('stty -echo 2>/dev/null');
    $answer = fgets(STDIN);
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");

    return $answer === false ? '' : trim($answer);
}

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';

if ($username === '') {
    $existing = auth_credentials();
    if ($existing !== null) {
        fwrite(STDOUT, "An account already exists for '{$existing['username']}'. Continuing replaces it.\n");
    }
    $username = prompt('Username: ');
}

if ($password === '') {
    $password = prompt('Password: ', true);
    $confirm  = prompt('Password again: ', true);
    if ($password !== $confirm) {
        fwrite(STDERR, "Those passwords do not match. Nothing was changed.\n");
        exit(1);
    }
}

$username = trim($username);

if ($username === '' || !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $username)) {
    fwrite(STDERR, "Use 1-64 characters: letters, digits, dot, underscore, hyphen or @.\n");
    exit(1);
}

if (strlen($password) < 10) {
    fwrite(STDERR, "Use a password of at least 10 characters.\n");
    exit(1);
}

auth_write_credentials($username, password_hash($password, PASSWORD_DEFAULT));
throttle_clear();

// Touch the database so the schema exists before the first page load.
db();

fwrite(STDOUT, "Saved. Sign in as '$username' at login.php.\n");
fwrite(STDOUT, 'Credentials: ' . AUTH_FILE . "\n");
fwrite(STDOUT, 'Database:    ' . DB_FILE . "\n");
