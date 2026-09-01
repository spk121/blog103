<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/gopher.php';
require APP_ROOT . '/layout.php';

require_login();

$report = null;
$error  = '';

if (is_post()) {
    csrf_verify();

    try {
        $report = gopher_publish();
        flash('ok', 'Published ' . $report['entries']
            . ($report['entries'] === 1 ? ' entry' : ' entries')
            . ' to ' . $report['path'] . '.');
        redirect('publish.php?done=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$preview = gopher_preview();
$last    = gopher_last_publish();
$counts  = entry_counts();

page_head('Publish', 'publish');
page_title('Publish to gopher');
?>

<?php if ($error !== ''): ?>
  <p class="note note-error" role="alert">Nothing was published: <?= h($error) ?></p>
<?php endif; ?>

<?php if ($last !== null && !empty($last['warnings'])): ?>
  <div class="note note-warn">
    <p>The last publish finished with notes:</p>
    <ul>
      <?php foreach ($last['warnings'] as $warning): ?>
        <li><?= h((string) $warning) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="publish">
  <section class="panel panel-wide">
    <h2>What will be published</h2>

    <dl class="facts">
      <dt>Public entries</dt>
      <dd><?= (int) $preview['entries'] ?></dd>

      <dt>Menu pages</dt>
      <dd><?= (int) $preview['pages'] ?> at <?= GOPHER_ENTRIES_PER_PAGE ?> entries each</dd>

      <dt>Text files</dt>
      <dd><?= (int) $preview['text'] ?></dd>

      <dt>Attached files</dt>
      <dd><?= (int) $preview['media'] ?></dd>

      <dt>Written to</dt>
      <dd><code><?= h(GOPHER_DIR) ?></code></dd>

      <dt>Served as</dt>
      <dd><code>gopher://<?= h(GOPHER_HOST) ?>:<?= GOPHER_PORT ?><?= h(gopher_page_selector(1)) ?></code></dd>
    </dl>

    <?php if ($counts['drafts'] > 0): ?>
      <p class="panel-note">
        <?= $counts['drafts'] ?> <?= $counts['drafts'] === 1 ? 'draft stays' : 'drafts stay' ?>
        behind, along with any files attached only to <?= $counts['drafts'] === 1 ? 'it' : 'them' ?>.
      </p>
    <?php endif; ?>

    <form method="post" action="publish.php">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary">Publish the site</button>
    </form>

    <p class="panel-note">
      The site is rebuilt from scratch each time, then swapped into place, so
      anything deleted here disappears from gopher on the next publish.
    </p>
  </section>

  <section class="panel panel-wide">
    <h2>Last publish</h2>

    <?php if ($last === null): ?>
      <p class="empty-note">Not published yet.</p>
    <?php else: ?>
      <dl class="facts">
        <dt>When</dt>
        <dd><?= h(fmt_datetime($last['at'] ?? null)) ?></dd>

        <dt>Entries</dt>
        <dd><?= (int) ($last['entries'] ?? 0) ?> across <?= (int) ($last['pages'] ?? 0) ?>
            <?= (int) ($last['pages'] ?? 0) === 1 ? 'page' : 'pages' ?></dd>

        <dt>Files written</dt>
        <dd><?= (int) ($last['text_files'] ?? 0) ?> text,
            <?= (int) ($last['media_files'] ?? 0) ?> media,
            <?= h(fmt_bytes((int) ($last['bytes'] ?? 0))) ?> in total</dd>
      </dl>
    <?php endif; ?>
  </section>
</div>

<?php page_foot(); ?>
