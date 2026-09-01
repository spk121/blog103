<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/layout.php';

require_login();

// --------------------------------------------------------------------------
// Actions
// --------------------------------------------------------------------------

if (is_post()) {
    csrf_verify();

    $action = post_str('action');
    $id     = post_int('id');
    $entry  = $id > 0 ? entry_find($id) : null;

    if ($entry === null) {
        flash('error', 'That entry no longer exists.');
        redirect('index.php');
    }

    $name = $entry['title'] !== null && $entry['title'] !== ''
        ? '“' . $entry['title'] . '”'
        : 'Entry ' . $entry['id'];

    switch ($action) {
        case 'publish':
            entry_set_visibility($id, true);
            flash('ok', $name . ' is now public.');
            break;

        case 'hide':
            entry_set_visibility($id, false);
            flash('ok', $name . ' is back to a draft.');
            break;

        case 'delete':
            entry_delete($id);
            flash('ok', $name . ' was deleted. Its media files are still in the library.');
            break;

        default:
            flash('error', 'Unknown action.');
    }

    redirect('index.php?filter=' . urlencode(post_str('filter', 'all')));
}

// --------------------------------------------------------------------------
// View
// --------------------------------------------------------------------------

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'public', 'draft'], true)) {
    $filter = 'all';
}

$entries = entry_list($filter);
$counts  = entry_counts();

page_head('Entries', 'entries');
?>

<div class="pagehead">
  <h1>Entries</h1>
  <div class="headactions">
    <a class="btn btn-quiet" href="publish.php">Publish to gopher</a>
    <a class="btn btn-primary" href="entry.php">Write an entry</a>
  </div>
</div>

<nav class="filters">
  <a href="?filter=all"<?= $filter === 'all' ? ' aria-current="page"' : '' ?>>All <span class="tally"><?= $counts['total'] ?></span></a>
  <a href="?filter=public"<?= $filter === 'public' ? ' aria-current="page"' : '' ?>>Public <span class="tally"><?= $counts['live'] ?></span></a>
  <a href="?filter=draft"<?= $filter === 'draft' ? ' aria-current="page"' : '' ?>>Drafts <span class="tally"><?= $counts['drafts'] ?></span></a>
</nav>

<?php if ($entries === []): ?>
  <div class="empty">
    <p><?= $filter === 'all'
        ? 'Nothing written yet.'
        : 'No entries match this filter.' ?></p>
    <a class="btn btn-primary" href="entry.php">Write the first entry</a>
  </div>
<?php else: ?>
  <table class="ledger">
    <thead>
      <tr>
        <th scope="col" class="col-id">#</th>
        <th scope="col">Title</th>
        <th scope="col" class="col-status">Status</th>
        <th scope="col" class="col-attach">Attached</th>
        <th scope="col" class="col-date">Created</th>
        <th scope="col" class="col-date">Updated</th>
        <th scope="col" class="col-actions"><span class="visually-hidden">Actions</span></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($entries as $entry):
        $id      = (int) $entry['id'];
        $visible = (int) $entry['visible'] === 1;
        $title   = (string) ($entry['title'] ?? '');
        $preview = excerpt($entry['body'], 80);
    ?>
      <tr>
        <td class="col-id"><?= $id ?></td>
        <td>
          <a class="entry-title" href="entry.php?id=<?= $id ?>">
            <?= $title !== '' ? h($title) : '<em>Untitled</em>' ?>
          </a>
          <?php if ($preview !== ''): ?>
            <span class="entry-preview"><?= h($preview) ?></span>
          <?php endif; ?>
        </td>
        <td class="col-status">
          <span class="badge badge-<?= $visible ? 'live' : 'draft' ?>">
            <?= $visible ? 'Public' : 'Draft' ?>
          </span>
        </td>
        <td class="col-attach">
          <?php
            $bits = [];
            if ((int) $entry['media_count'] > 0) {
                $bits[] = $entry['media_count'] . ' media';
            }
            if ((int) $entry['link_count'] > 0) {
                $bits[] = $entry['link_count'] . ' link' . ((int) $entry['link_count'] === 1 ? '' : 's');
            }
            echo $bits === [] ? '<span class="muted">—</span>' : h(implode(', ', $bits));
          ?>
        </td>
        <td class="col-date" data-label="Created"><?= h(fmt_datetime($entry['created_at'])) ?></td>
        <td class="col-date" data-label="Updated"><?= h(fmt_datetime($entry['updated_at'])) ?></td>
        <td class="col-actions">
          <div class="rowactions">
            <a class="btn btn-quiet" href="entry.php?id=<?= $id ?>">Edit</a>

            <form method="post" action="index.php">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="filter" value="<?= h($filter) ?>">
              <input type="hidden" name="action" value="<?= $visible ? 'hide' : 'publish' ?>">
              <button type="submit" class="btn btn-quiet">
                <?= $visible ? 'Hide' : 'Publish' ?>
              </button>
            </form>

            <form method="post" action="index.php"
                  onsubmit="return confirm(<?= h(json_encode(
                      'Delete ' . ($title !== '' ? $title : 'entry ' . $id)
                      . ' for good? This cannot be undone.'
                  )) ?>);">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="filter" value="<?= h($filter) ?>">
              <input type="hidden" name="action" value="delete">
              <button type="submit" class="btn btn-danger">Delete</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_foot(); ?>
