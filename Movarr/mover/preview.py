#!/usr/bin/env python3
"""
Move Preview Script

Performs the same watched/unwatched calculation as mover.py but makes NO
changes to disk, Sonarr/Radarr, or the database. Results are written to
/config/preview.json so that preview.php can display them.
"""

import json
import logging
import os
import re
import shutil
import sqlite3
import sys
import time
from datetime import datetime, timedelta
from pathlib import Path

import requests

CONFIG_DIR    = Path(os.getenv('CONFIG_PATH', '/config'))
SETTINGS_FILE = CONFIG_DIR / 'settings.json'
LOG_FILE      = CONFIG_DIR / 'mover.log'
DB_FILE       = CONFIG_DIR / 'movarr.db'
PREVIEW_FILE  = CONFIG_DIR / 'preview.json'


def setup_logger() -> logging.Logger:
    logger = logging.getLogger('preview')
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


def normalize(title: str) -> str:
    t = title.strip().lower()
    t = re.sub(r'\s*\(\d{4}\)\s*$', '', t)
    t = re.sub(r'\s+', ' ', t)
    return t.strip()


def _fmt_bytes(b: int) -> str:
    if b >= 1_099_511_627_776:
        return f'{b / 1_099_511_627_776:.1f} TB'
    if b >= 1_073_741_824:
        return f'{b / 1_073_741_824:.1f} GB'
    if b >= 1_048_576:
        return f'{b / 1_048_576:.1f} MB'
    return f'{b} B'


def get_id_from_plex(rating_key: str, tautulli: dict, scheme: str,
                     log: logging.Logger) -> int | None:
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
    tautulli  = settings['tautulli']
    days      = int(settings.get('watched_days', 30))
    min_count = int(settings.get('min_watch_count', 1))
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
                if rk in shows:
                    shows[rk][1] += 1
                else:
                    shows[rk] = [title, 1]
        if len(records) < page:
            break
        start += page

    watched_tvdb_ids: set = set()
    watched_titles:   set = set()
    for rk, (title, count) in shows.items():
        if count < min_count:
            continue
        tvdb_id = get_id_from_plex(rk, tautulli, 'tvdb', log)
        if tvdb_id:
            watched_tvdb_ids.add(tvdb_id)
        else:
            watched_titles.add(normalize(title))
    return watched_tvdb_ids, watched_titles


def get_watched_movies(settings: dict, log: logging.Logger) -> tuple:
    tautulli  = settings['tautulli']
    days      = int(settings.get('watched_days', 30))
    min_count = int(settings.get('min_watch_count', 1))
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
                if rk in movies:
                    movies[rk][1] += 1
                else:
                    movies[rk] = [title, 1]
        if len(records) < page:
            break
        start += page

    watched_tmdb_ids: set = set()
    watched_titles:   set = set()
    for rk, (title, count) in movies.items():
        if count < min_count:
            continue
        tmdb_id = get_id_from_plex(rk, tautulli, 'tmdb', log)
        if tmdb_id:
            watched_tmdb_ids.add(tmdb_id)
        else:
            watched_titles.add(normalize(title))
    return watched_tmdb_ids, watched_titles


def preview_mapping_sonarr(mapping: dict, watched_tvdb_ids: set, watched_titles: set,
                           all_series: list, days: int,
                           db: sqlite3.Connection, log: logging.Logger) -> dict:
    name        = mapping.get('name', 'unnamed')
    mapping_id  = mapping.get('id', name)
    slow_sonarr = mapping['slow_path_sonarr'].rstrip('/')
    fast_sonarr = mapping['fast_path_sonarr'].rstrip('/')
    slow_mover  = Path(mapping['slow_path_mover'])
    fast_mover  = Path(mapping['fast_path_mover'])

    now         = int(time.time())
    relocate_ts = now + (days * 86400)

    relevant = [s for s in all_series
                if s['path'].startswith(slow_sonarr + '/')
                or s['path'].startswith(fast_sonarr + '/')]

    # Load pinned IDs
    pinned_tvdb = {row['external_id'] for row in db.execute(
        "SELECT external_id FROM tracked_media "
        "WHERE service='sonarr' AND mapping_id=? AND current_location='fast' "
        "AND (relocate_after IS NULL OR relocate_after > ?)",
        (mapping_id, now))}

    all_watched = watched_tvdb_ids | pinned_tvdb

    to_fast, to_slow, to_extend = [], [], []
    for series in relevant:
        path    = series['path'].rstrip('/')
        folder  = Path(path).name
        on_slow = path.startswith(slow_sonarr + '/')
        on_fast = path.startswith(fast_sonarr + '/')
        tvdb_id = series.get('tvdbId')
        active  = (tvdb_id and tvdb_id in all_watched) or (normalize(folder) in watched_titles)
        from_t  = (tvdb_id and tvdb_id in watched_tvdb_ids) or (normalize(folder) in watched_titles)

        size = series.get('statistics', {}).get('sizeOnDisk', 0)
        entry = {'title': series['title'], 'folder': folder, 'size_bytes': size,
                 'size_human': _fmt_bytes(size)}

        if on_slow and active:
            src = slow_mover / folder
            entry['src_path'] = str(src)
            entry['disk_ok']  = src.exists()
            to_fast.append(entry)
        elif on_fast and not active:
            src = fast_mover / folder
            entry['src_path'] = str(src)
            entry['disk_ok']  = src.exists()
            to_slow.append(entry)
        elif on_fast and from_t:
            to_extend.append(entry)

    return {
        'mapping': name, 'service': 'sonarr',
        'to_fast':   to_fast,
        'to_slow':   to_slow,
        'to_extend': to_extend,
    }


def preview_mapping_radarr(mapping: dict, watched_tmdb_ids: set, watched_titles: set,
                            all_movies: list, days: int,
                            db: sqlite3.Connection, log: logging.Logger) -> dict:
    name        = mapping.get('name', 'unnamed')
    mapping_id  = mapping.get('id', name)
    slow_radarr = mapping['slow_path_radarr'].rstrip('/')
    fast_radarr = mapping['fast_path_radarr'].rstrip('/')
    slow_mover  = Path(mapping['slow_path_mover'])
    fast_mover  = Path(mapping['fast_path_mover'])

    now = int(time.time())

    relevant = [m for m in all_movies
                if m['path'].startswith(slow_radarr + '/')
                or m['path'].startswith(fast_radarr + '/')]

    pinned_tmdb = {row['external_id'] for row in db.execute(
        "SELECT external_id FROM tracked_media "
        "WHERE service='radarr' AND mapping_id=? AND current_location='fast' "
        "AND (relocate_after IS NULL OR relocate_after > ?)",
        (mapping_id, now))}

    all_watched = watched_tmdb_ids | pinned_tmdb

    to_fast, to_slow, to_extend = [], [], []
    for movie in relevant:
        path    = movie['path'].rstrip('/')
        folder  = Path(path).name
        on_slow = path.startswith(slow_radarr + '/')
        on_fast = path.startswith(fast_radarr + '/')
        tmdb_id = movie.get('tmdbId')
        active  = (tmdb_id and tmdb_id in all_watched) or (normalize(folder) in watched_titles)
        from_t  = (tmdb_id and tmdb_id in watched_tmdb_ids) or (normalize(folder) in watched_titles)

        size  = movie.get('sizeOnDisk', 0)
        entry = {'title': movie['title'], 'folder': folder, 'size_bytes': size,
                 'size_human': _fmt_bytes(size)}

        if on_slow and active:
            src = slow_mover / folder
            entry['src_path'] = str(src)
            entry['disk_ok']  = src.exists()
            to_fast.append(entry)
        elif on_fast and not active:
            src = fast_mover / folder
            entry['src_path'] = str(src)
            entry['disk_ok']  = src.exists()
            to_slow.append(entry)
        elif on_fast and from_t:
            to_extend.append(entry)

    return {
        'mapping': name, 'service': 'radarr',
        'to_fast':   to_fast,
        'to_slow':   to_slow,
        'to_extend': to_extend,
    }


def main() -> None:
    log = setup_logger()
    log.info('=' * 60)
    log.info('Preview run started')
    log.info('=' * 60)

    try:
        with open(SETTINGS_FILE) as f:
            settings = json.load(f)
    except Exception as exc:
        log.error('Failed to load settings: %s', exc)
        sys.exit(1)

    try:
        db = sqlite3.connect(str(DB_FILE))
        db.row_factory = sqlite3.Row
        db.execute('PRAGMA journal_mode=WAL')
    except Exception as exc:
        log.error('Failed to open DB: %s', exc)
        sys.exit(1)

    days     = int(settings.get('watched_days', 30))
    mappings = settings.get('path_mappings', [])

    sonarr = settings.get('sonarr', {})
    radarr = settings.get('radarr', {})
    has_sonarr = bool(sonarr.get('url') and sonarr.get('api_key'))
    has_radarr = bool(radarr.get('url') and radarr.get('api_key'))

    all_series, all_movies = [], []
    tautulli_tvdb_ids, watched_titles       = set(), set()
    tautulli_tmdb_ids, watched_movie_titles = set(), set()

    if has_sonarr:
        try:
            resp = requests.get(sonarr['url'].rstrip('/') + '/api/v3/series',
                                headers={'X-Api-Key': sonarr['api_key']}, timeout=30)
            all_series = resp.json()
            log.info('Sonarr: %d series', len(all_series))
        except Exception as exc:
            log.error('Sonarr fetch failed: %s', exc)
        tautulli_tvdb_ids, watched_titles = get_watched(settings, log)

    if has_radarr:
        try:
            resp = requests.get(radarr['url'].rstrip('/') + '/api/v3/movie',
                                headers={'X-Api-Key': radarr['api_key']}, timeout=30)
            all_movies = resp.json()
            log.info('Radarr: %d movies', len(all_movies))
        except Exception as exc:
            log.error('Radarr fetch failed: %s', exc)
        tautulli_tmdb_ids, watched_movie_titles = get_watched_movies(settings, log)

    results = []
    for mapping in mappings:
        if all_series:
            try:
                results.append(preview_mapping_sonarr(
                    mapping, tautulli_tvdb_ids, watched_titles, all_series, days, db, log))
            except Exception as exc:
                log.error("Error previewing sonarr mapping '%s': %s", mapping.get('name'), exc, exc_info=True)
        if all_movies:
            try:
                results.append(preview_mapping_radarr(
                    mapping, tautulli_tmdb_ids, watched_movie_titles, all_movies, days, db, log))
            except Exception as exc:
                log.error("Error previewing radarr mapping '%s': %s", mapping.get('name'), exc, exc_info=True)

    output = {
        'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'watched_days': days,
        'mappings':     results,
    }

    try:
        PREVIEW_FILE.write_text(json.dumps(output, indent=2))
        log.info('Preview written to %s', PREVIEW_FILE)
    except Exception as exc:
        log.error('Failed to write preview: %s', exc)

    total_fast = sum(len(r['to_fast']) for r in results)
    total_slow = sum(len(r['to_slow']) for r in results)
    log.info('Preview: %d to fast, %d to slow across %d mapping(s)',
             total_fast, total_slow, len(results))
    log.info('=' * 60)
    log.info('Preview run complete')
    log.info('=' * 60)


if __name__ == '__main__':
    main()
