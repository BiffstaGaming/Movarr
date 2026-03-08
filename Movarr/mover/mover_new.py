#\!/usr/bin/env python3
"""
Tiered TV Show / Movie Storage Manager
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

CONFIG_DIR    = Path(os.getenv("CONFIG_PATH", "/config"))
SETTINGS_FILE = CONFIG_DIR / "settings.json"
LOG_FILE      = CONFIG_DIR / "mover.log"
QUEUE_FILE    = CONFIG_DIR / "queue.json"


def setup_logger():
    logger = logging.getLogger("mover")
    logger.setLevel(logging.DEBUG)
    fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
    fh = logging.FileHandler(LOG_FILE)
    fh.setFormatter(fmt)
    logger.addHandler(fh)
    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)
    logger.addHandler(sh)
    return logger


class QueueWriter:
    def __init__(self, mode):
        self.data = {
            "run_id":    datetime.now().strftime("%Y%m%d_%H%M%S"),
            "started":   datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "completed": None,
            "mode":      mode,
            "items":     [],
        }
        self._write()

    def add_item(self, item_id, name, service, mapping, direction, src, dst):
        idx = len(self.data["items"])
        self.data["items"].append({
            "id": item_id, "name": name, "service": service,
            "mapping": mapping, "direction": direction,
            "src": src, "dst": dst,
            "status": "pending", "progress": "",
            "started_at": None, "done_at": None,
        })
        self._write()
        return idx

    def start(self, idx):
        self.data["items"][idx]["status"] = "moving"
        self.data["items"][idx]["started_at"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        self._write()

    def done(self, idx, progress=""):
        self.data["items"][idx].update(status="done", progress=progress,
            done_at=datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
        self._write()

    def error(self, idx, progress=""):
        self.data["items"][idx].update(status="error", progress=progress,
            done_at=datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
        self._write()

    def skip(self, idx, reason=""):
        self.data["items"][idx].update(status="skipped", progress=reason,
            done_at=datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
        self._write()

    def finish(self):
        self.data["completed"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        self._write()

    def _write(self):
        try:
            QUEUE_FILE.write_text(json.dumps(self.data, indent=2))
        except Exception:
            pass


def load_settings():
    with open(SETTINGS_FILE) as f:
        return json.load(f)


def normalize(title):
    t = title.strip().lower()
    t = re.sub(r"\s*\(\d{4}\)\s*$", "", t)
    t = re.sub(r"\s+", " ", t)
    return t.strip()


def get_id_from_plex(rating_key, tautulli, scheme, log):
    try:
        resp = requests.get(
            f"{tautulli["url"].rstrip("/")}/api/v2",
            params={"apikey": tautulli["api_key"], "cmd": "get_metadata", "rating_key": rating_key},
            timeout=15)
        metadata = resp.json()["response"]["data"]
        for guid in metadata.get("guids", []):
            if isinstance(guid, str) and guid.startswith(f"{scheme}://"):
                return int(guid.split("://", 1)[1])
            if isinstance(guid, dict):
                gid = guid.get("id", "")
                if gid.startswith(f"{scheme}://"):
                    return int(gid.split("://", 1)[1])
    except Exception as exc:
        log.debug(f"get_metadata ({scheme}) failed for rating_key {rating_key}: {exc}")
    return None
