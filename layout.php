<?php
declare(strict_types=1);

/**
 * Shared page chrome. Call page_head() before any output and page_foot() last.
 */

function page_head(string $title, string $currentNav = '', bool $showNav = true): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');

    $nav = [
        'entries' => ['index.php', 'Entries'],
        'media'   => ['media.php', 'Media'],
        'publish' => ['publish.php', 'Publish'],
    ];
    $author = auth_user();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<?php if ($showNav && $author !== null): ?>
<header class="topbar">
  <a class="wordmark" href="index.php"><?= h(APP_NAME) ?></a>
  <nav class="topnav">
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= h($href) ?>"<?= $currentNav === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="signout" method="post" action="logout.php">
    <?= csrf_field() ?>
    <span class="whoami"><?= h($author) ?></span>
    <button type="submit" class="btn btn-quiet">Sign out</button>
  </form>
</header>
<?php endif; ?>
<main class="shell">
<?php
    foreach (take_flashes() as $message) {
        $type = in_array($message['type'], ['ok', 'warn', 'error'], true) ? $message['type'] : 'ok';
        echo '<p class="note note-' . h($type) . '" role="status">' . h($message['message']) . "</p>\n";
    }
}

function page_foot(): void
{
    ?>
</main>
</body>
</html>
<?php
}

/** Page title block with an optional action button on the right. */
function page_title(string $title, string $actionHref = '', string $actionLabel = ''): void
{
    echo '<div class="pagehead"><h1>' . h($title) . '</h1>';
    if ($actionHref !== '' && $actionLabel !== '') {
        echo '<a class="btn btn-primary" href="' . h($actionHref) . '">' . h($actionLabel) . '</a>';
    }
    echo "</div>\n";
}
