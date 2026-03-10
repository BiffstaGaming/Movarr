#!/usr/bin/env python3
"""
Tiered TV Show / Movie Storage Manager

Reads settings from /config/settings.json, then:
  1. Processes any pending manual moves from the SQLite DB
  2. Fetches watched shows/movies from Tautulli (last N days)
  3. Augments the watched set with DB-pinned items (unexpired relocate_after)
  4. For each configured path mapping:
     - Items on slow storage that ARE watched  -> rsync to fast storage
     - Items on fast storage that are NOT watched -> rsync back to slow storage
     - Items on fast that ARE watched -> extend relocate_after in DB
  5. Updates Sonarr/Radarr path and triggers a rescan after each move
"""

import json
import logging
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import threading
import time
from datetime import datetime, timedelta
from pathlib import Path

import requests

CONFIG_DIR    = Path(os.getenv('CONFIG_PATH', '/config'))
SETTINGS_FILE = CONFIG_DIR / 'settings.json'
LOG_FILE      = CONFIG_DIR / 'mover.log'
QUEUE_FILE    = CONFIG_DIR / 'queue.json'
DB_FILE       = CONFIG_DIR / 'movarr.db'
HEALTH_FILE   = CONFIG_DIR / 'health.json'


# -- Logging -------------------------------------------------------------------

def setup_logger() -> logging.Logger:
    logger = logging.getLogger('mover')
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


# -- Queue writer --------------------------------------------------------------

class QueueWriter:
    def __init__(self, mode: str):
        self._lock = threading.Lock()
        self.data = {
            'run_id':    datetime.now().strftime('%Y%m%d_%H%M%S'),
            'started':   datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'completed': None,
            'mode':      mode,
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

    def update_progress(self, idx: int, pct: int, bytes_done: int,
                        total_bytes: int, speed: str = '') -> None:
        item = self.data['items'][idx]
        item['progress_pct']  = pct
        item['bytes_done']    = bytes_done
        item['total_bytes']   = total_bytes
        if speed:
            item['speed'] = speed
        self._write()

    def finish(self) -> None:
        self.data['completed'] = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        self._write()

    def _write(self) -> None:
        with self._lock:
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
    db.execute('PRAGMA foreign_keys=ON')
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
    for sql in [
        "ALTER TABLE move_history   ADD COLUMN time_taken   INTEGER DEFAULT NULL",
        "ALTER TABLE move_history   ADD COLUMN size_on_disk INTEGER DEFAULT NULL",
        "ALTER TABLE pending_moves  ADD COLUMN title        TEXT    NOT NULL DEFAULT ''",
        "ALTER TABLE tracked_media  ADD COLUMN size_on_disk INTEGER DEFAULT NULL",
    ]:
        try:
            db.execute(sql)
        except Exception:
            pass
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


# ── Health issue tracking ─────────────────────────────────────────────────────

def _health_load() -> list:
    try:
        return json.loads(HEALTH_FILE.read_text())
    except Exception:
        return []


def health_upsert(issue_id: str, level: str, title: str, message: str) -> None:
    issues = _health_load()
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    for issue in issues:
        if issue['id'] == issue_id:
            issue.update(level=level, title=title, message=message, updated_at=now)
            try:
                HEALTH_FILE.write_text(json.dumps(issues, indent=2))
            except Exception:
                pass
            return
    issues.append({'id': issue_id, 'level': level, 'title': title,
                   'message': message, 'raised_at': now, 'updated_at': now})
    try:
        HEALTH_FILE.write_text(json.dumps(issues, indent=2))
    except Exception:
        pass


def health_clear(issue_id: str) -> None:
    issues = [i for i in _health_load() if i['id'] != issue_id]
    try:
        HEALTH_FILE.write_text(json.dumps(issues, indent=2))
    except Exception:
        pass


def _fmt_bytes(b: int) -> str:
    if b >= 1_099_511_627_776:
        return f'{b / 1_099_511_627_776:.1f} TB'
    if b >= 1_073_741_824:
        return f'{b / 1_073_741_824:.1f} GB'
    if b >= 1_048_576:
        return f'{b / 1_048_576:.1f} MB'
    return f'{b} B'


def preflight_disk(dst_base: Path, size_bytes: int, title: str,
                   log: logging.Logger) -> bool:
    """Check free space on dst_base's filesystem before a real move.

    Returns True (ok to proceed) or False (blocked — not enough space).
    Raises a health issue when blocked; clears it when space is sufficient.
    """
    issue_id = f'disk_space:{dst_base}'
    check = dst_base
    while not check.exists() and check != check.parent:
        check = check.parent
    try:
        free = shutil.disk_usage(str(check)).free
    except Exception:
        return True  # Cannot determine — allow the move
    if size_bytes > free:
        msg = (f'Cannot move "{title}" ({_fmt_bytes(size_bytes)}) to {dst_base}'
               f' — only {_fmt_bytes(free)} free')
        log.warning('DISK SPACE: %s', msg)
        health_upsert(issue_id, 'error', 'Insufficient disk space', msg)
        return False
    health_clear(issue_id)
    return True


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
              location: str, source: str, notes: str, relocate_after,
              size_on_disk: int = None) -> None:
    """Insert or update a tracked_media entry."""
    now = int(time.time())
    db.execute("""
        INSERT INTO tracked_media
            (media_type, title, external_id, service, mapping_id, folder,
             current_location, moved_at, relocate_after, source, notes,
             size_on_disk, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(external_id, service, mapping_id) DO UPDATE SET
            title            = excluded.title,
            folder           = excluded.folder,
            current_location = excluded.current_location,
            moved_at         = excluded.moved_at,
            relocate_after   = excluded.relocate_after,
            source           = excluded.source,
            notes            = excluded.notes,
            size_on_disk     = excluded.size_on_disk,
            updated_at       = excluded.updated_at
    """, (media_type, title, external_id, service, mapping_id, folder,
          location, now, relocate_after, source, notes, size_on_disk, now, now))
    db.commit()


def db_extend_relocate(db: sqlite3.Connection, external_id: int, service: str,
                       mapping_id: str, relocate_after: int) -> None:
    """Push out the relocate_after date if the item is still actively watched."""
    now = int(time.time())
    db.execute("""
        UPDATE tracked_media
        SET relocate_after = MAX(COALESCE(relocate_after, 0), ?),
            updated_at     = ?
        WHERE external_id = ? AND service = ? AND mapping_id = ?
    """, (relocate_after, now, external_id, service, mapping_id))
    db.commit()


def db_set_location(db: sqlite3.Connection, external_id: int, service: str,
                    mapping_id: str, location: str) -> None:
    """Update just the current_location (and clear relocate_after when moving to slow)."""
    now = int(time.time())
    ra  = None if location == 'slow' else db.execute(
        'SELECT relocate_after FROM tracked_media WHERE external_id=? AND service=? AND mapping_id=?',
        (external_id, service, mapping_id)).fetchone()
    # If moving to slow, clear relocate_after
    if location == 'slow':
        db.execute("""
            UPDATE tracked_media
            SET current_location='slow', moved_at=?, relocate_after=NULL, updated_at=?
            WHERE external_id=? AND service=? AND mapping_id=?
        """, (now, now, external_id, service, mapping_id))
    else:
        db.execute("""
            UPDATE tracked_media
            SET current_location='fast', moved_at=?, updated_at=?
            WHERE external_id=? AND service=? AND mapping_id=?
        """, (now, now, external_id, service, mapping_id))
    db.commit()


# -- Title normalisation -------------------------------------------------------

def normalize(title: str) -> str:
    """Lowercase, strip trailing (YYYY), collapse whitespace."""
    t = title.strip().lower()
    t = re.sub(r'\s*\(\d{4}\)\s*$', '', t)
    t = re.sub(r'\s+', ' ', t)
    return t.strip()


# -- Tautulli ------------------------------------------------------------------

def get_id_from_plex(rating_key: str, tautulli: dict, scheme: str,
                     log: logging.Logger) -> int | None:
    """Extract a TVDB or TMDB ID from Plex GUIDs via Tautulli get_metadata."""
    try:
        resp = requests.get(
            tautulli['url'].rstrip('/') + '/api/v2',
            params={'apikey': tautulli['api_key'], 'cmd': 'get_metadata',
                    'rating_key': rating_key},
            timeout=15)
        metadata = resp.json()['response']['data']
        prefix = scheme + '://'
        for guid in metadata.get('guids', []):
            if isinstance(guid, str) and guid.startswith(prefix):
                return int(guid.split('://', 1)[1])
            if isinstance(guid, dict):
                gid = guid.get('id', '')
                if gid.startswith(prefix):
                    return int(gid.split('://', 1)[1])
    except Exception as exc:
        log.debug('get_metadata (%s) failed for rating_key %s: %s', scheme, rating_key, exc)
    return None


def get_watched(settings: dict, log: logging.Logger) -> tuple:
    """Return (watched_tvdb_ids, watched_titles) for TV shows."""
    tautulli  = settings['tautulli']
    days      = int(settings.get('watched_days', 30))
    after_str = (datetime.now() - timedelta(days=days)).strftime('%Y-%m-%d')

    shows: dict = {}
    start, page = 0, 1000
    while True:
        try:
            resp = requests.get(tautulli['url'].rstrip('/') + '/api/v2',
                params={'apikey': tautulli['api_key'], 'cmd': 'get_history',
                        'media_type': 'episode', 'length': page, 'start': start,
                        'after': after_str}, timeout=30)
            records = resp.json()['response']['data']['data']
        except Exception as exc:
            log.error('Tautulli history fetch failed: %s', exc)
            break
        for r in records:
            rk = str(r.get('grandparent_rating_key', '')).strip()
            title = r.get('grandparent_title', '').strip()
            if rk and title:
                shows[rk] = title
        if len(records) < page:
            break
        start += page

    log.info('Tautulli: %d unique shows watched in last %d days', len(shows), days)
    watched_tvdb_ids: set = set()
    watched_titles:   set = set()
    for rk, title in shows.items():
        tvdb_id = get_id_from_plex(rk, tautulli, 'tvdb', log)
        if tvdb_id:
            watched_tvdb_ids.add(tvdb_id)
            log.debug('  TVDB match: %r -> tvdb=%d', title, tvdb_id)
        else:
            watched_titles.add(normalize(title))
    log.info('  Resolved via TVDB ID : %d', len(watched_tvdb_ids))
    log.info('  Fallback title match : %d', len(watched_titles))
    return watched_tvdb_ids, watched_titles


def get_watched_movies(settings: dict, log: logging.Logger) -> tuple:
    """Return (watched_tmdb_ids, watched_titles) for movies."""
    tautulli  = settings['tautulli']
    days      = int(settings.get('watched_days', 30))
    after_str = (datetime.now() - timedelta(days=days)).strftime('%Y-%m-%d')

    movies: dict = {}
    start, page = 0, 1000
    while True:
        try:
            resp = requests.get(tautulli['url'].rstrip('/') + '/api/v2',
                params={'apikey': tautulli['api_key'], 'cmd': 'get_history',
                        'media_type': 'movie', 'length': page, 'start': start,
                        'after': after_str}, timeout=30)
            records = resp.json()['response']['data']['data']
        except Exception as exc:
            log.error('Tautulli movie history fetch failed: %s', exc)
            break
        for r in records:
            rk = str(r.get('rating_key', '')).strip()
            title = r.get('title', '').strip()
            if rk and title:
                movies[rk] = title
        if len(records) < page:
            break
        start += page

    log.info('Tautulli: %d unique movies watched in last %d days', len(movies), days)
    watched_tmdb_ids: set = set()
    watched_titles:   set = set()
    for rk, title in movies.items():
        tmdb_id = get_id_from_plex(rk, tautulli, 'tmdb', log)
        if tmdb_id:
            watched_tmdb_ids.add(tmdb_id)
            log.debug('  TMDB match: %r -> tmdb=%d', title, tmdb_id)
        else:
            watched_titles.add(normalize(title))
    log.info('  Resolved via TMDB ID : %d', len(watched_tmdb_ids))
    log.info('  Fallback title match : %d', len(watched_titles))
    return watched_tmdb_ids, watched_titles


# -- Sonarr --------------------------------------------------------------------

def get_sonarr_series(settings: dict, log: logging.Logger) -> list:
    sonarr  = settings['sonarr']
    headers = {'X-Api-Key': sonarr['api_key']}
    try:
        resp   = requests.get(sonarr['url'].rstrip('/') + '/api/v3/series',
                              headers=headers, timeout=30)
        series = resp.json()
        log.info('Sonarr: %d series fetched', len(series))
        return series
    except Exception as exc:
        log.error('Sonarr series fetch failed: %s', exc)
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


def rescan_series(series_id: int, settings: dict, log: logging.Logger) -> bool:
    sonarr  = settings['sonarr']
    headers = {'X-Api-Key': sonarr['api_key'], 'Content-Type': 'application/json'}
    try:
        resp = requests.post(sonarr['url'].rstrip('/') + '/api/v3/command',
            headers=headers, json={'name': 'RescanSeries', 'seriesId': series_id},
            timeout=30)
        if resp.ok:
            log.info('Sonarr rescan triggered for series ID %d', series_id)
            return True
        log.error('Sonarr rescan failed %d', resp.status_code)
    except Exception as exc:
        log.error('Sonarr rescan exception: %s', exc)
    return False


# -- Radarr --------------------------------------------------------------------

def get_radarr_movies(settings: dict, log: logging.Logger) -> list:
    radarr = settings.get('radarr', {})
    if not radarr.get('url') or not radarr.get('api_key'):
        log.warning('Radarr URL/API key not configured -- skipping Radarr mappings')
        return []
    headers = {'X-Api-Key': radarr['api_key']}
    try:
        resp   = requests.get(radarr['url'].rstrip('/') + '/api/v3/movie',
                              headers=headers, timeout=30)
        movies = resp.json()
        log.info('Radarr: %d movies fetched', len(movies))
        return movies
    except Exception as exc:
        log.error('Radarr movies fetch failed: %s', exc)
        return []


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


def rescan_movie(movie_id: int, settings: dict, log: logging.Logger) -> bool:
    radarr  = settings['radarr']
    headers = {'X-Api-Key': radarr['api_key'], 'Content-Type': 'application/json'}
    try:
        resp = requests.post(radarr['url'].rstrip('/') + '/api/v3/command',
            headers=headers, json={'name': 'RescanMovie', 'movieId': movie_id},
            timeout=30)
        if resp.ok:
            log.info('Radarr rescan triggered for movie ID %d', movie_id)
            return True
        log.error('Radarr rescan failed %d', resp.status_code)
    except Exception as exc:
        log.error('Radarr rescan exception: %s', exc)
    return False


# -- Plex notification ---------------------------------------------------------

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


# -- Progress monitor ----------------------------------------------------------

def _watch_progress(dst: Path, total_bytes: int, stop_evt: threading.Event,
                    update_fn, interval: float = 3.0) -> None:
    """Background thread: polls dst size and calls update_fn(pct, done, speed)."""
    last_bytes = 0
    last_time  = time.time()
    while not stop_evt.wait(interval):
        done = folder_size_bytes(dst)
        now  = time.time()
        pct  = min(99, int(done * 100 / total_bytes)) if total_bytes > 0 else 0
        dt   = now - last_time
        speed_str = ''
        if dt > 0:
            bps = (done - last_bytes) / dt
            if   bps >= 1024 ** 3: speed_str = f'{bps/1024**3:.1f} GB/s'
            elif bps >= 1024 ** 2: speed_str = f'{bps/1024**2:.1f} MB/s'
            elif bps >= 1024:      speed_str = f'{bps/1024:.1f} KB/s'
        last_bytes, last_time = done, now
        update_fn(pct, done, speed_str)


# -- rsync move ----------------------------------------------------------------

def rsync_move(src: Path, dst: Path, dry_run: bool,
               log: logging.Logger, size_bytes: int = 0,
               progress_cb=None) -> tuple:
    """rsync src/ into dst/, delete src on success. Returns (ok, summary)."""
    dst.mkdir(parents=True, exist_ok=True)
    cmd = ['rsync', '-av', '--checksum', str(src) + '/', str(dst) + '/']
    if dry_run:
        cmd.insert(1, '--dry-run')

    prefix = '[DRY RUN] ' if dry_run else ''
    log.info('%srsync  %s  ->  %s', prefix, src, dst)

    stop_evt = threading.Event()
    if progress_cb and size_bytes > 0 and not dry_run:
        t = threading.Thread(target=_watch_progress,
                             args=(dst, size_bytes, stop_evt, progress_cb),
                             daemon=True)
        t.start()

    result = subprocess.run(cmd, capture_output=True, text=True)
    stop_evt.set()

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

    if not dry_run:
        try:
            shutil.rmtree(str(src))
            log.info('Deleted source: %s', src)
        except Exception as exc:
            log.error('Failed to delete source %s: %s', src, exc)
            return False, summary

    return True, summary


# -- Core mapping logic: Sonarr ------------------------------------------------

def process_mapping(mapping: dict, watched_tvdb_ids: set, tautulli_tvdb_ids: set,
                    watched_titles: set, all_series: list, settings: dict,
                    dry_run: bool, list_only: bool, days: int,
                    db: sqlite3.Connection, queue: QueueWriter,
                    log: logging.Logger) -> None:

    name        = mapping.get('name', 'unnamed')
    mapping_id  = mapping.get('id', name)
    slow_mover  = Path(mapping['slow_path_mover'])
    fast_mover  = Path(mapping['fast_path_mover'])
    slow_sonarr = mapping['slow_path_sonarr'].rstrip('/')
    fast_sonarr = mapping['fast_path_sonarr'].rstrip('/')

    now         = int(time.time())
    relocate_ts = now + (days * 86400)

    log.info('=' * 60)
    log.info('Mapping (Sonarr): %s', name)
    log.info('  Slow  mover=%s  sonarr=%s', slow_mover, slow_sonarr)
    log.info('  Fast  mover=%s  sonarr=%s', fast_mover, fast_sonarr)

    relevant = [s for s in all_series
                if s['path'].startswith(slow_sonarr + '/')
                or s['path'].startswith(fast_sonarr + '/')]
    log.info('  %d Sonarr series in this mapping\'s paths', len(relevant))

    to_fast, to_slow, to_extend = [], [], []
    for series in relevant:
        path      = series['path'].rstrip('/')
        folder    = Path(path).name
        on_slow   = path.startswith(slow_sonarr + '/')
        on_fast   = path.startswith(fast_sonarr + '/')
        tvdb_id   = series.get('tvdbId')
        # watched_tvdb_ids includes DB-pinned items; tautulli_tvdb_ids is Tautulli-only
        is_active = (tvdb_id and tvdb_id in watched_tvdb_ids) or \
                    (normalize(folder) in watched_titles)
        from_tautulli = (tvdb_id and tvdb_id in tautulli_tvdb_ids) or \
                        (normalize(folder) in watched_titles)

        if on_slow and is_active:
            to_fast.append(series)
        elif on_fast and not is_active:
            to_slow.append(series)
        elif on_fast and from_tautulli:
            # Watched and already on fast: extend the window
            to_extend.append(series)

    log.info('  -> Move to fast: %d', len(to_fast))
    log.info('  <- Move to slow: %d', len(to_slow))
    log.info('  ~~ Extend window: %d', len(to_extend))

    # Extend relocate_after for actively-watched shows already on fast
    if not list_only:
        for series in to_extend:
            tvdb_id = series.get('tvdbId')
            if tvdb_id:
                db_extend_relocate(db, tvdb_id, 'sonarr', mapping_id, relocate_ts)
                log.debug('  Extended window for: %s', series['title'])

    q_fast = [queue.add_item('sonarr_' + str(s['id']), s['title'], 'sonarr', name, 'to_fast',
               str(slow_mover / Path(s['path']).name),
               str(fast_mover / Path(s['path']).name)) for s in to_fast]
    q_slow = [queue.add_item('sonarr_' + str(s['id']), s['title'], 'sonarr', name, 'to_slow',
               str(fast_mover / Path(s['path']).name),
               str(slow_mover / Path(s['path']).name)) for s in to_slow]

    if list_only:
        log.info('  LIST ONLY -- skipping rsync and Sonarr updates')
        for idx in q_fast + q_slow:
            queue.skip(idx, 'List Only Mode')
        return

    def do_move(series, src_base, dst_base, new_sonarr_base, location, q_idx):
        folder          = Path(series['path']).name
        src             = src_base / folder
        dst             = dst_base / folder
        new_sonarr_path = new_sonarr_base + '/' + folder
        tvdb_id         = series.get('tvdbId')
        direction       = 'to_fast' if location == 'fast' else 'to_slow'
        if not src.exists():
            log.warning('Source not found on disk, skipping: %s', src)
            queue.skip(q_idx, 'Source not found on disk')
            return
        size_bytes = folder_size_bytes(src)
        if not dry_run and not preflight_disk(dst_base, size_bytes, series['title'], log):
            queue.skip(q_idx, 'Insufficient disk space')
            return
        queue.start(q_idx)
        t_start = time.time()
        cb = lambda pct, done, spd: queue.update_progress(q_idx, pct, done, size_bytes, spd)
        ok, summary = rsync_move(src, dst, dry_run, log, size_bytes, cb)
        t_taken = int(time.time() - t_start)
        if ok:
            queue.done(q_idx, summary)
            if not dry_run:
                svc_updated = update_sonarr_path(series, new_sonarr_path, settings, log)
                if svc_updated:
                    rescan_series(series['id'], settings, log)
                plex_ok = notify_plex(settings, new_sonarr_path, log)
                ra = relocate_ts if location == 'fast' else None
                if tvdb_id:
                    db_record_history(db, 'show', series['title'], tvdb_id, 'sonarr',
                                      mapping_id, folder, direction, src, dst,
                                      'auto', svc_updated, plex_ok, '',
                                      t_taken, size_bytes)
                    db_upsert(db, 'show', series['title'], tvdb_id, 'sonarr',
                              mapping_id, folder, location, 'auto', '', ra, size_bytes)
        else:
            queue.error(q_idx, 'rsync failed')
            log.error('Move failed for: %s -- Sonarr NOT updated', folder)

    for series, q_idx in zip(to_fast, q_fast):
        do_move(series, slow_mover, fast_mover, fast_sonarr, 'fast', q_idx)
    for series, q_idx in zip(to_slow, q_slow):
        do_move(series, fast_mover, slow_mover, slow_sonarr, 'slow', q_idx)


# -- Core mapping logic: Radarr ------------------------------------------------

def process_mapping_radarr(mapping: dict, watched_tmdb_ids: set, tautulli_tmdb_ids: set,
                           watched_titles: set, all_movies: list, settings: dict,
                           dry_run: bool, list_only: bool, days: int,
                           db: sqlite3.Connection, queue: QueueWriter,
                           log: logging.Logger) -> None:

    name        = mapping.get('name', 'unnamed')
    mapping_id  = mapping.get('id', name)
    slow_mover  = Path(mapping['slow_path_mover'])
    fast_mover  = Path(mapping['fast_path_mover'])
    slow_radarr = mapping['slow_path_sonarr'].rstrip('/')
    fast_radarr = mapping['fast_path_sonarr'].rstrip('/')

    now         = int(time.time())
    relocate_ts = now + (days * 86400)

    log.info('=' * 60)
    log.info('Mapping (Radarr): %s', name)
    log.info('  Slow  mover=%s  radarr=%s', slow_mover, slow_radarr)
    log.info('  Fast  mover=%s  radarr=%s', fast_mover, fast_radarr)

    relevant = [m for m in all_movies
                if m['path'].startswith(slow_radarr + '/')
                or m['path'].startswith(fast_radarr + '/')]
    log.info('  %d Radarr movies in this mapping\'s paths', len(relevant))

    to_fast, to_slow, to_extend = [], [], []
    for movie in relevant:
        path      = movie['path'].rstrip('/')
        folder    = Path(path).name
        on_slow   = path.startswith(slow_radarr + '/')
        on_fast   = path.startswith(fast_radarr + '/')
        tmdb_id   = movie.get('tmdbId')
        is_active = (tmdb_id and tmdb_id in watched_tmdb_ids) or \
                    (normalize(folder) in watched_titles)
        from_tautulli = (tmdb_id and tmdb_id in tautulli_tmdb_ids) or \
                        (normalize(folder) in watched_titles)

        if on_slow and is_active:
            to_fast.append(movie)
        elif on_fast and not is_active:
            to_slow.append(movie)
        elif on_fast and from_tautulli:
            to_extend.append(movie)

    log.info('  -> Move to fast: %d', len(to_fast))
    log.info('  <- Move to slow: %d', len(to_slow))
    log.info('  ~~ Extend window: %d', len(to_extend))

    if not list_only:
        for movie in to_extend:
            tmdb_id = movie.get('tmdbId')
            if tmdb_id:
                db_extend_relocate(db, tmdb_id, 'radarr', mapping_id, relocate_ts)
                log.debug('  Extended window for: %s', movie['title'])

    q_fast = [queue.add_item('radarr_' + str(m['id']), m['title'], 'radarr', name, 'to_fast',
               str(slow_mover / Path(m['path']).name),
               str(fast_mover / Path(m['path']).name)) for m in to_fast]
    q_slow = [queue.add_item('radarr_' + str(m['id']), m['title'], 'radarr', name, 'to_slow',
               str(fast_mover / Path(m['path']).name),
               str(slow_mover / Path(m['path']).name)) for m in to_slow]

    if list_only:
        log.info('  LIST ONLY -- skipping rsync and Radarr updates')
        for idx in q_fast + q_slow:
            queue.skip(idx, 'List Only Mode')
        return

    def do_move(movie, src_base, dst_base, new_radarr_base, location, q_idx):
        folder          = Path(movie['path']).name
        src             = src_base / folder
        dst             = dst_base / folder
        new_radarr_path = new_radarr_base + '/' + folder
        tmdb_id         = movie.get('tmdbId')
        direction       = 'to_fast' if location == 'fast' else 'to_slow'
        if not src.exists():
            log.warning('Source not found on disk, skipping: %s', src)
            queue.skip(q_idx, 'Source not found on disk')
            return
        size_bytes = folder_size_bytes(src)
        if not dry_run and not preflight_disk(dst_base, size_bytes, movie['title'], log):
            queue.skip(q_idx, 'Insufficient disk space')
            return
        queue.start(q_idx)
        t_start = time.time()
        cb = lambda pct, done, spd: queue.update_progress(q_idx, pct, done, size_bytes, spd)
        ok, summary = rsync_move(src, dst, dry_run, log, size_bytes, cb)
        t_taken = int(time.time() - t_start)
        if ok:
            queue.done(q_idx, summary)
            if not dry_run:
                svc_updated = update_radarr_path(movie, new_radarr_path, settings, log)
                if svc_updated:
                    rescan_movie(movie['id'], settings, log)
                plex_ok = notify_plex(settings, new_radarr_path, log)
                ra = relocate_ts if location == 'fast' else None
                if tmdb_id:
                    db_record_history(db, 'movie', movie['title'], tmdb_id, 'radarr',
                                      mapping_id, folder, direction, src, dst,
                                      'auto', svc_updated, plex_ok, '',
                                      t_taken, size_bytes)
                    db_upsert(db, 'movie', movie['title'], tmdb_id, 'radarr',
                              mapping_id, folder, location, 'auto', '', ra, size_bytes)
        else:
            queue.error(q_idx, 'rsync failed')
            log.error('Move failed for: %s -- Radarr NOT updated', folder)

    for movie, q_idx in zip(to_fast, q_fast):
        do_move(movie, slow_mover, fast_mover, fast_radarr, 'fast', q_idx)
    for movie, q_idx in zip(to_slow, q_slow):
        do_move(movie, fast_mover, slow_mover, slow_radarr, 'slow', q_idx)


# -- Entry point ---------------------------------------------------------------

def main() -> None:
    log = setup_logger()
    log.info('=' * 60)
    log.info('Mover run started')
    log.info('=' * 60)

    if not SETTINGS_FILE.exists():
        log.error('Settings file not found: %s', SETTINGS_FILE)
        sys.exit(1)

    try:
        settings = load_settings()
    except Exception as exc:
        log.error('Failed to load settings: %s', exc)
        sys.exit(1)

    dry_run   = settings.get('dry_run', True)
    list_only = settings.get('list_only', False)
    days      = int(settings.get('watched_days', 30))

    if list_only:
        mode = 'list_only'
        log.info('LIST ONLY MODE -- decisions logged, no rsync or service updates')
    elif dry_run:
        mode = 'dry_run'
        log.info('DRY RUN MODE -- rsync runs but no files will be moved or deleted')
    else:
        mode = 'real'

    queue = QueueWriter(mode)

    # Open SQLite DB
    try:
        db = db_connect()
        log.info('SQLite DB opened: %s', DB_FILE)
    except Exception as exc:
        log.error('Failed to open SQLite DB: %s', exc)
        db = None

    mappings     = settings.get('path_mappings', [])
    needs_sonarr = any(m.get('service') == 'sonarr' for m in mappings)
    needs_radarr = any(m.get('service') == 'radarr' for m in mappings)

    # -- Step 1: Load Sonarr/Radarr data ----------------------------------------
    all_series, all_movies = [], []

    if needs_sonarr:
        all_series = get_sonarr_series(settings, log)
        if not all_series:
            log.error('No series returned from Sonarr -- skipping Sonarr mappings')

    if needs_radarr:
        all_movies = get_radarr_movies(settings, log)

    # -- Step 2: Load Tautulli watched sets ------------------------------------
    tautulli_tvdb_ids,    watched_titles       = set(), set()
    tautulli_tmdb_ids,    watched_movie_titles = set(), set()

    if needs_sonarr:
        tautulli_tvdb_ids, watched_titles = get_watched(settings, log)
    if needs_radarr:
        tautulli_tmdb_ids, watched_movie_titles = get_watched_movies(settings, log)

    # -- Step 3: Load DB-pinned IDs and augment watched sets -------------------
    if db:
        now = int(time.time())
        pinned_tvdb = {row['external_id'] for row in db.execute(
            "SELECT external_id FROM tracked_media "
            "WHERE service='sonarr' AND current_location='fast' "
            "AND (relocate_after IS NULL OR relocate_after > ?)", (now,))}
        pinned_tmdb = {row['external_id'] for row in db.execute(
            "SELECT external_id FROM tracked_media "
            "WHERE service='radarr' AND current_location='fast' "
            "AND (relocate_after IS NULL OR relocate_after > ?)", (now,))}

        if pinned_tvdb:
            log.info('DB: %d Sonarr items pinned (unexpired relocate_after)', len(pinned_tvdb))
        if pinned_tmdb:
            log.info('DB: %d Radarr items pinned (unexpired relocate_after)', len(pinned_tmdb))
    else:
        pinned_tvdb, pinned_tmdb = set(), set()

    # Augment: pinned items count as "watched" so they stay on fast
    watched_tvdb_ids = tautulli_tvdb_ids | pinned_tvdb
    watched_tmdb_ids = tautulli_tmdb_ids | pinned_tmdb

    # -- Step 4: Run auto mapping logic ----------------------------------------
    for mapping in mappings:
        service = mapping.get('service', 'sonarr')
        try:
            if service == 'sonarr' and all_series:
                process_mapping(mapping, watched_tvdb_ids, tautulli_tvdb_ids,
                                watched_titles, all_series, settings,
                                dry_run, list_only, days, db or sqlite3.connect(':memory:'),
                                queue, log)
            elif service == 'radarr':
                process_mapping_radarr(mapping, watched_tmdb_ids, tautulli_tmdb_ids,
                                       watched_movie_titles, all_movies, settings,
                                       dry_run, list_only, days, db or sqlite3.connect(':memory:'),
                                       queue, log)
        except Exception as exc:
            log.error("Error in mapping '%s': %s", mapping.get('name'), exc, exc_info=True)

    queue.finish()
    log.info('=' * 60)
    log.info('Mover run complete')
    log.info('=' * 60)


if __name__ == '__main__':
    main()
