#!/usr/bin/env python3
"""
Scheduler for the mover.

- Reads cron_schedule from /config/settings.json on every loop tick so that
  schedule changes made via the UI take effect without restarting the container.
- Watches for /config/.trigger (written by the web UI "Run Now" button) and
  fires an immediate run when found.
- Falls back to running once per day at 03:00 if croniter is unavailable or
  the cron expression is invalid.
"""

import json
import logging
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

try:
    from croniter import croniter
    HAS_CRONITER = True
except ImportError:
    HAS_CRONITER = False

CONFIG_DIR    = Path(__file__).parent.parent / 'config'  # overridden by env
import os
CONFIG_DIR    = Path(os.getenv('CONFIG_PATH', '/config'))
TRIGGER_FILE        = CONFIG_DIR / '.trigger'
MANUAL_TRIGGER_FILE = CONFIG_DIR / '.manual_trigger'
SETTINGS_FILE       = CONFIG_DIR / 'settings.json'
MOVER_SCRIPT        = Path(__file__).parent / 'mover.py'
MANUAL_SCRIPT       = Path(__file__).parent / 'manual_move.py'

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
    handlers=[logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger('scheduler')


def load_cron() -> str:
    try:
        with open(SETTINGS_FILE) as f:
            return json.load(f).get('cron_schedule', '0 3 * * *')
    except Exception:
        return '0 3 * * *'


def next_run_time(cron_expr: str) -> datetime:
    if HAS_CRONITER:
        try:
            return croniter(cron_expr, datetime.now()).get_next(datetime)
        except Exception as exc:
            log.warning(f"Invalid cron expression '{cron_expr}': {exc} — defaulting to 03:00 daily")

    # Fallback: next 03:00
    from datetime import timedelta
    now    = datetime.now()
    target = now.replace(hour=3, minute=0, second=0, microsecond=0)
    if target <= now:
        target += timedelta(days=1)
    return target


def run_mover() -> None:
    log.info("Starting mover.py ...")
    result = subprocess.run([sys.executable, str(MOVER_SCRIPT)])
    if result.returncode != 0:
        log.error(f"mover.py exited with code {result.returncode}")
    else:
        log.info("mover.py finished successfully")


def run_manual_mover() -> None:
    log.info("Starting manual_move.py ...")
    result = subprocess.run([sys.executable, str(MANUAL_SCRIPT)])
    if result.returncode != 0:
        log.error(f"manual_move.py exited with code {result.returncode}")
    else:
        log.info("manual_move.py finished successfully")


def check_config_writable() -> None:
    import stat
    test_file = CONFIG_DIR / '.write_test'
    try:
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        CONFIG_DIR.chmod(stat.S_IRWXU | stat.S_IRGRP | stat.S_IXGRP | stat.S_IROTH | stat.S_IXOTH)
        test_file.write_text('ok')
        test_file.unlink()
        log.info(f"Config volume is writable: {CONFIG_DIR}")
    except OSError as e:
        log.critical(f"Config volume is not writable ({CONFIG_DIR}): {e}")
        sys.exit(1)


def main() -> None:
    log.info("Scheduler started")
    check_config_writable()
    if not HAS_CRONITER:
        log.warning("croniter not installed — falling back to daily 03:00 schedule")

    cron_expr = load_cron()
    next_run  = next_run_time(cron_expr)
    log.info(f"Next scheduled run: {next_run:%Y-%m-%d %H:%M:%S}  (schedule: {cron_expr})")

    while True:
        now          = datetime.now()
        # Reload schedule each loop so UI changes take effect immediately
        new_cron     = load_cron()
        if new_cron != cron_expr:
            cron_expr = new_cron
            next_run  = next_run_time(cron_expr)
            log.info(f"Schedule changed → {cron_expr}  next run: {next_run:%Y-%m-%d %H:%M:%S}")

        if MANUAL_TRIGGER_FILE.exists():
            MANUAL_TRIGGER_FILE.unlink(missing_ok=True)
            log.info("Manual move trigger detected — running manual_move.py only")
            run_manual_mover()

        elif TRIGGER_FILE.exists():
            TRIGGER_FILE.unlink(missing_ok=True)
            log.info("Full run trigger detected")
            run_mover()
            next_run = next_run_time(cron_expr)
            log.info(f"Next scheduled run: {next_run:%Y-%m-%d %H:%M:%S}")

        elif now >= next_run:
            log.info(f"Scheduled run at {now:%Y-%m-%d %H:%M:%S}")
            run_mover()
            next_run = next_run_time(cron_expr)
            log.info(f"Next scheduled run: {next_run:%Y-%m-%d %H:%M:%S}")

        time.sleep(30)


if __name__ == '__main__':
    main()
