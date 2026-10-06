#!/usr/bin/env python3
"""
detect_connectors.py - see which OPTIONAL connectors the owner already has.

Why: the onboarding flow should pre-fill the connector menu instead of asking the
owner to recite their tool stack. This script does a *light, read-only* sniff -
it checks for environment variables, common CLI tools, and connection files - and
reports what is plausibly available. It NEVER authenticates, NEVER sends data, and
NEVER fails the skill: if it finds nothing, the universal quartet (local file ->
Google Sheets -> Gmail -> Telegram) still covers everything.

Output: JSON to stdout describing each connector as available / maybe / absent,
plus the always-on quartet so the caller can present a complete menu.

Run:
    python detect_connectors.py
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
import shutil
import sys

from common import find_workspace_root, load_config


def _env_any(*names: str) -> bool:
    return any(os.environ.get(n) for n in names)


def detect() -> dict:
    """Return a structured view of connector availability. 'available' means we
    found a strong signal (env var / connection file); 'maybe' means a CLI is on
    PATH but we cannot confirm auth; 'absent' means no signal at all."""
    root = find_workspace_root()
    config = load_config()
    env_file = root / ".env"
    env_text = ""
    if env_file.exists():
        try:
            env_text = env_file.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            env_text = ""

    def in_env_file(*keys: str) -> bool:
        return any(k in env_text for k in keys)

    connectors: dict[str, dict] = {}

    # --- Source connectors (read a process that already ran) ---
    connectors["gmail"] = _status(
        strong=_env_any("GMAIL_TOKEN", "GOOGLE_OAUTH_TOKEN") or in_env_file("GMAIL", "GOOGLE_OAUTH"),
        maybe=bool(shutil.which("composio")) or _env_any("GOOGLE_APPLICATION_CREDENTIALS"),
        role="source", label="Gmail",
        note="קריאת שרשור-מייל שכבר טופל כדי להסיק שלבים",
    )
    connectors["whatsapp"] = _status(
        strong=_env_any("GREEN_API_TOKEN", "GREENAPI_TOKEN", "WHATSAPP_TOKEN") or in_env_file("GREEN_API", "GREENAPI"),
        maybe=False,
        role="source", label="WhatsApp (Green API)",
        note="קריאת צ'אט-וואטסאפ שבו התהליך כבר רץ",
    )
    connectors["google_drive"] = _status(
        strong=in_env_file("DRIVE", "GOOGLE_OAUTH"),
        maybe=bool(shutil.which("composio")),
        role="source+store", label="Google Drive",
        note="מקור לתמלולים/מסמכים וגם מאגר-נהלים",
    )

    # --- Store connectors (where the finished SOP lives) ---
    connectors["notion"] = _status(
        strong=_env_any("NOTION_TOKEN", "NOTION_API_KEY") or in_env_file("NOTION"),
        maybe=False,
        role="store", label="Notion",
        note="מאגר-נהלים מסודר עם תוכן-עניינים",
    )
    connectors["google_docs"] = _status(
        strong=in_env_file("GOOGLE_OAUTH", "DOCS"),
        maybe=bool(shutil.which("composio")),
        role="store", label="Google Docs",
        note="נוהל כמסמך נקי לשיתוף",
    )
    connectors["google_sheets"] = _status(
        strong=in_env_file("GOOGLE_OAUTH", "SHEETS"),
        maybe=bool(shutil.which("composio")),
        role="store+tasks", label="Google Sheets",
        note="חלק מהרביעייה האוניברסלית - תמיד אפשרי כיעד",
    )

    # --- Task-board connectors (checklist -> onboarding tasks) ---
    connectors["monday"] = _status(
        strong=_env_any("MONDAY_TOKEN", "MONDAY_API_KEY") or in_env_file("MONDAY"),
        maybe=False, role="tasks", label="Monday",
        note="הפיכת הצ'קליסט למשימות-onboarding",
    )
    connectors["trello"] = _status(
        strong=_env_any("TRELLO_TOKEN", "TRELLO_API_KEY") or in_env_file("TRELLO"),
        maybe=False, role="tasks", label="Trello",
        note="הפיכת הצ'קליסט למשימות-onboarding",
    )
    connectors["asana"] = _status(
        strong=_env_any("ASANA_TOKEN", "ASANA_ACCESS_TOKEN") or in_env_file("ASANA"),
        maybe=False, role="tasks", label="Asana",
        note="הפיכת הצ'קליסט למשימות-onboarding",
    )
    connectors["clickup"] = _status(
        strong=_env_any("CLICKUP_TOKEN", "CLICKUP_API_KEY") or in_env_file("CLICKUP"),
        maybe=False, role="tasks", label="ClickUp",
        note="הפיכת הצ'קליסט למשימות-onboarding",
    )

    # --- Notify connector ---
    connectors["telegram"] = _status(
        strong=_env_any("TELEGRAM_BOT_TOKEN", "TELEGRAM_TOKEN") or in_env_file("TELEGRAM"),
        maybe=False, role="notify", label="Telegram",
        note="חלק מהרביעייה - שער-אישור והצעות-תיעוד יזומות",
    )

    quartet = {
        "local_file": "תמיד זמין - נוהל כקובץ Markdown בתיקיית 'נהלים'",
        "google_sheets": connectors["google_sheets"]["status"],
        "gmail": connectors["gmail"]["status"],
        "telegram": connectors["telegram"]["status"],
    }

    return {
        "workspace_root": str(root),
        "configured_primary": (config.get("output") or {}).get("primary", "local_file"),
        "universal_quartet": quartet,
        "connectors": connectors,
        "fallback_doctrine": (
            "אף מחבר אינו חובה. בלי שום מחבר, הרביעייה "
            "(קובץ מקומי -> Google Sheets -> Gmail -> Telegram) מספיקה לתיעוד "
            "מלא, לשיתוף ולזיהוי תהליכים-חוזרים."
        ),
    }


def _status(strong: bool, maybe: bool, role: str, label: str, note: str) -> dict:
    if strong:
        status = "available"
    elif maybe:
        status = "maybe"
    else:
        status = "absent"
    return {"label": label, "role": role, "status": status, "note": note}


def main() -> int:
    print(json.dumps(detect(), ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
