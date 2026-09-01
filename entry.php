<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/layout.php';

require_login();

$id      = get_int('id') ?: null;
$entry   = $id !== null ? entry_find($id) : null;
$library = media_list();

if ($id !== null && $entry === null) {
    flash('error', 'Entry ' . $id . ' does not exist.');
    redirect('index.php');
}

/** Media the author can choose from, indexed by id for quick lookups. */
$libraryById = [];
foreach ($library as $item) {
    $libraryById[(int) $item['id']] = $item;
}

// --------------------------------------------------------------------------
// Default form state
// --------------------------------------------------------------------------

$form = [
    'title'       => '',
    'body'        => '',
    'body_format' => 'wrap',
    'visible'     => 0,
    'created'     => dt_to_input(null),
    'media'       => array_fill(0, MAX_MEDIA_PER_ENTRY, ''),
    'links'       => array_fill(0, MAX_LINKS_PER_ENTRY, ['url' => '', 'label' => '']),
];

if ($entry !== null) {
    $form['title']       = (string) ($entry['title'] ?? '');
    $form['body']        = (string) ($entry['body'] ?? '');
    $form['body_format'] = $entry['body_format'];
    $form['visible']     = (int) $entry['visible'];
    $form['created']     = dt_to_input($entry['created_at']);

    foreach (entry_media((int) $entry['id']) as $position => $item) {
        $form['media'][$position] = (string) $item['id'];
    }
    foreach (entry_links((int) $entry['id']) as $position => $link) {
        $form['links'][$position] = [
            'url'   => (string) $link['url'],
            'label' => (string) ($link['label'] ?? ''),
        ];
    }
}

$errors = [];

// --------------------------------------------------------------------------
// Save
// --------------------------------------------------------------------------

if (is_post()) {
    csrf_verify();

    $form['title']       = post_str('title');
    $form['body']        = (string) ($_POST['body'] ?? '');
    $form['body_format'] = post_str('body_format') === 'pre' ? 'pre' : 'wrap';
    $form['visible']     = post_int('visible') === 1 ? 1 : 0;
    $form['created']     = post_str('created');

    // Preformatted text keeps its exact bytes; wrapped text only loses stray
    // trailing whitespace on the whole field.
    $body = $form['body_format'] === 'pre'
        ? str_replace("\r\n", "\n", $form['body'])
        : trim(str_replace("\r\n", "\n", $form['body']));

    $createdUtc = dt_from_input($form['created']);
    if ($createdUtc === null) {
        $errors[] = 'Give the entry a creation date and time.';
    }

    // --- media slots ------------------------------------------------------
    $mediaIds = [];
    $duplicates = false;

    for ($slot = 0; $slot < MAX_MEDIA_PER_ENTRY; $slot++) {
        $raw = post_arr('media', $slot);
        $form['media'][$slot] = $raw;

        if ($raw === '') {
            continue;
        }

        $mediaId = (int) $raw;
        if (!isset($libraryById[$mediaId])) {
            $errors[] = 'Slot ' . ($slot + 1) . ' points at a file that is no longer in the library.';
            continue;
        }
        if (in_array($mediaId, $mediaIds, true)) {
            $duplicates = true;
            $form['media'][$slot] = '';
            continue;
        }
        $mediaIds[] = $mediaId;
    }

    // --- links ------------------------------------------------------------
    $links = [];

    for ($slot = 0; $slot < MAX_LINKS_PER_ENTRY; $slot++) {
        $url   = post_arr('link_url', $slot);
        $label = post_arr('link_label', $slot);
        $form['links'][$slot] = ['url' => $url, 'label' => $label];

        if ($url === '') {
            if ($label !== '') {
                $errors[] = 'Link ' . ($slot + 1) . ' has a label but no address.';
            }
            continue;
        }

        $clean = clean_url($url);
        if ($clean === null) {
            $errors[] = 'Link ' . ($slot + 1) . ' is not a usable web address.';
            continue;
        }

        $form['links'][$slot]['url'] = $clean;
        $links[] = ['url' => $clean, 'label' => $label !== '' ? $label : null];
    }

    // --- write ------------------------------------------------------------
    if ($errors === []) {
        $savedId = entry_save(
            $id,
            [
                'title'       => $form['title'] !== '' ? $form['title'] : null,
                'body'        => $body !== '' ? $body : null,
                'body_format' => $form['body_format'],
                'visible'     => $form['visible'],
                'created_at'  => $createdUtc,
            ],
            $mediaIds,
            $links
        );

        if ($duplicates) {
            flash('warn', 'The same file was picked more than once, so the repeats were cleared.');
        }
        flash('ok', $id === null
            ? 'Entry ' . $savedId . ' created.'
            : 'Entry ' . $savedId . ' saved.');

        redirect('entry.php?id=' . $savedId);
    }
}

// --------------------------------------------------------------------------
// View
// --------------------------------------------------------------------------

$isNew = $entry === null;
$title = $isNew ? 'New entry' : 'Entry ' . $entry['id'];

/** Compact library description for the slot previews. */
$previewData = [];
foreach ($library as $item) {
    $previewData[(int) $item['id']] = [
        'kind' => $item['kind'],
        'url'  => UPLOAD_URL . '/' . $item['filename'],
        'name' => $item['original_name'],
    ];
}

page_head($title, 'entries');
?>

<div class="pagehead">
  <h1><?= h($title) ?></h1>
  <a class="btn btn-quiet" href="index.php">Back to entries</a>
</div>

<?php if ($errors !== []): ?>
  <div class="note note-error" role="alert">
    <p>This entry was not saved:</p>
    <ul>
      <?php foreach ($errors as $message): ?>
        <li><?= h($message) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="entry.php<?= $isNew ? '' : '?id=' . (int) $entry['id'] ?>" class="editor">
  <?= csrf_field() ?>

  <section class="desk">
    <label class="field">
      <span class="field-label">Title <span class="hint">optional</span></span>
      <input type="text" name="title" value="<?= h($form['title']) ?>"
             maxlength="200" placeholder="Leave blank for an untitled entry">
    </label>

    <div class="field">
      <div class="field-label formatline">
        <span>Text</span>
        <span class="formatpicker" role="radiogroup" aria-label="Text formatting">
          <label>
            <input type="radio" name="body_format" value="wrap"
                   <?= $form['body_format'] === 'wrap' ? 'checked' : '' ?>>
            <span>Auto-wrapped</span>
          </label>
          <label>
            <input type="radio" name="body_format" value="pre"
                   <?= $form['body_format'] === 'pre' ? 'checked' : '' ?>>
            <span>Preformatted</span>
          </label>
        </span>
      </div>
      <textarea id="body" name="body" class="manuscript"
                data-format="<?= h($form['body_format']) ?>"
                wrap="<?= $form['body_format'] === 'pre' ? 'off' : 'soft' ?>"
                spellcheck="true" rows="24"
                placeholder="Write here. Auto-wrapped text flows to the reader's window; preformatted text keeps every space and line break."><?= "\n" . h($form['body']) ?></textarea>
      <p class="hint hint-block" id="formathint"></p>
    </div>
  </section>

  <aside class="rail">
    <section class="panel">
      <h2>Publishing</h2>

      <div class="field">
        <span class="field-label">Visibility</span>
        <div class="choices">
          <label class="choice">
            <input type="radio" name="visible" value="0" <?= $form['visible'] === 0 ? 'checked' : '' ?>>
            <span>Draft, only you can see it</span>
          </label>
          <label class="choice">
            <input type="radio" name="visible" value="1" <?= $form['visible'] === 1 ? 'checked' : '' ?>>
            <span>Public, anyone can read it</span>
          </label>
        </div>
      </div>

      <label class="field">
        <span class="field-label">Created</span>
        <input type="datetime-local" name="created" value="<?= h($form['created']) ?>" required>
      </label>

      <?php if (!$isNew): ?>
        <p class="stamp">Last updated <?= h(fmt_datetime($entry['updated_at'], 'j M Y, H:i')) ?></p>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Media</h2>
      <?php if ($library === []): ?>
        <p class="empty-note">
          The library is empty. <a href="media.php">Upload a file</a> and it will show up here.
        </p>
      <?php else: ?>
        <p class="panel-note">Up to <?= MAX_MEDIA_PER_ENTRY ?> files, shown in this order.</p>
        <?php for ($slot = 0; $slot < MAX_MEDIA_PER_ENTRY; $slot++): ?>
          <div class="slot">
            <label class="field">
              <span class="field-label">Slot <?= $slot + 1 ?></span>
              <select name="media[<?= $slot ?>]" class="media-picker" data-slot="<?= $slot ?>">
                <option value="">Nothing</option>
                <?php foreach (MEDIA_KINDS as $kind => $groupLabel): ?>
                  <?php $group = array_filter($library, fn($m) => $m['kind'] === $kind); ?>
                  <?php if ($group !== []): ?>
                    <optgroup label="<?= h($groupLabel) ?>">
                      <?php foreach ($group as $item): ?>
                        <option value="<?= (int) $item['id'] ?>"
                          <?= $form['media'][$slot] === (string) $item['id'] ? 'selected' : '' ?>>
                          <?= h(excerpt($item['original_name'], 44)) ?>
                        </option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="slot-preview" data-preview="<?= $slot ?>"></div>
          </div>
        <?php endfor; ?>
        <p class="panel-note"><a href="media.php">Manage the library</a></p>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Links</h2>
      <p class="panel-note">Up to <?= MAX_LINKS_PER_ENTRY ?> addresses elsewhere on the web.</p>
      <?php for ($slot = 0; $slot < MAX_LINKS_PER_ENTRY; $slot++): ?>
        <div class="linkrow">
          <label class="field">
            <span class="field-label">Link <?= $slot + 1 ?></span>
            <input type="url" name="link_url[<?= $slot ?>]"
                   value="<?= h($form['links'][$slot]['url']) ?>"
                   placeholder="https://example.com/page" inputmode="url" spellcheck="false">
          </label>
          <label class="field">
            <span class="visually-hidden">Link <?= $slot + 1 ?> text</span>
            <input type="text" name="link_label[<?= $slot ?>]"
                   value="<?= h($form['links'][$slot]['label']) ?>"
                   maxlength="120" placeholder="Link text, optional">
          </label>
        </div>
      <?php endfor; ?>
    </section>
  </aside>

  <div class="deskfoot">
    <button type="submit" class="btn btn-primary">
      <?= $isNew ? 'Create entry' : 'Save changes' ?>
    </button>
    <a class="btn btn-quiet" href="index.php">Cancel</a>
  </div>
</form>

<?php if (!$isNew): ?>
  <form method="post" action="index.php" class="dangerzone"
        onsubmit="return confirm('Delete this entry for good? This cannot be undone.');">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="btn btn-danger">Delete this entry</button>
    <span class="hint">The attached files stay in the media library.</span>
  </form>
<?php endif; ?>

<script>
(function () {
  var library = <?= json_encode($previewData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  // Preview whatever each slot currently points at.
  function drawPreview(select) {
    var target = document.querySelector('[data-preview="' + select.dataset.slot + '"]');
    if (!target) { return; }

    target.textContent = '';
    var item = library[select.value];
    if (!item) { return; }

    var node;
    if (item.kind === 'image') {
      node = document.createElement('img');
      node.src = item.url;
      node.alt = '';
      node.loading = 'lazy';
    } else {
      node = document.createElement(item.kind === 'audio' ? 'audio' : 'video');
      node.src = item.url;
      node.controls = true;
      node.preload = 'none';
    }
    target.appendChild(node);
  }

  document.querySelectorAll('.media-picker').forEach(function (select) {
    drawPreview(select);
    select.addEventListener('change', function () { drawPreview(select); });
  });

  // The writing surface mirrors the format the reader will get.
  var body = document.getElementById('body');
  var hint = document.getElementById('formathint');
  var hints = {
    wrap: 'Lines flow to fit the reader\u2019s window. Blank lines separate paragraphs.',
    pre:  'Every space and line break is kept exactly as typed.'
  };

  function applyFormat(value) {
    body.dataset.format = value;
    body.setAttribute('wrap', value === 'pre' ? 'off' : 'soft');
    hint.textContent = hints[value];
  }

  document.querySelectorAll('input[name="body_format"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (radio.checked) { applyFormat(radio.value); }
    });
  });

  applyFormat(body.dataset.format);
})();
</script>

<?php page_foot(); ?>
