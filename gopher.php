<?php
declare(strict_types=1);

/**
 * Renders the public entries into a static gopher site.
 *
 * Output tree (served through the <GOPHER_DIR>/current symlink, which each
 * publish swaps to point at a fresh <GOPHER_DIR>/releases/<token> directory):
 *
 *   <GOPHER_DIR>/current/gophermap          page 1        selector /
 *   <GOPHER_DIR>/current/p2/gophermap       page 2        selector /p2
 *   <GOPHER_DIR>/current/entries/0007.txt   entry bodies  selector /entries/0007.txt
 *   <GOPHER_DIR>/current/media/<file>       attachments   selector /media/<file>
 *
 * A menu line is  <type><display>TAB<selector>TAB<host>TAB<port>CRLF  and the
 * menu ends with a line holding a single period. Display strings and selectors
 * therefore must not contain tabs or line breaks; gopher_clean() enforces that.
 */

// --------------------------------------------------------------------------
// Menu building blocks
// --------------------------------------------------------------------------

/**
 * Flatten anything that would break the tab-delimited menu format. This is
 * protocol safety only: it must not touch spacing, or the alignment built
 * into a display string gets eaten.
 */
function gopher_clean(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
    return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '');
}

/**
 * Tidy a piece of author-supplied text for display: protocol-safe, and with
 * runs of whitespace collapsed so a body full of newlines reads as one line.
 * Apply this to content, never to a string you have already laid out.
 */
function gopher_tidy(string $text): string
{
    return trim(preg_replace('/ {2,}/', ' ', gopher_clean($text)) ?? '');
}

/** Trim to a display width, marking the cut with an ellipsis. */
function gopher_truncate(string $text, int $width): string
{
    if ($width < 4 || u_strlen($text) <= $width) {
        return $text;
    }
    return rtrim(u_substr($text, 0, $width - 3)) . '...';
}

/**
 * One menu line. $indent nests an item under its entry heading; it is added
 * after cleaning so it survives whitespace collapsing.
 */
function gopher_line(string $type, string $display, string $selector, string $indent = ''): string
{
    $display = $indent . gopher_truncate(gopher_clean($display), GOPHER_DISPLAY_WIDTH - u_strlen($indent));

    return $type . $display . "\t" . gopher_clean($selector)
        . "\t" . GOPHER_HOST . "\t" . GOPHER_PORT . "\r\n";
}

/**
 * An informational line. Clients ignore the selector, host and port on type
 * 'i', but all four fields must be present for strict parsers.
 */
function gopher_info(string $text = ''): string
{
    return 'i' . gopher_truncate(gopher_clean($text), GOPHER_DISPLAY_WIDTH)
        . "\tfake\t(NULL)\t0\r\n";
}

/** Selector for a menu page. Page 1 lives at the base itself. */
function gopher_page_selector(int $page): string
{
    $base = rtrim(GOPHER_SELECTOR_BASE, '/');
    if ($page === 1) {
        return $base === '' ? '/' : $base;
    }
    return $base . '/p' . $page;
}

/** Selector for a file inside the tree, e.g. 'entries/0007.txt'. */
function gopher_selector(string $path): string
{
    return rtrim(GOPHER_SELECTOR_BASE, '/') . '/' . ltrim($path, '/');
}

// --------------------------------------------------------------------------
// Entry text
// --------------------------------------------------------------------------

/**
 * Rewrap prose to a column width, one paragraph at a time so blank lines are
 * kept. Words longer than the limit (URLs, mostly) are left whole rather than
 * chopped in half.
 */
function gopher_wrap(string $text, int $columns): string
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

    foreach ($lines as $i => $line) {
        $line = rtrim($line);
        $lines[$i] = $line === '' ? '' : wordwrap($line, $columns, "\n", false);
    }

    return implode("\n", $lines);
}

/** The body exactly as the reader will receive it. */
function gopher_body(array $entry): string
{
    $body = str_replace(["\r\n", "\r"], "\n", (string) ($entry['body'] ?? ''));

    if ($entry['body_format'] === 'wrap') {
        $body = gopher_wrap($body, GOPHER_WRAP_COLUMNS);
    }

    $body = rtrim($body, "\n");
    return $body === '' ? '' : $body . "\n";
}

// --------------------------------------------------------------------------
// Filesystem
// --------------------------------------------------------------------------

function gopher_mkdir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create ' . $dir);
    }
}

function gopher_put(string $path, string $contents): int
{
    gopher_mkdir(dirname($path));
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
    @chmod($path, 0644);
    return strlen($contents);
}

/**
 * Delete one of our own scratch directories. Refuses anything that is not a
 * direct child of GOPHER_DIR/releases carrying the token name we generated,
 * so a misconfigured GOPHER_DIR cannot turn this into a recursive wipe.
 */
function gopher_rmtree(string $dir): bool
{
    $real = realpath($dir);
    $parent = realpath(rtrim(GOPHER_DIR, '/') . '/releases');

    if ($real === false || $parent === false) {
        return false;
    }
    if (dirname($real) !== $parent) {
        return false;
    }
    if (!preg_match('/^[0-9a-f]{12}$/', basename($real))) {
        return false;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    return @rmdir($real);
}

// --------------------------------------------------------------------------
// Rendering
// --------------------------------------------------------------------------

/** What a publish would contain, without writing anything. */
function gopher_preview(): array
{
    $row = db()->query(
        'SELECT COUNT(*) AS entries,
                COALESCE(SUM(body IS NOT NULL AND body <> \'\'), 0) AS with_text
         FROM entries WHERE visible = 1'
    )->fetch();

    $media = db()->query(
        'SELECT COUNT(DISTINCT em.media_id) AS n
         FROM entry_media em
         JOIN entries e ON e.id = em.entry_id
         WHERE e.visible = 1'
    )->fetch();

    $entries = (int) $row['entries'];

    return [
        'entries' => $entries,
        'text'    => (int) $row['with_text'],
        'media'   => (int) $media['n'],
        'pages'   => max(1, (int) ceil($entries / GOPHER_ENTRIES_PER_PAGE)),
    ];
}

/**
 * Build the whole site into $root. Returns a report of what was written.
 */
function gopher_build(string $root): array
{
    $report = [
        'entries' => 0, 'pages' => 0, 'text_files' => 0,
        'media_files' => 0, 'bytes' => 0, 'warnings' => [],
    ];

    $entries = db()->query(
        'SELECT * FROM entries WHERE visible = 1 ORDER BY created_at DESC, id DESC'
    )->fetchAll();

    $pages = max(1, (int) ceil(count($entries) / GOPHER_ENTRIES_PER_PAGE));
    $copied = [];

    foreach (array_chunk($entries, GOPHER_ENTRIES_PER_PAGE) ?: [[]] as $index => $chunk) {
        $page = $index + 1;
        $menu = gopher_info(GOPHER_TITLE);
        $menu .= gopher_info(str_repeat('=', min(u_strlen(GOPHER_TITLE), GOPHER_DISPLAY_WIDTH)));
        if ($pages > 1) {
            $menu .= gopher_info('Page ' . $page . ' of ' . $pages);
        }
        $menu .= gopher_info();

        foreach ($chunk as $entry) {
            $id    = (int) $entry['id'];
            $date  = fmt_datetime($entry['created_at'], GOPHER_DATE_FORMAT);
            $title = trim((string) ($entry['title'] ?? ''));

            // With no title of its own, the date becomes the title.
            $menu .= gopher_info(
                $title !== '' ? $date . '  ' . gopher_tidy($title) : $date
            );

            // Media first, in the slot order the author chose.
            foreach (entry_media($id) as $item) {
                $type = GOPHER_MEDIA_TYPES[$item['kind']] ?? '9';
                if ($item['kind'] === 'image' && $item['mime'] === 'image/gif') {
                    $type = 'g';
                }

                $source = UPLOAD_DIR . '/' . $item['filename'];
                if (!is_file($source)) {
                    $report['warnings'][] = 'Entry ' . $id . ': the file for '
                        . $item['original_name'] . ' is missing from uploads, so it was skipped.';
                    continue;
                }

                if (!isset($copied[$item['filename']])) {
                    gopher_mkdir($root . '/media');
                    if (!@copy($source, $root . '/media/' . $item['filename'])) {
                        throw new RuntimeException('Cannot copy ' . $item['filename']);
                    }
                    @chmod($root . '/media/' . $item['filename'], 0644);
                    $copied[$item['filename']] = true;
                    $report['media_files']++;
                    $report['bytes'] += (int) $item['bytes'];
                }

                $menu .= gopher_line(
                    $type,
                    gopher_tidy($item['original_name']),
                    gopher_selector('media/' . $item['filename']),
                    '  '
                );
            }

            // Then the text.
            $body = gopher_body($entry);
            if ($body !== '') {
                $name = sprintf('entries/%04d.txt', $id);
                $text = GOPHER_TEXT_CRLF ? str_replace("\n", "\r\n", $body) : $body;
                $report['bytes'] += gopher_put($root . '/' . $name, $text);
                $report['text_files']++;

                if (preg_match('/^\.$/m', $body)) {
                    $report['warnings'][] = 'Entry ' . $id . ' has a line containing only a period. '
                        . 'Some gopher servers read that as end of file and will truncate it there.';
                }

                $menu .= gopher_line(
                    '0',
                    gopher_truncate(gopher_tidy($body), GOPHER_LINK_PREVIEW_CHARS),
                    gopher_selector($name),
                    '  '
                );
            }

            // Then the outside world.
            foreach (entry_links($id) as $link) {
                $label = trim((string) ($link['label'] ?? ''));
                $menu .= gopher_line(
                    'h',
                    gopher_tidy($label !== '' ? $label : $link['url']),
                    'URL:' . $link['url'],
                    '  '
                );
            }

            $menu .= gopher_info();
            $report['entries']++;
        }

        if ($chunk === []) {
            $menu .= gopher_info('Nothing has been published yet.');
            $menu .= gopher_info();
        }

        if ($pages > 1) {
            $menu .= gopher_info(str_repeat('-', 40));
            if ($page > 1) {
                $menu .= gopher_line('1', 'Previous page (' . ($page - 1) . ' of ' . $pages . ')',
                    gopher_page_selector($page - 1));
            }
            if ($page < $pages) {
                $menu .= gopher_line('1', 'Next page (' . ($page + 1) . ' of ' . $pages . ')',
                    gopher_page_selector($page + 1));
            }
        }

        $menu .= ".\r\n";

        $path = $page === 1 ? $root . '/gophermap' : $root . '/p' . $page . '/gophermap';
        $report['bytes'] += gopher_put($path, $menu);
        $report['pages']++;
    }

    // Keep servers from auto-listing the two content directories.
    foreach (['entries' => 'Entry text', 'media' => 'Attached files'] as $dir => $label) {
        if (is_dir($root . '/' . $dir)) {
            gopher_put(
                $root . '/' . $dir . '/gophermap',
                gopher_info($label)
                . gopher_info()
                . gopher_line('1', 'Back to the blog', gopher_page_selector(1))
                . ".\r\n"
            );
        }
    }

    return $report;
}

/**
 * Render into a fresh release directory under GOPHER_DIR/releases, then swap
 * a "current" symlink over to it, so a failure part way through never leaves
 * a half-written site being served.
 *
 * GOPHER_DIR itself is never renamed. When Docker (or anything else) bind
 * mounts a volume at GOPHER_DIR, the mount point cannot be moved aside with
 * rename(2) — the kernel refuses with EBUSY. Renaming a symlink that lives
 * inside GOPHER_DIR has no such problem, so that is what gets swapped
 * instead.
 */
function gopher_publish(): array
{
    $base        = rtrim(GOPHER_DIR, '/');
    $releasesDir = $base . '/releases';
    $link        = $base . '/current';

    gopher_mkdir($releasesDir);
    if (!is_writable($releasesDir)) {
        throw new RuntimeException($releasesDir . ' is not writable by the web server.');
    }

    $token   = bin2hex(random_bytes(6));
    $release = $releasesDir . '/' . $token;

    gopher_mkdir($release);

    try {
        $report = gopher_build($release);
    } catch (Throwable $e) {
        gopher_rmtree($release);
        throw $e;
    }

    $previousTarget = @readlink($link);
    $tmpLink        = $link . '.new-' . $token;

    if (!@symlink('releases/' . $token, $tmpLink)) {
        gopher_rmtree($release);
        throw new RuntimeException('Cannot prepare the new site symlink in ' . $base . '.');
    }

    if (!@rename($tmpLink, $link)) {
        @unlink($tmpLink);
        gopher_rmtree($release);
        throw new RuntimeException('Cannot swap the new site into place.');
    }

    if ($previousTarget !== false) {
        $previousRelease = $releasesDir . '/' . basename($previousTarget);
        if ($previousRelease !== $release) {
            gopher_rmtree($previousRelease);
        }
    }

    $report['path'] = $link;
    $report['at']   = now_utc();

    @file_put_contents(DATA_DIR . '/gopher-publish.json', json_encode($report, JSON_PRETTY_PRINT));

    return $report;
}

/** The report from the last publish, or null. */
function gopher_last_publish(): ?array
{
    $file = DATA_DIR . '/gopher-publish.json';
    if (!is_readable($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
