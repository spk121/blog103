<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/layout.php';

/** Only allow redirects back to our own admin pages. */
function safe_next(string $candidate): string
{
    $path = parse_url($candidate, PHP_URL_PATH) ?? '';
    $file = basename((string) $path);
    $allowed = ['index.php', 'entry.php', 'media.php'];

    if (!in_array($file, $allowed, true)) {
        return 'index.php';
    }

    $query = parse_url($candidate, PHP_URL_QUERY);
    return $file . ($query ? '?' . $query : '');
}

$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php'));

if (auth_user() !== null) {
    redirect($next);
}

$error = '';
$username = '';
$lockedFor = throttle_seconds_remaining();

if (is_post() && $lockedFor === 0) {
    csrf_verify();

    $username = post_str('username');
    $password = (string) ($_POST['password'] ?? '');

    if (!auth_is_configured()) {
        $error = 'No account exists yet. Run "php setup.php" on the server to create one.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter both a username and a password.';
    } elseif (auth_attempt($username, $password)) {
        throttle_clear();
        redirect($next);
    } else {
        throttle_record_failure();
        $lockedFor = throttle_seconds_remaining();
        $error = $lockedFor > 0
            ? 'Too many failed attempts. Try again in ' . (int) ceil($lockedFor / 60) . ' minutes.'
            : 'That username and password do not match.';
    }
}

page_head('Sign in', '', false);
?>
<div class="signin">
  <h1>Sign in</h1>

  <?php if (!auth_is_configured()): ?>
    <p class="note note-warn">
      No account has been set up. On the server, run <code>php setup.php</code> from the
      blog directory to choose a username and password.
    </p>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <p class="note note-error" role="alert"><?= h($error) ?></p>
  <?php endif; ?>

  <?php if ($lockedFor > 0): ?>
    <p class="note note-warn">
      Sign-in is paused for <?= (int) ceil($lockedFor / 60) ?> more minutes.
    </p>
  <?php else: ?>
    <form method="post" action="login.php" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">

      <label class="field">
        <span class="field-label">Username</span>
        <input type="text" name="username" value="<?= h($username) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required>
      </label>

      <label class="field">
        <span class="field-label">Password</span>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>

      <button type="submit" class="btn btn-primary btn-wide">Sign in</button>
    </form>
  <?php endif; ?>
</div>
<?php page_foot(); ?>
