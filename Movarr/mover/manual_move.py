#!/usr/bin/env python3
"""
Manual Move Runner

Processes only the pending_moves queue from the SQLite DB.
Always runs in REAL mode — ignores dry_run / list_only flags.
Does NOT query Tautulli or touch any other shows/movies.
"""

import json
import logging
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

import requests

CONFIG_DIR    = Path(os.getenv('CONFIG_PATH', '/config'))
SETTINGS_FILE = CONFIG_DIR / 'settings.json'
LOG_FILE      = CONFIG_DIR / 'mover.log'
QUEUE_FILE    = CONFIG_DIR / 'queue.json'
DB_FILE       = CONFIG_DIR / 'movarr.db'


# -- Logging -------------------------------------------------------------------

def setup_logger() -> logging.Logger:
    logger = logging.getLogger('manual_move')
    logger.setLevel(logging.DEBUG)
    fmt = logging.Formatter('%(asctime)s [%(levelname)s] %(message)s',
                            datefmt='%Y-%m-%d %H:%M:%S')
    fh = logging.FileHandler(LOG_FILE)
    fh.setFormatter(fmt)
    logger.addHandler(fh)
    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)
    logger.addHandler(sh)
    return logger


# -- Queue writer (appends to existing queue or starts fresh) ------------------

class QueueWriter:
    def __init__(self):
        # Always start a fresh run entry for manual moves
        self.data = {
            'run_id':    datetime.now().strftime('%Y%m%d_%H%M%S'),
            'started':   datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'completed': None,
            'mode':      'real',
            'items':     [],
        }
        self._write()

    def add_item(self, item_id, name, service, mapping, direction, src, dst) -> int:
        idx = len(self.data['items'])
        self.data['items'].append({
            'id': item_id, 'name': name, 'service': service,
            'mapping': mapping, 'direction': direction,
            'src': src, 'dst': dst,
            'status': 'pending', 'progress': '',
            'started_at': None, 'done_at': None,
        })
        self._write()
        return idx

    def start(self, idx: int) -> None:
        self.data['items'][idx]['status'] = 'moving'
        self.data['items'][idx]['started_at'] = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        self._write()

    def done(self, idx: int, progress: str = '') -> None:
        self.data['items'][idx].update(status='done', progress=progress,
            done_at=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
        self._write()

    def error(self, idx: int, progress: str = '') -> None:
        self.data['items'][idx].update(status='error', progress=progress,
            done_at=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
        self._write()

    def skip(self, idx: int, reason: str = '') -> None:
        self.data['items'][idx].update(status='skipped', progress=reason,
            done_at=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
        self._write()

    def finish(self) -> None:
        self.data['completed'] = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        self._write()

    def _write(self) -> None:
        try:
            QUEUE_FILE.write_text(json.dumps(self.data, indent=2))
        except Exception:
            pass


# -- Settings ------------------------------------------------------------------

def load_settings() -> dict:
    with open(SETTINGS_FILE) as f:
        return json.load(f)


# -- SQLite DB -----------------------------------------------------------------

def db_connect() -> sqlite3.Connection:
    db = sqlite3.connect(str(DB_FILE))
    db.row_factory = sqlite3.Row
    db.execute('PRAGMA journal_mode=WAL')
    db_migrate(db)
    return db


def db_migrate(db: sqlite3.Connection) -> None:
    db.executescript("""
        CREATE TABLE IF NOT EXISTS tracked_media (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            media_type       TEXT    NOT NULL,
            title            TEXT    NOT NULL DEFAULT '',
            external_id      INTEGER NOT NULL,
            service          TEXT    NOT NULL,
            mapping_id       TEXT    NOT NULL,
            folder           TEXT    NOT NULL DEFAULT '',
            current_location TEXT    NOT NULL DEFAULT 'unknown',
            moved_at         INTEGER,
            relocate_after   INTEGER,
            source           TEXT    NOT NULL DEFAULT 'auto',
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
            direction    TEXT    NOT NULL,
            notes        TEXT    DEFAULT '',
            requested_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            status       TEXT    NOT NULL DEFAULT 'pending'
        );

        CREATE TABLE IF NOT EXISTS move_history (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            media_type      TEXT    NOT NULL DEFAULT '',
            title           TEXT    NOT NULL DEFAULT '',
            external_id     INTEGER NOT NULL DEFAULT 0,
            service         TEXT    NOT NULL DEFAULT '',
            mapping_id      TEXT    NOT NULL DEFAULT '',
            folder          TEXT    NOT NULL DEFAULT '',
            direction       TEXT    NOT NULL DEFAULT '',
            src_path        TEXT    NOT NULL DEFAULT '',
            dst_path        TEXT    NOT NULL DEFAULT '',
            source          TEXT    NOT NULL DEFAULT 'auto',
            service_updated INTEGER NOT NULL DEFAULT 0,
            plex_refreshed  INTEGER NOT NULL DEFAULT 0,
            notes           TEXT    DEFAULT '',
            moved_at        INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );
    """)
    db.commit()


def folder_size_bytes(path) -> int:
    """Return total byte size of all files under path, or 0 on error."""
    total = 0
    try:
        for dirpath, _, filenames in os.walk(str(path)):
            for f in filenames:
                try:
                    total += os.path.getsize(os.path.join(dirpath, f))
                except OSError:
                    pass
    except Exception:
        pass
    return total


def db_record_history(db: sqlite3.Connection, media_type: str, title: str,
                      external_id: int, service: str, mapping_id: str, folder: str,
                      direction: str, src_path, dst_path, source: str,
                      service_updated: bool, plex_refreshed: bool, notes: str,
                      time_taken: int = None, size_on_disk: int = None) -> None:
    db.execute("""
        INSERT INTO move_history
            (media_type, title, external_id, service, mapping_id, folder,
             direction, src_path, dst_path, source, service_updated, plex_refreshed,
             notes, moved_at, time_taken, size_on_disk)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """, (media_type, title, external_id, service, mapping_id, folder,
          direction, str(src_path), str(dst_path), source,
          1 if service_updated else 0, 1 if plex_refreshed else 0,
          notes, int(time.time()), time_taken, size_on_disk))
    db.commit()


def db_upsert(db: sqlite3.Connection, media_type: str, title: str,
              external_id: int, service: str, mapping_id: str, folder: str,
              location: str, source: str, notes: str, relocate_after) -> None:
    now = int(time.time())
    db.execute("""
        INSERT INTO tracked_media
            (media_type, title, external_id, service, mapping_id, folder,
             current_location, moved_at, relocate_after, source, notes,
             created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(external_id, service, mapping_id) DO UPDATE SET
            title            = excluded.title,
            folder           = excluded.folder,
            current_location = excluded.current_location,
            moved_at         = excluded.moved_at,
            relocate_after   = excluded.relocate_after,
            source           = excluded.source,
            notes            = excluded.notes,
            updated_at       = excluded.updated_at
    """, (media_type, title, external_id, service, mapping_id, folder,
          location, now, relocate_after, source, notes, now, now))
    db.commit()


# -- rsync move ----------------------------------------------------------------

def rsync_move(src: Path, dst: Path, log: logging.Logger) -> tuple:
    """rsync src/ into dst/ (always real), delete src on success. Returns (ok, summary)."""
    dst.mkdir(parents=True, exist_ok=True)
    cmd = ['rsync', '-av', '--checksum', str(src) + '/', str(dst) + '/']
    log.info('rsync  %s  ->  %s', src, dst)

    result = subprocess.run(cmd, capture_output=True, text=True)

    summary = ''
    for line in reversed(result.stdout.splitlines()):
        if 'sent' in line and 'bytes' in line:
            summary = line.strip()
            break

    if result.returncode != 0:
        log.error('rsync exited %d -- source left intact. %s',
                  result.returncode, result.stderr[:200])
        return False, ''

    if summary:
        log.info('rsync complete: %s', summary)

    try:
        shutil.rmtree(str(src))
        log.info('Deleted source: %s', src)
    except Exception as exc:
        log.error('Failed to delete source %s: %s', src, exc)
        return False, summary

    return True, summary


# -- Service API helpers -------------------------------------------------------

def get_sonarr_series(settings: dict, log: logging.Logger) -> list:
    sonarr = settings['sonarr']
    try:
        resp = requests.get(sonarr['url'].rstrip('/') + '/api/v3/series',
                            headers={'X-Api-Key': sonarr['api_key']}, timeout=30)
        return resp.json()
    except Exception as exc:
        log.error('Sonarr series fetch failed: %s', exc)
        return []


def get_radarr_movies(settings: dict, log: logging.Logger) -> list:
    radarr = settings.get('radarr', {})
    if not radarr.get('url') or not radarr.get('api_key'):
        return []
    try:
        resp = requests.get(radarr['url'].rstrip('/') + '/api/v3/movie',
                            headers={'X-Api-Key': radarr['api_key']}, timeout=30)
        return resp.json()
    except Exception as exc:
        log.error('Radarr movies fetch failed: %s', exc)
        return []


def update_sonarr_path(series: dict, new_path: str, settings: dict,
                       log: logging.Logger) -> bool:
    sonarr  = settings['sonarr']
    headers = {'X-Api-Key': sonarr['api_key'], 'Content-Type': 'application/json'}
    payload = dict(series)
    payload['path'] = new_path
    try:
        resp = requests.put(sonarr['url'].rstrip('/') + '/api/v3/series/' + str(series['id']),
                            headers=headers, json=payload, timeout=30)
        if resp.ok:
            log.info("Sonarr path updated: '%s' -> %s", series['title'], new_path)
            return True
        log.error('Sonarr PUT failed %d: %s', resp.status_code, resp.text[:300])
    except Exception as exc:
        log.error('Sonarr path update exception: %s', exc)
    return False


def rescan_series(series_id: int, settings: dict, log: logging.Logger) -> None:
    sonarr  = settings['sonarr']
    headers = {'X-Api-Key': sonarr['api_key'], 'Content-Type': 'application/json'}
    try:
        requests.post(sonarr['url'].rstrip('/') + '/api/v3/command',
            headers=headers, json={'name': 'RescanSeries', 'seriesId': series_id},
            timeout=30)
        log.info('Sonarr rescan triggered for series ID %d', series_id)
    except Exception as exc:
        log.error('Sonarr rescan exception: %s', exc)


def update_radarr_path(movie: dict, new_path: str, settings: dict,
                       log: logging.Logger) -> bool:
    radarr  = settings['radarr']
    headers = {'X-Api-Key': radarr['api_key'], 'Content-Type': 'application/json'}
    payload = dict(movie)
    payload['path'] = new_path
    try:
        resp = requests.put(radarr['url'].rstrip('/') + '/api/v3/movie/' + str(movie['id']),
                            headers=headers, json=payload, timeout=30)
        if resp.ok:
            log.info("Radarr path updated: '%s' -> %s", movie['title'], new_path)
            return True
        log.error('Radarr PUT failed %d: %s', resp.status_code, resp.text[:300])
    except Exception as exc:
        log.error('Radarr path update exception: %s', exc)
    return False


def rescan_movie(movie_id: int, settings: dict, log: logging.Logger) -> None:
    radarr  = settings['radarr']
    headers = {'X-Api-Key': radarr['api_key'], 'Content-Type': 'application/json'}
    try:
        requests.post(radarr['url'].rstrip('/') + '/api/v3/command',
            headers=headers, json={'name': 'RescanMovie', 'movieId': movie_id},
            timeout=30)
        log.info('Radarr rescan triggered for movie ID %d', movie_id)
    except Exception as exc:
        log.error('Radarr rescan exception: %s', exc)


def notify_plex(settings: dict, new_path: str, log: logging.Logger) -> bool:
    """Trigger a targeted Plex refresh for the specific folder that changed.
    Returns True if the refresh call succeeded."""
    plex  = settings.get('plex', {})
    url   = plex.get('url', '').rstrip('/')
    token = plex.get('token', '')
    if not url or not token:
        return False
    try:
        resp = requests.get(f'{url}/library/sections',
                            params={'X-Plex-Token': token},
                            headers={'Accept': 'application/json'}, timeout=10)
        if not resp.ok:
            log.warning('Plex sections fetch returned %d', resp.status_code)
            return False

        sections   = resp.json().get('MediaContainer', {}).get('Directory', [])
        section_id = None
        section_title = ''
        for s in sections:
            for loc in s.get('Location', []):
                if new_path.startswith(loc['path'].rstrip('/') + '/') or \
                   new_path == loc['path'].rstrip('/'):
                    section_id    = s['key']
                    section_title = s.get('title', section_id)
                    break
            if section_id:
                break

        if section_id:
            r = requests.get(f'{url}/library/sections/{section_id}/refresh',
                             params={'X-Plex-Token': token, 'path': new_path},
                             timeout=15)
            if r.ok:
                log.info("Plex refresh: section '%s', path '%s'", section_title, new_path)
                return True
            else:
                log.warning('Plex targeted refresh returned %d', r.status_code)
                return False
        else:
            log.warning("Plex: no section matched path '%s' — skipping refresh. "
                        "Check that Plex and this container share the same mount paths.", new_path)
            return False
    except Exception as exc:
        log.warning('Plex refresh failed: %s', exc)
        return False


# -- Main ----------------------------------------------------------------------

def main() -> None:
    log = setup_logger()
    log.info('=' * 60)
    log.info('Manual move run started')
    log.info('=' * 60)

    if not SETTINGS_FILE.exists():
        log.error('Settings file not found: %s', SETTINGS_FILE)
        sys.exit(1)

    try:
        settings = load_settings()
    except Exception as exc:
        log.error('Failed to load settings: %s', exc)
        sys.exit(1)

    days = int(settings.get('watched_days', 30))

    try:
        db = db_connect()
    except Exception as exc:
        log.error('Failed to open DB: %s', exc)
        sys.exit(1)

    pending = db.execute(
        "SELECT * FROM pending_moves WHERE status='pending' ORDER BY requested_at"
    ).fetchall()

    if not pending:
        log.info('No pending manual moves — nothing to do.')
        return

    log.info('%d pending manual move(s) to process', len(pending))

    queue = QueueWriter()

    # Load service data only for the services actually needed
    needed_services = {row['service'] for row in pending}
    all_series = get_sonarr_series(settings, log) if 'sonarr' in needed_services else []
    all_movies = get_radarr_movies(settings, log) if 'radarr' in needed_services else []

    now         = int(time.time())
    relocate_ts = now + (days * 86400)

    for row in pending:
        pm_id       = row['id']
        external_id = row['external_id']
        service     = row['service']
        mapping_id  = row['mapping_id']
        direction   = row['direction']
        notes       = row['notes'] or ''

        mapping = next((m for m in settings.get('path_mappings', [])
                        if m.get('id') == mapping_id), None)
        if not mapping:
            log.error('Pending move %d: mapping "%s" not found in settings', pm_id, mapping_id)
            db.execute("UPDATE pending_moves SET status='error' WHERE id=?", (pm_id,))
            db.commit()
            continue

        name       = mapping.get('name', mapping_id)
        slow_mover = Path(mapping['slow_path_mover'])
        fast_mover = Path(mapping['fast_path_mover'])
        slow_svc   = mapping['slow_path_sonarr'].rstrip('/')
        fast_svc   = mapping['fast_path_sonarr'].rstrip('/')

        log.info('-' * 40)

        if service == 'sonarr':
            item = next((s for s in all_series if s.get('tvdbId') == external_id), None)
            if not item:
                log.error('tvdbId=%d not found in Sonarr — cannot move', external_id)
                db.execute("UPDATE pending_moves SET status='error' WHERE id=?", (pm_id,))
                db.commit()
                continue

            title      = item['title']
            folder     = Path(item['path']).name
            item_id    = item['id']
            media_type = 'show'

            if direction == 'to_fast':
                src, dst       = slow_mover / folder, fast_mover / folder
                new_svc_path   = fast_svc + '/' + folder
                new_location   = 'fast'
                relocate_after = relocate_ts
            else:
                src, dst       = fast_mover / folder, slow_mover / folder
                new_svc_path   = slow_svc + '/' + folder
                new_location   = 'slow'
                relocate_after = None

            log.info('Manual move: "%s" (%s)', title, direction)
            q_idx = queue.add_item(f'manual_sonarr_{item_id}', title, 'sonarr',
                                   name, direction, str(src), str(dst))

            if not src.exists():
                log.warning('Source not found on disk: %s', src)
                queue.skip(q_idx, 'Source not found on disk')
                db.execute("UPDATE pending_moves SET status='error' WHERE id=?", (pm_id,))
                db.commit()
                continue

            size_bytes = folder_size_bytes(src)
            queue.start(q_idx)
            t_start = time.time()
            ok, summary = rsync_move(src, dst, log)
            t_taken = int(time.time() - t_start)
            if ok:
                queue.done(q_idx, summary)
                svc_updated = update_sonarr_path(item, new_svc_path, settings, log)
                if svc_updated:
                    rescan_series(item_id, settings, log)
                plex_ok = notify_plex(settings, new_svc_path, log)
                db_record_history(db, media_type, title, external_id, service,
                                  mapping_id, folder, direction, src, dst,
                                  'manual', svc_updated, plex_ok, notes,
                                  t_taken, size_bytes)
                db_upsert(db, media_type, title, external_id, service,
                          mapping_id, folder, new_location, 'manual', notes, relocate_after)
                db.execute("UPDATE pending_moves SET status='done' WHERE id=?", (pm_id,))
            else:
                queue.error(q_idx, 'rsync failed')
                db.execute("UPDATE pending_moves SET status='error' WHERE id=?", (pm_id,))

        elif service == 'radarr':
            item = next((m for m in all_movies if m.get('tmdbId') == external_id), None)
            if not item:
                log.error('tmdbId=%d not found in Radarr — cannot move', external_id)
                db.execute("UPDATE pending_moves SET status='error' WHERE id=?", (pm_id,))
                db.commit()
                continue

            title      = item['title']
            folder     = Path(item['path']).name
            item_id    = item['id']
            media_type = 'movie'

            if direction == 'to_fast':
                src, dst       = slow_mover / folder, fast_mover / folder
                new_svc_path   = fast_svc + '/' + folder
                new_location   = 'fast'
                relocate_after = relocate_ts
            else:
                src, dst       = fast_mover / folder, slow_mover / folder
                new_svc_path   = slow_svc + '/' + folder
                new_location   = 'slow'
                relocate_after = None

            log.info('Manual move: "%s" (%s)', title, direction)
            q_idx = queue.add_item(f'manual_radarr_{item_id}', title, 'radarr',
                                   name, direction, str(src), str(dst))

            if not src.exists():
                log.warning('Source not found on disk: %s', src)
                queue.skip(q_idx, 'Source not found on disk')
                db.execute("UPDATE pending_moves SET status='error' WHERE id=?", (pm_id,))
                db.commit()
                continue

            size_bytes = folder_size_bytes(src)
            queue.start(q_idx)
            t_start = time.time()
            ok, summary = rsync_move(src, dst, log)
            t_taken = int(time.time() - t_start)
            if ok:
                queue.done(q_idx, summary)
                svc_updated = update_radarr_path(item, new_svc_path, settings, log)
                if svc_updated:
                    rescan_movie(item_id, settings, log)
                plex_ok = notify_plex(settings, new_svc_path, log)
                db_record_history(db, media_type, title, external_id, service,
                                  mapping_id, folder, direction, src, dst,
                                  'manual', svc_updated, plex_ok, notes,
                                  t_taken, size_bytes)
                db_upsert(db, media_type, title, external_id, service,
                          mapping_id, folder, new_location, 'manual', notes, relocate_after)
                db.execute("UPDATE pending_moves SET status='done' WHERE id=?", (pm_id,))
            else:
                queue.error(q_idx, 'rsync failed')
                db.execute("UPDATE pending_moves SET status='error' WHERE id=?", (pm_id,))

        db.commit()

    queue.finish()
    log.info('=' * 60)
    log.info('Manual move run complete')
    log.info('=' * 60)


if __name__ == '__main__':
    main()
