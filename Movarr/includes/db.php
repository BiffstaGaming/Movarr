<?php
/**
 * SQLite database helpers for Movarr.
 *
 * Tables:
 *   tracked_media  – source of truth for what's on fast storage and when it expires
 *   pending_moves  – manual move requests waiting to be processed by mover.py
 */

function db_connect(): PDO
{
    $path = db_file();
    $db   = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    db_migrate($db);
    return $db;
}

function db_migrate(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS tracked_media (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            media_type       TEXT    NOT NULL,               -- 'show' or 'movie'
            title            TEXT    NOT NULL DEFAULT '',
            external_id      INTEGER NOT NULL,               -- tvdb_id or tmdb_id
            service          TEXT    NOT NULL,               -- 'sonarr' or 'radarr'
            mapping_id       TEXT    NOT NULL,
            folder           TEXT    NOT NULL DEFAULT '',
            current_location TEXT    NOT NULL DEFAULT 'unknown', -- 'fast' or 'slow'
            moved_at         INTEGER,
            relocate_after   INTEGER,                        -- NULL = never auto-relocate
            source           TEXT    NOT NULL DEFAULT 'auto',-- 'auto' or 'manual'
            notes            TEXT    DEFAULT '',
            created_at       INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at       INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(external_id, service, mapping_id)
        );

        CREATE TABLE IF NOT EXISTS pending_moves (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            external_id  INTEGER NOT NULL,
            service      TEXT    NOT NULL,
            mapping_id   TEXT    NOT NULL,
            direction    TEXT    NOT NULL,                   -- 'to_fast' or 'to_slow'
            notes        TEXT    DEFAULT '',
            requested_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            status       TEXT    NOT NULL DEFAULT 'pending'  -- 'pending','done','error'
        );
    ");
}

/** Insert a pending manual move and return its id. */
function db_queue_move(PDO $db, int $external_id, string $service,
                       string $mapping_id, string $direction, string $notes = ''): int
{
    $stmt = $db->prepare(
        'INSERT INTO pending_moves (external_id, service, mapping_id, direction, notes, requested_at, status)
         VALUES (?, ?, ?, ?, ?, ?, \'pending\')'
    );
    $stmt->execute([$external_id, $service, $mapping_id, $direction, $notes, time()]);
    return (int)$db->lastInsertId();
}

/** Fetch all pending moves (not yet processed). */
function db_pending_moves(PDO $db): array
{
    return $db->query("SELECT * FROM pending_moves WHERE status='pending' ORDER BY requested_at ASC")
              ->fetchAll();
}

/** Fetch all tracked media, newest first. */
function db_all_tracked(PDO $db): array
{
    return $db->query('SELECT * FROM tracked_media ORDER BY updated_at DESC')
              ->fetchAll();
}

/** Delete a tracked_media entry by id. */
function db_delete_tracked(PDO $db, int $id): void
{
    $db->prepare('DELETE FROM tracked_media WHERE id=?')->execute([$id]);
}

/** Pin an entry so it never auto-relocates (relocate_after = NULL, source = manual). */
function db_pin_tracked(PDO $db, int $id): void
{
    $db->prepare("UPDATE tracked_media SET relocate_after=NULL, source='manual', updated_at=? WHERE id=?")
       ->execute([time(), $id]);
}

/** Set a new relocate_after date (unix timestamp) on a tracked entry. */
function db_set_relocate(PDO $db, int $id, int $ts): void
{
    $db->prepare('UPDATE tracked_media SET relocate_after=?, updated_at=? WHERE id=?')
       ->execute([$ts, time(), $id]);
}
