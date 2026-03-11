#!/usr/bin/env python3
"""
Location Reconciliation Script

Reads all tracked_media rows and verifies each item is actually present
on disk at the location Movarr believes it to be. If an item is found
at the other location, the DB is corrected. Results are written to
/config/reconcile_results.json.
"""

import json
import logging
import os
import sqlite3
import sys
import time
from datetime import datetime
from pathlib import Path

CONFIG_DIR    = Path(os.getenv('CONFIG_PATH', '/config'))
SETTINGS_FILE = CONFIG_DIR / 'settings.json'
DB_FILE       = CONFIG_DIR / 'movarr.db'
LOG_FILE      = CONFIG_DIR / 'mover.log'
RESULTS_FILE  = CONFIG_DIR / 'reconcile_results.json'


def setup_logger() -> logging.Logger:
    logger = logging.getLogger('reconcile')
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


def db_connect() -> sqlite3.Connection:
    db = sqlite3.connect(str(DB_FILE))
    db.row_factory = sqlite3.Row
    db.execute('PRAGMA journal_mode=WAL')
    return db


def main() -> None:
    log = setup_logger()
    log.info('=' * 60)
    log.info('Reconciliation run started')
    log.info('=' * 60)

    try:
        with open(SETTINGS_FILE) as f:
            settings = json.load(f)
    except Exception as exc:
        log.error('Failed to load settings: %s', exc)
        sys.exit(1)

    try:
        db = db_connect()
    except Exception as exc:
        log.error('Failed to open DB: %s', exc)
        sys.exit(1)

    rows = db.execute(
        "SELECT * FROM tracked_media ORDER BY title"
    ).fetchall()

    mappings = {m['id']: m for m in settings.get('path_mappings', [])}

    results = {
        'run_at':    datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'total':     len(rows),
        'ok':        0,
        'corrected': 0,
        'missing':   0,
        'skipped':   0,
        'items':     [],
    }

    log.info('Checking %d tracked items...', len(rows))

    for row in rows:
        mapping_id = row['mapping_id']
        mapping    = mappings.get(mapping_id)
        if not mapping:
            log.warning('"%s": mapping "%s" not found — skipping', row['title'], mapping_id)
            results['skipped'] += 1
            results['items'].append({
                'title':    row['title'],
                'service':  row['service'],
                'mapping':  mapping_id,
                'stored_location': row['current_location'],
                'result':   'skipped',
                'reason':   f'Mapping "{mapping_id}" not in settings',
            })
            continue

        slow = Path(mapping.get('slow_path_mover', ''))
        fast = Path(mapping.get('fast_path_mover', ''))
        folder = row['folder']

        if not folder:
            results['skipped'] += 1
            results['items'].append({
                'title':    row['title'],
                'service':  row['service'],
                'mapping':  mapping_id,
                'stored_location': row['current_location'],
                'result':   'skipped',
                'reason':   'No folder name recorded',
            })
            continue

        slow_path = slow / folder
        fast_path = fast / folder
        stored    = row['current_location']

        if stored == 'fast':
            expected, other = fast_path, slow_path
            other_location  = 'slow'
        elif stored == 'slow':
            expected, other = slow_path, fast_path
            other_location  = 'fast'
        else:
            results['skipped'] += 1
            results['items'].append({
                'title':    row['title'],
                'service':  row['service'],
                'mapping':  mapping_id,
                'stored_location': stored,
                'result':   'skipped',
                'reason':   f'Unknown location "{stored}"',
            })
            continue

        if expected.exists():
            log.debug('OK: "%s" at %s (%s)', row['title'], expected, stored)
            results['ok'] += 1
            results['items'].append({
                'title':    row['title'],
                'service':  row['service'],
                'mapping':  mapping_id,
                'stored_location': stored,
                'result':   'ok',
                'path':     str(expected),
            })
        elif other.exists():
            log.warning('CORRECTING "%s": DB says %s but found at %s (%s)',
                        row['title'], stored, other, other_location)
            db.execute(
                "UPDATE tracked_media SET current_location=?, updated_at=? "
                "WHERE id=?",
                (other_location, int(time.time()), row['id'])
            )
            db.commit()
            results['corrected'] += 1
            results['items'].append({
                'title':    row['title'],
                'service':  row['service'],
                'mapping':  mapping_id,
                'stored_location': stored,
                'actual_location': other_location,
                'result':   'corrected',
                'path':     str(other),
            })
        else:
            log.warning('MISSING "%s": not found at %s or %s', row['title'], expected, other)
            results['missing'] += 1
            results['items'].append({
                'title':    row['title'],
                'service':  row['service'],
                'mapping':  mapping_id,
                'stored_location': stored,
                'result':   'missing',
                'checked_paths': [str(expected), str(other)],
            })

    log.info('Reconciliation complete — ok=%d corrected=%d missing=%d skipped=%d',
             results['ok'], results['corrected'], results['missing'], results['skipped'])

    try:
        RESULTS_FILE.write_text(json.dumps(results, indent=2))
        log.info('Results written to %s', RESULTS_FILE)
    except Exception as exc:
        log.error('Failed to write results: %s', exc)

    log.info('=' * 60)
    log.info('Reconciliation run complete')
    log.info('=' * 60)


if __name__ == '__main__':
    main()
