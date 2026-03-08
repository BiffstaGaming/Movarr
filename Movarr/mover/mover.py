#!/usr/bin/env python3
"""
Tiered TV Show / Movie Storage Manager

Reads settings from /config/settings.json, then:
  1. Fetches watched shows/movies from Tautulli (last N days)
  2. Fetches all series from Sonarr / all movies from Radarr
  3. For each configured path mapping:
     - Items on slow storage that ARE watched  -> rsync to fast storage
     - Items on fast storage that are NOT watched -> rsync back to slow storage
  4. Updates Sonarr/Radarr path and triggers a rescan
"""

import json
import logging
import os
import re
import shutil
import subprocess
import sys
from datetime import datetime, timedelta
from pathlib import Path

import requests

CONFIG_DIR    = Path(os.getenv('CONFIG_PATH', '/config'))
SETTINGS_FILE = CONFIG_DIR / 'settings.json'
LOG_FILE      = CONFIG_DIR / 'mover.log'
QUEUE_FILE    = CONFIG_DIR / 'queue.json'


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


# -- rsync move ----------------------------------------------------------------

def rsync_move(src: Path, dst: Path, dry_run: bool,
               log: logging.Logger) -> tuple:
    """rsync src/ into dst/, delete src on success. Returns (ok, summary)."""
    dst.mkdir(parents=True, exist_ok=True)
    cmd = ['rsync', '-av', '--checksum', str(src) + '/', str(dst) + '/']
    if dry_run:
        cmd.insert(1, '--dry-run')

    prefix = '[DRY RUN] ' if dry_run else ''
    log.info('%srsync  %s  ->  %s', prefix, src, dst)

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

    if not dry_run:
        try:
            shutil.rmtree(str(src))
            log.info('Deleted source: %s', src)
        except Exception as exc:
            log.error('Failed to delete source %s: %s', src, exc)
            return False, summary

    return True, summary


# -- Core mapping logic: Sonarr ------------------------------------------------

def process_mapping(mapping: dict, watched_tvdb_ids: set, watched_titles: set,
                    all_series: list, settings: dict,
                    dry_run: bool, list_only: bool,
                    queue: QueueWriter, log: logging.Logger) -> None:

    name        = mapping.get('name', 'unnamed')
    slow_mover  = Path(mapping['slow_path_mover'])
    fast_mover  = Path(mapping['fast_path_mover'])
    slow_sonarr = mapping['slow_path_sonarr'].rstrip('/')
    fast_sonarr = mapping['fast_path_sonarr'].rstrip('/')

    log.info('=' * 60)
    log.info('Mapping (Sonarr): %s', name)
    log.info('  Slow  mover=%s  sonarr=%s', slow_mover, slow_sonarr)
    log.info('  Fast  mover=%s  sonarr=%s', fast_mover, fast_sonarr)

    relevant = [s for s in all_series
                if s['path'].startswith(slow_sonarr + '/')
                or s['path'].startswith(fast_sonarr + '/')]
    log.info('  %d Sonarr series in this mapping\'s paths', len(relevant))

    to_fast, to_slow = [], []
    for series in relevant:
        path      = series['path'].rstrip('/')
        folder    = Path(path).name
        on_slow   = path.startswith(slow_sonarr + '/')
        on_fast   = path.startswith(fast_sonarr + '/')
        tvdb_id   = series.get('tvdbId')
        is_active = (tvdb_id and tvdb_id in watched_tvdb_ids) or \
                    (normalize(folder) in watched_titles)
        if on_slow and is_active:
            to_fast.append(series)
        elif on_fast and not is_active:
            to_slow.append(series)

    log.info('  -> Move to fast: %d', len(to_fast))
    for s in to_fast:
        log.debug('      %s', Path(s['path']).name)
    log.info('  <- Move to slow: %d', len(to_slow))
    for s in to_slow:
        log.debug('      %s', Path(s['path']).name)

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

    def do_move(series, src_base, dst_base, new_sonarr_base, q_idx):
        folder          = Path(series['path']).name
        src             = src_base / folder
        dst             = dst_base / folder
        new_sonarr_path = new_sonarr_base + '/' + folder
        if not src.exists():
            log.warning('Source not found on disk, skipping: %s', src)
            queue.skip(q_idx, 'Source not found on disk')
            return
        queue.start(q_idx)
        ok, summary = rsync_move(src, dst, dry_run, log)
        if ok:
            queue.done(q_idx, summary)
            if not dry_run:
                if update_sonarr_path(series, new_sonarr_path, settings, log):
                    rescan_series(series['id'], settings, log)
        else:
            queue.error(q_idx, 'rsync failed')
            log.error('Move failed for: %s -- Sonarr NOT updated', folder)

    for series, q_idx in zip(to_fast, q_fast):
        do_move(series, slow_mover, fast_mover, fast_sonarr, q_idx)
    for series, q_idx in zip(to_slow, q_slow):
        do_move(series, fast_mover, slow_mover, slow_sonarr, q_idx)


# -- Core mapping logic: Radarr ------------------------------------------------

def process_mapping_radarr(mapping: dict, watched_tmdb_ids: set, watched_titles: set,
                           all_movies: list, settings: dict,
                           dry_run: bool, list_only: bool,
                           queue: QueueWriter, log: logging.Logger) -> None:

    name        = mapping.get('name', 'unnamed')
    slow_mover  = Path(mapping['slow_path_mover'])
    fast_mover  = Path(mapping['fast_path_mover'])
    slow_radarr = mapping['slow_path_sonarr'].rstrip('/')
    fast_radarr = mapping['fast_path_sonarr'].rstrip('/')

    log.info('=' * 60)
    log.info('Mapping (Radarr): %s', name)
    log.info('  Slow  mover=%s  radarr=%s', slow_mover, slow_radarr)
    log.info('  Fast  mover=%s  radarr=%s', fast_mover, fast_radarr)

    relevant = [m for m in all_movies
                if m['path'].startswith(slow_radarr + '/')
                or m['path'].startswith(fast_radarr + '/')]
    log.info('  %d Radarr movies in this mapping\'s paths', len(relevant))

    to_fast, to_slow = [], []
    for movie in relevant:
        path      = movie['path'].rstrip('/')
        folder    = Path(path).name
        on_slow   = path.startswith(slow_radarr + '/')
        on_fast   = path.startswith(fast_radarr + '/')
        tmdb_id   = movie.get('tmdbId')
        is_active = (tmdb_id and tmdb_id in watched_tmdb_ids) or \
                    (normalize(folder) in watched_titles)
        if on_slow and is_active:
            to_fast.append(movie)
        elif on_fast and not is_active:
            to_slow.append(movie)

    log.info('  -> Move to fast: %d', len(to_fast))
    for m in to_fast:
        log.debug('      %s', Path(m['path']).name)
    log.info('  <- Move to slow: %d', len(to_slow))
    for m in to_slow:
        log.debug('      %s', Path(m['path']).name)

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

    def do_move(movie, src_base, dst_base, new_radarr_base, q_idx):
        folder          = Path(movie['path']).name
        src             = src_base / folder
        dst             = dst_base / folder
        new_radarr_path = new_radarr_base + '/' + folder
        if not src.exists():
            log.warning('Source not found on disk, skipping: %s', src)
            queue.skip(q_idx, 'Source not found on disk')
            return
        queue.start(q_idx)
        ok, summary = rsync_move(src, dst, dry_run, log)
        if ok:
            queue.done(q_idx, summary)
            if not dry_run:
                if update_radarr_path(movie, new_radarr_path, settings, log):
                    rescan_movie(movie['id'], settings, log)
        else:
            queue.error(q_idx, 'rsync failed')
            log.error('Move failed for: %s -- Radarr NOT updated', folder)

    for movie, q_idx in zip(to_fast, q_fast):
        do_move(movie, slow_mover, fast_mover, fast_radarr, q_idx)
    for movie, q_idx in zip(to_slow, q_slow):
        do_move(movie, fast_mover, slow_mover, slow_radarr, q_idx)


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

    if list_only:
        mode = 'list_only'
        log.info('LIST ONLY MODE -- decisions logged, no rsync or service updates')
    elif dry_run:
        mode = 'dry_run'
        log.info('DRY RUN MODE -- rsync runs but no files will be moved or deleted')
    else:
        mode = 'real'

    queue = QueueWriter(mode)

    mappings     = settings.get('path_mappings', [])
    needs_sonarr = any(m.get('service') == 'sonarr' for m in mappings)
    needs_radarr = any(m.get('service') == 'radarr' for m in mappings)

    watched_tvdb_ids, watched_titles       = set(), set()
    watched_tmdb_ids, watched_movie_titles = set(), set()

    if needs_sonarr:
        watched_tvdb_ids, watched_titles = get_watched(settings, log)
    if needs_radarr:
        watched_tmdb_ids, watched_movie_titles = get_watched_movies(settings, log)

    all_series, all_movies = [], []

    if needs_sonarr:
        all_series = get_sonarr_series(settings, log)
        if not all_series:
            log.error('No series returned from Sonarr -- skipping Sonarr mappings')
    if needs_radarr:
        all_movies = get_radarr_movies(settings, log)

    for mapping in mappings:
        service = mapping.get('service', 'sonarr')
        try:
            if service == 'sonarr' and all_series:
                process_mapping(mapping, watched_tvdb_ids, watched_titles,
                                all_series, settings, dry_run, list_only, queue, log)
            elif service == 'radarr':
                process_mapping_radarr(mapping, watched_tmdb_ids, watched_movie_titles,
                                       all_movies, settings, dry_run, list_only, queue, log)
        except Exception as exc:
            log.error("Error in mapping '%s': %s", mapping.get('name'), exc, exc_info=True)

    queue.finish()
    log.info('=' * 60)
    log.info('Mover run complete')
    log.info('=' * 60)


if __name__ == '__main__':
    main()
