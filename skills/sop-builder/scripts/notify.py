#!/usr/bin/env python3
"""
notify.py - the approval-gate / notify leg of the universal quartet.

Human-in-the-loop is non-negotiable for this skill: nothing gets saved as an
official procedure, and no proactive "I found a repeat" suggestion acts on its
own, without the owner saying yes. This helper sends that yes/no prompt.

Preferred channel: Telegram with inline [yes]/[no] buttons (when the owner has a
bot token). If Telegram is not configured, it degrades gracefully - it prints the
exact message to stdout so the owner's Claude session can show it in chat and ask
for approval there. Either way the gate holds; only the medium changes.

Uses only urllib from the standard library - no 'requests', no extra install, so
it runs anywhere Python 3 runs.

Run:
    python notify.py --text "זוהה תהליך חוזר: טיפול בליד. לתעד לנוהל?" --approve
    python notify.py --text "הנוהל מוכן ונשמר. לשלוח לצוות?"
"""

from __future__ import annotations

# Windows: emit UTF-8 so Hebrew output never crashes under cp1252 (additive, safe)
import sys as _sys
try:
    _sys.stdout.reconfigure(encoding="utf-8")
    _sys.stderr.reconfigure(encoding="utf-8")
except Exception:
    pass

import argparse
import json
import os
import sys
import urllib.parse
import urllib.request

from common import find_workspace_root


def _token_and_chat() -> tuple[str | None, str | None]:
    token = os.environ.get("TELEGRAM_BOT_TOKEN") or os.environ.get("TELEGRAM_TOKEN")
    chat = os.environ.get("TELEGRAM_CHAT_ID")
    if token and chat:
        return token, chat
    # Fall back to a .env in the workspace if env vars are not exported.
    env = find_workspace_root() / ".env"
    if env.exists():
        try:
            for line in env.read_text(encoding="utf-8", errors="ignore").splitlines():
                line = line.strip()
                if line.startswith("TELEGRAM_BOT_TOKEN=") and not token:
                    token = line.split("=", 1)[1].strip().strip('"').strip("'")
                elif line.startswith("TELEGRAM_TOKEN=") and not token:
                    token = line.split("=", 1)[1].strip().strip('"').strip("'")
                elif line.startswith("TELEGRAM_CHAT_ID=") and not chat:
                    chat = line.split("=", 1)[1].strip().strip('"').strip("'")
        except OSError:
            pass
    return token, chat


def send_telegram(text: str, approve: bool) -> dict:
    token, chat = _token_and_chat()
    if not token or not chat:
        return {"sent": False, "reason": "no_telegram_config"}

    payload = {"chat_id": chat, "text": text}
    if approve:
        # Inline approval buttons. The callback_data is plain so the owner's bot
        # (or a future hook) can route it without parsing anything fancy.
        payload["reply_markup"] = json.dumps({
            "inline_keyboard": [[
                {"text": "✅ כן", "callback_data": "sop_yes"},
                {"text": "⏭️ לא", "callback_data": "sop_no"},
            ]]
        })

    url = f"https://api.telegram.org/bot{token}/sendMessage"
    data = urllib.parse.urlencode(payload).encode("utf-8")
    try:
        with urllib.request.urlopen(url, data=data, timeout=15) as resp:
            body = resp.read().decode("utf-8", errors="ignore")
        return {"sent": True, "response": json.loads(body) if body else {}}
    except Exception as exc:  # noqa: BLE001 - any network error degrades gracefully
        return {"sent": False, "reason": f"telegram_error: {exc}"}


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Send an approval/notification message.")
    ap.add_argument("--text", required=True, help="The message body.")
    ap.add_argument("--approve", action="store_true",
                    help="Attach yes/no approval buttons (Telegram only).")
    args = ap.parse_args(argv)

    result = send_telegram(args.text, args.approve)
    if not result.get("sent"):
        # Graceful fallback: print so the chat-based gate can take over.
        print(args.text)
        print(json.dumps({"telegram": result, "fallback": "printed_to_stdout"},
                         ensure_ascii=False), file=sys.stderr)
        return 0

    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
