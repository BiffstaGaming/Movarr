#!/usr/bin/env python3
"""
Tiered TV Show Storage Manager

Reads settings from /config/settings.json, then:
  1. Fetches watched show titles from Tautulli (last N days)
  2. Fetches all series from Sonarr
  3. For each configured path mapping:
     - Shows on slow storage that ARE watched  → rsync to fast storage
     - Shows on fast storage that are NOT watched → rsync back to slow storage
  4. Updates Sonarr's path for each moved series and triggers a rescan

Matching uses the folder name on disk (extracted from Sonarr's recorded path),
normalized to lowercase with year suffixes stripped, and compared against
Tautulli's grandparent_title values.
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


# ── Logging ───────────────────────────────────────────────────────────────────

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


# ── Settings ──────────────────────────────────────────────────────────────────

def load_settings() -> dict:
    with open(SETTINGS_FILE) as f:
        return json.load(f)


# ── Title normalisation ───────────────────────────────────────────────────────

def normalize(title: str) -> str:
    """Lowercase, strip trailing (YYYY), collapse whitespace."""
    t = title.strip().lower()
    t = re.sub(r'\s*\(\d{4}\)\s*$', '', t)
    t = re.sub(r'\s+', ' ', t)
    return t.strip()


# ── Tautulli ──────────────────────────────────────────────────────────────────

def get_tvdb_id_from_plex(rating_key: str, tautulli: dict,
                           log: logging.Logger) -> int | None:
    """Call Tautulli get_metadata and extract the TVDB ID from Plex GUIDs."""
    try:
        resp = requests.get(
            f"{tautulli['url'].rstrip('/')}/api/v2",
            params={'apikey': tautulli['api_key'], 'cmd': 'get_metadata',
                    'rating_key': rating_key},
            timeout=15)
        metadata = resp.json()['response']['data']
        for guid in metadata.get('guids', []):
            # guid format: "tvdb://12345"
            if isinstance(guid, str) and guid.startswith('tvdb://'):
                return int(guid.split('://', 1)[1])
            # some Plex/Tautulli versions return {'id': 'tvdb://12345'}
            if isinstance(guid, dict):
                gid = guid.get('id', '')
                if gid.startswith('tvdb://'):
                    return int(gid.split('://', 1)[1])
    except Exception as exc:
        log.debug(f"get_metadata failed for rating_key {rating_key}: {exc}")
    return None


def get_watched(settings: dict, log: logging.Logger) -> tuple[set[int], set[str]]:
    """
    Return:
      watched_tvdb_ids  – set of TVDB IDs (int) resolved via Plex metadata
      watched_titles    – set of normalized titles as fallback for unresolved shows
    """
    tautulli  = settings['tautulli']
    days      = int(settings.get('watched_days', 30))
    after_str = (datetime.now() - timedelta(days=days)).strftime('%Y-%m-%d')

    # Collect unique (rating_key, title) pairs from history
    shows: dict[str, str] = {}   # rating_key → grandparent_title
    start, page = 0, 1000

    while True:
        params = {
            'apikey':     tautulli['api_key'],
            'cmd':        'get_history',
            'media_type': 'episode',
            'length':     page,
            'start':      start,
            'after':      after_str,
        }
        try:
            resp    = requests.get(f"{tautulli['url'].rstrip('/')}/api/v2",
                                   params=params, timeout=30)
            records = resp.json()['response']['data']['data']
        except Exception as exc:
            log.error(f"Tautulli history fetch failed: {exc}")
            break

        for r in records:
            rk    = str(r.get('grandparent_rating_key', '')).strip()
            title = r.get('grandparent_title', '').strip()
            if rk and title:
                shows[rk] = title

        if len(records) < page:
            break
        start += page

    log.info(f"Tautulli: {len(shows)} unique shows watched in last {days} days")

    # Resolve TVDB IDs where possible
    watched_tvdb_ids: set[int] = set()
    watched_titles:   set[str] = set()
    unresolved: list[str]      = []

    for rk, title in shows.items():
        tvdb_id = get_tvdb_id_from_plex(rk, tautulli, log)
        if tvdb_id:
            watched_tvdb_ids.add(tvdb_id)
            log.debug(f"  TVDB match: '{title}' → tvdb={tvdb_id}")
        else:
            watched_titles.add(normalize(title))
            unresolved.append(title)

    log.info(f"  Resolved via TVDB ID : {len(watched_tvdb_ids)}")
    log.info(f"  Fallback title match : {len(watched_titles)}")
    if unresolved:
        log.debug(f"  Unresolved titles    : {sorted(unresolved)}")

    return watched_tvdb_ids, watched_titles


# ── Sonarr ────────────────────────────────────────────────────────────────────

def get_sonarr_series(settings: dict, log: logging.Logger) -> list[dict]:
    sonarr  = settings['sonarr']
    headers = {'X-Api-Key': sonarr['api_key']}
    try:
        resp   = requests.get(f"{sonarr['url'].rstrip('/')}/api/v3/series",
                              headers=headers, timeout=30)
        series = resp.json()
        log.info(f"Sonarr: {len(series)} series fetched")
        return series
    except Exception as exc:
        log.error(f"Sonarr series fetch failed: {exc}")
        return []


def update_sonarr_path(series: dict, new_path: str,
                       settings: dict, log: logging.Logger) -> bool:
    sonarr  = settings['sonarr']
    headers = {'X-Api-Key': sonarr['api_key'], 'Content-Type': 'application/json'}
    payload = dict(series)
    payload['path'] = new_path
    try:
        resp = requests.put(
            f"{sonarr['url'].rstrip('/')}/api/v3/series/{series['id']}",
            headers=headers, json=payload, timeout=30)
        if resp.ok:
            log.info(f"Sonarr path updated: '{series['title']}' → {new_path}")
            return True
        log.error(f"Sonarr PUT failed {resp.status_code}: {resp.text[:300]}")
    except Exception as exc:
        log.error(f"Sonarr path update exception: {exc}")
    return False


def rescan_series(series_id: int, settings: dict, log: logging.Logger) -> bool:
    sonarr  = settings['sonarr']
    headers = {'X-Api-Key': sonarr['api_key'], 'Content-Type': 'application/json'}
    try:
        resp = requests.post(
            f"{sonarr['url'].rstrip('/')}/api/v3/command",
            headers=headers,
            json={'name': 'RescanSeries', 'seriesId': series_id},
            timeout=30)
        if resp.ok:
            log.info(f"Sonarr rescan triggered for series ID {series_id}")
            return True
        log.error(f"Sonarr rescan failed {resp.status_code}")
    except Exception as exc:
        log.error(f"Sonarr rescan exception: {exc}")
    return False


# ── rsync move ────────────────────────────────────────────────────────────────

def rsync_move(src: Path, dst: Path, dry_run: bool, log: logging.Logger) -> bool:
    """
    rsync the contents of src/ into dst/, then delete src on success.
    Uses --checksum so integrity is verified, not just timestamps.
    Trailing slash on src means rsync syncs the *contents* into dst (which
    must already exist or be created), preserving the folder name via dst.
    """
    dst.mkdir(parents=True, exist_ok=True)

    cmd = [
        'rsync',
        '-av',
        '--checksum',
        '--progress',
        f'{src}/',   # trailing slash: sync contents
        f'{dst}/',
    ]
    if dry_run:
        cmd.insert(1, '--dry-run')

    prefix = '[DRY RUN] ' if dry_run else ''
    log.info(f"{prefix}rsync  {src}  →  {dst}")

    result = subprocess.run(cmd, text=True)
    if result.returncode != 0:
        log.error(f"rsync exited {result.returncode} — source left intact")
        return False

    if not dry_run:
        try:
            shutil.rmtree(str(src))
            log.info(f"Deleted source: {src}")
        except Exception as exc:
            log.error(f"Failed to delete source {src}: {exc}")
            return False

    return True


# ── Core mapping logic ────────────────────────────────────────────────────────

def process_mapping(mapping: dict, watched_tvdb_ids: set[int],
                    watched_titles: set[str],
                    all_series: list[dict], settings: dict,
                    dry_run: bool, log: logging.Logger) -> None:

    name         = mapping.get('name', 'unnamed')
    slow_mover   = Path(mapping['slow_path_mover'])
    fast_mover   = Path(mapping['fast_path_mover'])
    slow_sonarr  = mapping['slow_path_sonarr'].rstrip('/')
    fast_sonarr  = mapping['fast_path_sonarr'].rstrip('/')

    log.info(f"{'=' * 60}")
    log.info(f"Mapping: {name}")
    log.info(f"  Slow  mover={slow_mover}  sonarr={slow_sonarr}")
    log.info(f"  Fast  mover={fast_mover}  sonarr={fast_sonarr}")

    # Find Sonarr series whose paths belong to this mapping
    relevant = [
        s for s in all_series
        if s['path'].startswith(slow_sonarr + '/') or
           s['path'].startswith(fast_sonarr + '/')
    ]
    log.info(f"  {len(relevant)} Sonarr series in this mapping's paths")

    to_fast: list[dict] = []
    to_slow: list[dict] = []
    unmatched: list[str] = []

    for series in relevant:
        path        = series['path'].rstrip('/')
        folder_name = Path(path).name
        on_slow     = path.startswith(slow_sonarr + '/')
        on_fast     = path.startswith(fast_sonarr + '/')

        # Primary: match by TVDB ID; fallback: normalized title
        tvdb_id   = series.get('tvdbId')
        by_tvdb   = tvdb_id and tvdb_id in watched_tvdb_ids
        by_title  = normalize(folder_name) in watched_titles
        is_active = by_tvdb or by_title

        if not is_active and not on_fast:
            unmatched.append(f"{folder_name} (tvdbId={tvdb_id})")

        if on_slow and is_active:
            to_fast.append(series)
        elif on_fast and not is_active:
            to_slow.append(series)

    log.info(f"  → Move to fast: {len(to_fast)}")
    for s in to_fast:
        log.info(f"      {Path(s['path']).name}")

    log.info(f"  ← Move to slow: {len(to_slow)}")
    for s in to_slow:
        log.info(f"      {Path(s['path']).name}")

    log.info(f"  ? Watched but unmatched in Sonarr: {len(unmatched)}")
    for name in sorted(unmatched):
        log.debug(f"      {name}")

    def do_move(series: dict, src_base: Path, dst_base: Path,
                new_sonarr_base: str) -> None:
        folder_name    = Path(series['path']).name
        src            = src_base / folder_name
        dst            = dst_base / folder_name
        new_sonarr_path = f"{new_sonarr_base}/{folder_name}"

        if not src.exists():
            log.warning(f"Source not found on disk, skipping: {src}")
            return

        if rsync_move(src, dst, dry_run, log):
            if not dry_run:
                if update_sonarr_path(series, new_sonarr_path, settings, log):
                    rescan_series(series['id'], settings, log)
        else:
            log.error(f"Move failed for: {folder_name} — Sonarr NOT updated")

    for series in to_fast:
        do_move(series, slow_mover, fast_mover, fast_sonarr)

    for series in to_slow:
        do_move(series, fast_mover, slow_mover, slow_sonarr)


# ── Entry point ───────────────────────────────────────────────────────────────

def main() -> None:
    log = setup_logger()
    log.info('=' * 60)
    log.info('Mover run started')
    log.info('=' * 60)

    if not SETTINGS_FILE.exists():
        log.error(f"Settings file not found: {SETTINGS_FILE}")
        sys.exit(1)

    try:
        settings = load_settings()
    except Exception as exc:
        log.error(f"Failed to load settings: {exc}")
        sys.exit(1)

    dry_run = settings.get('dry_run', True)
    if dry_run:
        log.info('DRY RUN MODE — no files will be moved or deleted')

    watched_tvdb_ids, watched_titles = get_watched(settings, log)

    all_series = get_sonarr_series(settings, log)
    if not all_series:
        log.error("No series returned from Sonarr — aborting")
        sys.exit(1)

    for mapping in settings.get('path_mappings', []):
        if mapping.get('service') == 'sonarr':
            try:
                process_mapping(mapping, watched_tvdb_ids, watched_titles,
                                all_series, settings, dry_run, log)
            except Exception as exc:
                log.error(f"Error in mapping '{mapping.get('name')}': {exc}",
                          exc_info=True)

    log.info('=' * 60)
    log.info('Mover run complete')
    log.info('=' * 60)


if __name__ == '__main__':
    main()
