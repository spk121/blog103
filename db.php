<?php
declare(strict_types=1);

/**
 * SQLite connection, schema, and every query the admin uses.
 *
 * Storage rules:
 *  - all timestamps are UTC 'YYYY-MM-DD HH:MM:SS' strings
 *  - entry_media and entry_links use position 0..3, which is both the display
 *    order and the mechanism that enforces the four-slot limit
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $maxMedia = MAX_MEDIA_PER_ENTRY - 1;
    $maxLinks = MAX_LINKS_PER_ENTRY - 1;

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS entries (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT,
            body        TEXT,
            body_format TEXT    NOT NULL DEFAULT 'wrap'
                        CHECK (body_format IN ('wrap', 'pre')),
            visible     INTEGER NOT NULL DEFAULT 0
                        CHECK (visible IN (0, 1)),
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_entries_public
            ON entries (visible, created_at DESC);

        CREATE TABLE IF NOT EXISTS media (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            filename      TEXT    NOT NULL UNIQUE,
            original_name TEXT    NOT NULL,
            mime          TEXT    NOT NULL,
            kind          TEXT    NOT NULL
                          CHECK (kind IN ('image', 'audio', 'video')),
            bytes         INTEGER NOT NULL,
            uploaded_at   TEXT    NOT NULL
        );

        CREATE TABLE IF NOT EXISTS entry_media (
            entry_id INTEGER NOT NULL REFERENCES entries (id) ON DELETE CASCADE,
            media_id INTEGER NOT NULL REFERENCES media (id)   ON DELETE CASCADE,
            position INTEGER NOT NULL CHECK (position BETWEEN 0 AND $maxMedia),
            PRIMARY KEY (entry_id, position)
        );

        CREATE INDEX IF NOT EXISTS idx_entry_media_media ON entry_media (media_id);

        CREATE TABLE IF NOT EXISTS entry_links (
            entry_id INTEGER NOT NULL REFERENCES entries (id) ON DELETE CASCADE,
            position INTEGER NOT NULL CHECK (position BETWEEN 0 AND $maxLinks),
            url      TEXT    NOT NULL,
            label    TEXT,
            PRIMARY KEY (entry_id, position)
        );
        SQL);
}

// --------------------------------------------------------------------------
// Entries
// --------------------------------------------------------------------------

/** @param string $filter one of: all, public, draft */
function entry_list(string $filter = 'all'): array
{
    $where = match ($filter) {
        'public' => 'WHERE e.visible = 1',
        'draft'  => 'WHERE e.visible = 0',
        default  => '',
    };

    $sql = "SELECT e.*,
                   (SELECT COUNT(*) FROM entry_media m WHERE m.entry_id = e.id) AS media_count,
                   (SELECT COUNT(*) FROM entry_links l WHERE l.entry_id = e.id) AS link_count
            FROM entries e
            $where
            ORDER BY e.created_at DESC, e.id DESC";

    return db()->query($sql)->fetchAll();
}

function entry_counts(): array
{
    $row = db()->query(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(visible = 1), 0) AS live,
                COALESCE(SUM(visible = 0), 0) AS drafts
         FROM entries'
    )->fetch();

    return [
        'total'  => (int) $row['total'],
        'live'   => (int) $row['live'],
        'drafts' => (int) $row['drafts'],
    ];
}

function entry_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM entries WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** @return array position => media row (with the media table's columns) */
function entry_media(int $entryId): array
{
    $stmt = db()->prepare(
        'SELECT em.position, m.*
         FROM entry_media em
         JOIN media m ON m.id = em.media_id
         WHERE em.entry_id = ?
         ORDER BY em.position'
    );
    $stmt->execute([$entryId]);

    $slots = [];
    foreach ($stmt->fetchAll() as $row) {
        $slots[(int) $row['position']] = $row;
    }
    return $slots;
}

/** @return array position => ['url' => ..., 'label' => ...] */
function entry_links(int $entryId): array
{
    $stmt = db()->prepare(
        'SELECT position, url, label FROM entry_links WHERE entry_id = ? ORDER BY position'
    );
    $stmt->execute([$entryId]);

    $slots = [];
    foreach ($stmt->fetchAll() as $row) {
        $slots[(int) $row['position']] = $row;
    }
    return $slots;
}

/**
 * Insert or update an entry along with its media slots and links.
 *
 * @param array $data  title, body, body_format, visible, created_at
 * @param array $media list of media ids in slot order (already validated)
 * @param array $links list of ['url' => ..., 'label' => ...] in slot order
 * @return int the entry id
 */
function entry_save(?int $id, array $data, array $media, array $links): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $now = now_utc();

        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO entries (title, body, body_format, visible, created_at, updated_at)
                 VALUES (:title, :body, :format, :visible, :created, :updated)'
            );
            $stmt->execute([
                ':title'   => $data['title'],
                ':body'    => $data['body'],
                ':format'  => $data['body_format'],
                ':visible' => $data['visible'],
                ':created' => $data['created_at'] ?: $now,
                ':updated' => $now,
            ]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare(
                'UPDATE entries
                 SET title = :title, body = :body, body_format = :format,
                     visible = :visible, created_at = :created, updated_at = :updated
                 WHERE id = :id'
            );
            $stmt->execute([
                ':title'   => $data['title'],
                ':body'    => $data['body'],
                ':format'  => $data['body_format'],
                ':visible' => $data['visible'],
                ':created' => $data['created_at'],
                ':updated' => $now,
                ':id'      => $id,
            ]);
        }

        $pdo->prepare('DELETE FROM entry_media WHERE entry_id = ?')->execute([$id]);
        $insertMedia = $pdo->prepare(
            'INSERT INTO entry_media (entry_id, media_id, position) VALUES (?, ?, ?)'
        );
        foreach (array_values($media) as $position => $mediaId) {
            $insertMedia->execute([$id, $mediaId, $position]);
        }

        $pdo->prepare('DELETE FROM entry_links WHERE entry_id = ?')->execute([$id]);
        $insertLink = $pdo->prepare(
            'INSERT INTO entry_links (entry_id, position, url, label) VALUES (?, ?, ?, ?)'
        );
        foreach (array_values($links) as $position => $link) {
            $insertLink->execute([$id, $position, $link['url'], $link['label']]);
        }

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function entry_set_visibility(int $id, bool $visible): void
{
    $stmt = db()->prepare('UPDATE entries SET visible = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$visible ? 1 : 0, now_utc(), $id]);
}

function entry_delete(int $id): void
{
    // entry_media and entry_links cascade; the media files themselves are kept.
    db()->prepare('DELETE FROM entries WHERE id = ?')->execute([$id]);
}

// --------------------------------------------------------------------------
// Media
// --------------------------------------------------------------------------

/** @param string $kind '' for everything, or image/audio/video */
function media_list(string $kind = ''): array
{
    if ($kind !== '' && isset(MEDIA_KINDS[$kind])) {
        $stmt = db()->prepare(
            'SELECT m.*, (SELECT COUNT(*) FROM entry_media em WHERE em.media_id = m.id) AS use_count
             FROM media m WHERE m.kind = ? ORDER BY m.uploaded_at DESC, m.id DESC'
        );
        $stmt->execute([$kind]);
        return $stmt->fetchAll();
    }

    return db()->query(
        'SELECT m.*, (SELECT COUNT(*) FROM entry_media em WHERE em.media_id = m.id) AS use_count
         FROM media m ORDER BY m.uploaded_at DESC, m.id DESC'
    )->fetchAll();
}

function media_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM media WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function media_insert(string $filename, string $originalName, string $mime, string $kind, int $bytes): int
{
    $stmt = db()->prepare(
        'INSERT INTO media (filename, original_name, mime, kind, bytes, uploaded_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$filename, $originalName, $mime, $kind, $bytes, now_utc()]);
    return (int) db()->lastInsertId();
}

/** Which entries currently use this file. */
function media_used_by(int $mediaId): array
{
    $stmt = db()->prepare(
        'SELECT e.id, e.title FROM entry_media em
         JOIN entries e ON e.id = em.entry_id
         WHERE em.media_id = ? ORDER BY e.id'
    );
    $stmt->execute([$mediaId]);
    return $stmt->fetchAll();
}

/** Remove the row and the file on disk. Entry slots referencing it cascade away. */
function media_delete(int $id): bool
{
    $media = media_find($id);
    if ($media === null) {
        return false;
    }

    db()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);

    $path = UPLOAD_DIR . '/' . $media['filename'];
    if (is_file($path)) {
        @unlink($path);
    }
    return true;
}
