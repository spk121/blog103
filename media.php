<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/layout.php';

require_login();

/** Human wording for PHP's upload error codes. */
function upload_error_message(int $code, string $name): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            $name . ' is larger than the server accepts (' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_PARTIAL   => $name . ' only uploaded part way. Try again.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
            $name . ' could not be written on the server. Check the temp and uploads directories.',
        UPLOAD_ERR_EXTENSION => $name . ' was blocked by a server extension.',
        default              => $name . ' could not be uploaded.',
    };
}

/** Strip control characters and over-long names before storing them. */
function tidy_filename(string $name): string
{
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim(basename($name));
    return $name === '' ? 'untitled' : u_substr($name, 0, 180);
}

// --------------------------------------------------------------------------
// Actions
// --------------------------------------------------------------------------

// A POST bigger than post_max_size arrives with everything stripped, including
// the CSRF token, so catch that before the token check calls it a bad request.
if (is_post() && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('error', 'That upload was bigger than the server accepts in one go ('
        . ini_get('post_max_size') . ' total). Try fewer files at a time.');
    redirect('media.php');
}

if (is_post()) {
    csrf_verify();
    $action = post_str('action');

    // ---- delete ----------------------------------------------------------
    if ($action === 'delete') {
        $mediaId = post_int('id');
        $media   = $mediaId > 0 ? media_find($mediaId) : null;

        if ($media === null) {
            flash('error', 'That file is already gone.');
        } else {
            $usedBy = media_used_by($mediaId);
            media_delete($mediaId);

            $message = 'Deleted ' . $media['original_name'] . '.';
            if ($usedBy !== []) {
                $ids = implode(', ', array_map(static fn($e) => '#' . $e['id'], $usedBy));
                $message .= ' It was removed from ' . count($usedBy)
                    . (count($usedBy) === 1 ? ' entry (' : ' entries (') . $ids . ').';
            }
            flash('ok', $message);
        }
        redirect('media.php');
    }

    // ---- upload ----------------------------------------------------------
    if ($action === 'upload') {
        $uploads = $_FILES['files'] ?? null;
        $saved   = 0;
        $failed  = [];

        if (!is_array($uploads) || !isset($uploads['name']) || !is_array($uploads['name'])) {
            flash('warn', 'Choose at least one file to upload.');
            redirect('media.php');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        foreach (array_keys($uploads['name']) as $i) {
            $originalName = tidy_filename((string) $uploads['name'][$i]);
            $errorCode    = (int) $uploads['error'][$i];
            $tmpPath      = (string) $uploads['tmp_name'][$i];
            $size         = (int) $uploads['size'][$i];

            if ($errorCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errorCode !== UPLOAD_ERR_OK) {
                $failed[] = upload_error_message($errorCode, $originalName);
                continue;
            }
            if (!is_uploaded_file($tmpPath)) {
                $failed[] = $originalName . ' did not arrive as a real upload.';
                continue;
            }
            if ($size <= 0) {
                $failed[] = $originalName . ' is empty.';
                continue;
            }
            if ($size > MAX_UPLOAD_BYTES) {
                $failed[] = $originalName . ' is ' . fmt_bytes($size)
                    . ', over the ' . fmt_bytes(MAX_UPLOAD_BYTES) . ' limit.';
                continue;
            }

            // Trust the file's own bytes, never the browser-supplied type.
            $mime = (string) $finfo->file($tmpPath);
            if (!isset(ALLOWED_UPLOAD_TYPES[$mime])) {
                $failed[] = $originalName . ' is a ' . ($mime ?: 'unrecognised')
                    . ' file, which is not an accepted image, sound or video format.';
                continue;
            }

            [$kind, $extension] = ALLOWED_UPLOAD_TYPES[$mime];

            if ($kind === 'image' && @getimagesize($tmpPath) === false && $mime !== 'image/avif') {
                $failed[] = $originalName . ' says it is an image but could not be read as one.';
                continue;
            }

            $storedName = gmdate('Ymd') . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
            $target     = UPLOAD_DIR . '/' . $storedName;

            if (!move_uploaded_file($tmpPath, $target)) {
                $failed[] = $originalName . ' could not be saved into the uploads directory.';
                continue;
            }
            @chmod($target, 0644);

            try {
                media_insert($storedName, $originalName, $mime, $kind, $size);
                $saved++;
            } catch (Throwable $e) {
                @unlink($target);
                $failed[] = $originalName . ' could not be recorded in the database.';
            }
        }

        if ($saved > 0) {
            flash('ok', $saved === 1 ? 'Uploaded 1 file.' : 'Uploaded ' . $saved . ' files.');
        }
        foreach ($failed as $message) {
            flash('error', $message);
        }
        if ($saved === 0 && $failed === []) {
            flash('warn', 'Choose at least one file to upload.');
        }

        redirect('media.php');
    }

    flash('error', 'Unknown action.');
    redirect('media.php');
}

// --------------------------------------------------------------------------
// View
// --------------------------------------------------------------------------

$kindFilter = $_GET['kind'] ?? '';
if (!isset(MEDIA_KINDS[$kindFilter])) {
    $kindFilter = '';
}

$files = media_list($kindFilter);
$totalBytes = 0;
foreach (media_list() as $item) {
    $totalBytes += (int) $item['bytes'];
}

page_head('Media', 'media');
page_title('Media');
?>

<form method="post" action="media.php" enctype="multipart/form-data" class="uploader">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="upload">
  <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_BYTES ?>">

  <label class="field">
    <span class="field-label">Add files</span>
    <input type="file" name="files[]" multiple required
           accept="<?= h(implode(',', array_keys(ALLOWED_UPLOAD_TYPES))) ?>">
  </label>

  <button type="submit" class="btn btn-primary">Upload</button>
  <p class="hint hint-block">
    Images, sound and video up to <?= h(fmt_bytes(MAX_UPLOAD_BYTES)) ?> each.
  </p>
</form>

<nav class="filters">
  <a href="media.php"<?= $kindFilter === '' ? ' aria-current="page"' : '' ?>>Everything</a>
  <?php foreach (MEDIA_KINDS as $kind => $label): ?>
    <a href="?kind=<?= h($kind) ?>"<?= $kindFilter === $kind ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
  <?php endforeach; ?>
  <?php if ($totalBytes > 0): ?>
    <span class="filler"><?= h(fmt_bytes($totalBytes)) ?> stored</span>
  <?php endif; ?>
</nav>

<?php if ($files === []): ?>
  <div class="empty">
    <p><?= $kindFilter === ''
        ? 'No files yet. Anything you upload here can be attached to an entry.'
        : 'Nothing of that kind yet.' ?></p>
  </div>
<?php else: ?>
  <ul class="library">
    <?php foreach ($files as $item):
      $url      = UPLOAD_URL . '/' . $item['filename'];
      $useCount = (int) $item['use_count'];
    ?>
      <li class="card">
        <div class="card-view card-view-<?= h($item['kind']) ?>">
          <?php if ($item['kind'] === 'image'): ?>
            <a href="<?= h($url) ?>" target="_blank" rel="noopener">
              <img src="<?= h($url) ?>" alt="<?= h($item['original_name']) ?>" loading="lazy">
            </a>
          <?php elseif ($item['kind'] === 'audio'): ?>
            <audio src="<?= h($url) ?>" controls preload="none"></audio>
          <?php else: ?>
            <video src="<?= h($url) ?>" controls preload="metadata"></video>
          <?php endif; ?>
        </div>

        <div class="card-body">
          <p class="card-name"><a href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h($item['original_name']) ?></a></p>
          <p class="card-meta">
            <?= h(fmt_bytes((int) $item['bytes'])) ?>,
            <?= h($item['mime']) ?>,
            added <?= h(fmt_datetime($item['uploaded_at'], 'j M Y')) ?>
          </p>
          <p class="card-meta">
            <?php if ($useCount === 0): ?>
              <span class="muted">Not attached to any entry</span>
            <?php else: ?>
              In <?= $useCount ?> <?= $useCount === 1 ? 'entry' : 'entries' ?>:
              <?php foreach (media_used_by((int) $item['id']) as $n => $used): ?>
                <?= $n > 0 ? ', ' : '' ?><a href="entry.php?id=<?= (int) $used['id'] ?>">#<?= (int) $used['id'] ?></a>
              <?php endforeach; ?>
            <?php endif; ?>
          </p>
        </div>

        <form method="post" action="media.php" class="card-action"
              onsubmit="return confirm(<?= h(json_encode(
                  $useCount === 0
                      ? 'Delete ' . $item['original_name'] . '? This cannot be undone.'
                      : 'Delete ' . $item['original_name'] . '? It will be removed from '
                        . $useCount . ($useCount === 1 ? ' entry' : ' entries') . ' as well.'
              )) ?>);">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
          <button type="submit" class="btn btn-danger">Delete</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php page_foot(); ?>
