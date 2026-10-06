#!/usr/bin/env python3
"""
common.py - shared helpers for the SOP Builder skill.

Why this exists: every other script needs the same three things - find the
owner's workspace root, read/write the skill config, and resolve where outputs
go - and all of it must work identically on macOS and Windows. Centralising it
here means the rest of the engine never hard-codes a path or a platform check.

No third-party dependencies. Standard library only, so it runs on any Python 3.8+.
"""

from __future__ import annotations

# Windows: emit UTF-8 so Hebrew output never crashes under cp1252 (additive, safe)
import sys as _sys
try:
    _sys.stdout.reconfigure(encoding="utf-8")
    _sys.stderr.reconfigure(encoding="utf-8")
except Exception:
    pass

import json
import os
import platform
from datetime import datetime, timezone, timedelta
from pathlib import Path

# ----------------------------------------------------------------------------
# Skill + workspace location (cross-platform, no hard-coded paths)
# ----------------------------------------------------------------------------

SKILL_DIR = Path(__file__).resolve().parent.parent
"""The skill's own folder (.../sop-builder/). Derived from this file's location
so it is correct on any machine, Mac or Windows, wherever the owner installed it."""

# Marker files that identify the top of an owner's workspace. We walk UP from the
# current directory looking for any of these, so the skill adapts to whatever the
# owner named their project folder - we never assume any absolute home path on Mac
# or Windows.
WORKSPACE_MARKERS = ("CLAUDE.md", ".git", "purpose.md", "context")


def find_workspace_root(start: Path | None = None) -> Path:
    """Walk upward from `start` (default: current working directory) until a
    workspace marker is found. Falls back to the current directory if nothing is
    found, so the skill still works in a bare folder with zero setup."""
    here = (start or Path.cwd()).resolve()
    for candidate in (here, *here.parents):
        for marker in WORKSPACE_MARKERS:
            if (candidate / marker).exists():
                return candidate
    return here


def is_windows() -> bool:
    return platform.system().lower().startswith("win")


# ----------------------------------------------------------------------------
# Config (written by onboarding, read by every later run)
# ----------------------------------------------------------------------------

def config_path(workspace: Path | None = None) -> Path:
    """Where the per-owner config lives. We keep it inside the skill folder so it
    travels with the skill and stays out of the owner's git history by default."""
    return SKILL_DIR / "config.json"


def load_config(workspace: Path | None = None) -> dict:
    """Read config.json if it exists, else return an empty dict. Empty config is a
    valid state - it just means onboarding has not run yet, and the engine should
    fall back to the universal quartet (local file)."""
    path = config_path(workspace)
    if path.exists():
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except (json.JSONDecodeError, OSError):
            return {}
    return {}


def save_config(config: dict, workspace: Path | None = None) -> Path:
    """Persist config.json with stable, human-readable formatting."""
    path = config_path(workspace)
    path.write_text(
        json.dumps(config, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    return path


# ----------------------------------------------------------------------------
# Output destination (the universal quartet: local file is always available)
# ----------------------------------------------------------------------------

def outputs_dir(config: dict, workspace: Path | None = None) -> Path:
    """Resolve the local SOP folder. Order of preference:
    1. An explicit absolute/relative path in config["output"]["local_dir"].
    2. A 'procedures' (or Hebrew 'נהלים') folder under the workspace.
    The folder is created if missing. This is the ALWAYS-WORKS destination - even
    with zero connectors, every SOP can land here as a clean Markdown file."""
    ws = workspace or find_workspace_root()
    local = (config.get("output") or {}).get("local_dir")
    if local:
        p = Path(local).expanduser()
        if not p.is_absolute():
            p = ws / p
    else:
        p = ws / "outputs" / "procedures"
    p.mkdir(parents=True, exist_ok=True)
    return p


# ----------------------------------------------------------------------------
# Time (always Israel-friendly local time, never a raw UTC Z string)
# ----------------------------------------------------------------------------

def now_local_iso(tz_offset_hours: int = 3) -> str:
    """A readable local timestamp. Defaults to UTC+3 (Israel summer time) so the
    owner never sees a confusing UTC 'Z'. Adjust tz_offset_hours in config if the
    owner is elsewhere."""
    tz = timezone(timedelta(hours=tz_offset_hours))
    return datetime.now(tz).strftime("%Y-%m-%d %H:%M")


def today_slug(tz_offset_hours: int = 3) -> str:
    """A filename-safe date stamp, e.g. 2026-06-21. Uses the same local timezone as
    now_local_iso (default UTC+3, Israel) so the filename date never disagrees with
    the in-document 'נוצר' timestamp - including on a UTC-clock server or CI box."""
    tz = timezone(timedelta(hours=tz_offset_hours))
    return datetime.now(tz).strftime("%Y-%m-%d")


def slugify(text: str, max_len: int = 60) -> str:
    """Turn an SOP title into a safe filename stem. Keeps Hebrew letters (the
    owner's SOPs are often Hebrew) but strips path-hostile characters."""
    bad = '<>:"/\\|?*\n\r\t'
    cleaned = "".join("-" if c in bad else c for c in text).strip()
    cleaned = "-".join(part for part in cleaned.split() if part)
    return cleaned[:max_len] or "sop"
