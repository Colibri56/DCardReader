#!/usr/bin/env python3
"""
build_sop.py - render a structured SOP into clean, transferable documents.

THE DIVISION OF LABOUR (important): the *thinking* - reading a real email thread
or meeting transcript and inferring the actual steps - is done by the owner's own
Claude Code session. There is NO API key and NO model call in this script. The
session hands this script a finished SOP as JSON; this script turns that JSON into
a polished Markdown document (and an optional checklist), and writes it to the
chosen destination from the universal quartet.

This keeps the engine deterministic, free, and identical on Mac and Windows.

Usage:
    python build_sop.py --in sop.json
    python build_sop.py --in sop.json --print           # also echo Markdown to stdout
    cat sop.json | python build_sop.py --in -            # read JSON from stdin

Input JSON shape (all fields optional except title + steps):
{
  "title": "טיפול בליד נכנס",
  "purpose": "להבטיח שכל ליד מקבל מענה אישי תוך 5 דקות",
  "when_to_run": "בכל פעם שנכנס ליד חדש מטופס הנחיתה",
  "default_owner": "מנהל/ת המכירות",
  "standard_time": "10 דקות",
  "source": "הוסק משרשור מייל מ-12.6 (3 הודעות)",
  "steps": [
    {"text": "פותחים את הליד בכרטיס...", "owner": "נציג/ה", "time": "2 דק", "unknown": false},
    {"text": "מתקשרים תוך 5 דקות", "owner": "[?]", "time": "", "unknown": true}
  ],
  "pitfalls": ["לא לשלוח לפני שבדקתם שם נכון", "..."],
  "checklist": ["פתחתי את הכרטיס", "התקשרתי", "..."]
}

Any step or field marked unknown / containing "[?]" is preserved verbatim so the
owner can see exactly what still needs a human answer - we never silently invent.
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
import sys
from pathlib import Path

from common import (
    load_config,
    outputs_dir,
    now_local_iso,
    today_slug,
    slugify,
)

UNKNOWN_MARK = "[?]"


def _read_input(path_arg: str) -> dict:
    if path_arg == "-":
        raw = sys.stdin.read()
    else:
        raw = Path(path_arg).expanduser().read_text(encoding="utf-8")
    data = json.loads(raw)
    if not isinstance(data, dict):
        raise ValueError("SOP input must be a JSON object.")
    return data


def render_markdown(sop: dict, tz_offset_hours: int = 3) -> str:
    """Render the canonical SOP layout. The layout is fixed on purpose - a
    transferable procedure always reads the same way: purpose, trigger, numbered
    steps with an owner per step, standard time, pitfalls, and a final checklist."""
    title = (sop.get("title") or "נוהל").strip()
    lines: list[str] = []

    lines.append(f"# נוהל: {title}")
    lines.append("")

    meta_bits = []
    if sop.get("source"):
        meta_bits.append(f"מקור: {sop['source']}")
    meta_bits.append(f"נוצר: {now_local_iso(tz_offset_hours)}")
    if sop.get("default_owner"):
        meta_bits.append(f"אחראי/ת ברירת מחדל: {sop['default_owner']}")
    lines.append("> " + " | ".join(meta_bits))
    lines.append("")

    if sop.get("purpose"):
        lines.append("## מטרה")
        lines.append(sop["purpose"].strip())
        lines.append("")

    if sop.get("when_to_run"):
        lines.append("## מתי מפעילים")
        lines.append(sop["when_to_run"].strip())
        lines.append("")

    steps = sop.get("steps") or []
    if steps:
        lines.append("## שלבים")
        for i, step in enumerate(steps, start=1):
            text = (step.get("text") or "").strip() if isinstance(step, dict) else str(step).strip()
            owner = (step.get("owner") or "").strip() if isinstance(step, dict) else ""
            time = (step.get("time") or "").strip() if isinstance(step, dict) else ""
            tail = []
            if owner:
                tail.append(f"אחראי/ת: {owner}")
            if time:
                tail.append(f"זמן: {time}")
            suffix = f"  _( {' · '.join(tail)} )_" if tail else ""
            lines.append(f"{i}. {text}{suffix}")
        lines.append("")

    if sop.get("standard_time"):
        lines.append("## זמן-תקן לכל התהליך")
        lines.append(sop["standard_time"].strip())
        lines.append("")

    pitfalls = sop.get("pitfalls") or []
    if pitfalls:
        lines.append("## מלכודות נפוצות")
        for p in pitfalls:
            lines.append(f"- {str(p).strip()}")
        lines.append("")

    checklist = sop.get("checklist") or _derive_checklist(steps)
    if checklist:
        lines.append("## צ'קליסט סופי")
        for c in checklist:
            lines.append(f"- [ ] {str(c).strip()}")
        lines.append("")

    # Surface any still-open questions so the human sees them at a glance.
    open_items = _collect_unknowns(sop)
    if open_items:
        lines.append("## להשלמה (סומן אוטומטית)")
        lines.append("השדות הבאים סומנו כלא-ידועים מתוך החומר - כדאי לאשר או למלא:")
        for q in open_items:
            lines.append(f"- {q}")
        lines.append("")

    return "\n".join(lines).rstrip() + "\n"


def _derive_checklist(steps: list) -> list[str]:
    """If no explicit checklist was supplied, build one from the step texts so the
    SOP is always actionable - a procedure without a tick-list rarely gets used."""
    out = []
    for step in steps:
        text = (step.get("text") if isinstance(step, dict) else str(step)) or ""
        text = text.strip()
        if text:
            out.append(text)
    return out


def _collect_unknowns(sop: dict) -> list[str]:
    out = []
    for i, step in enumerate(sop.get("steps") or [], start=1):
        if not isinstance(step, dict):
            continue
        if step.get("unknown") or UNKNOWN_MARK in (step.get("owner") or "") or UNKNOWN_MARK in (step.get("text") or ""):
            out.append(f"שלב {i}: {(step.get('text') or '').strip()[:80]}")
    for key in ("purpose", "when_to_run", "default_owner", "standard_time"):
        if UNKNOWN_MARK in str(sop.get(key) or ""):
            out.append(f"שדה '{key}'")
    return out


def render_checklist_only(sop: dict) -> str:
    """A bare checklist - used when the owner wants onboarding tasks streamed to a
    file/sheet/board. One actionable line per row, no headers."""
    checklist = sop.get("checklist") or _derive_checklist(sop.get("steps") or [])
    return "\n".join(str(c).strip() for c in checklist if str(c).strip()) + "\n"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Render a structured SOP to documents.")
    ap.add_argument("--in", dest="infile", required=True,
                    help="Path to SOP JSON, or '-' for stdin.")
    ap.add_argument("--print", action="store_true",
                    help="Also echo the Markdown to stdout.")
    ap.add_argument("--checklist-out", default=None,
                    help="Optional path to also write a bare onboarding checklist.")
    args = ap.parse_args(argv)

    sop = _read_input(args.infile)
    config = load_config()
    tz_offset_hours = config.get("tz_offset_hours", 3)

    md = render_markdown(sop, tz_offset_hours)

    out_dir = outputs_dir(config)
    stem = f"{today_slug(tz_offset_hours)}-{slugify(sop.get('title') or 'sop')}"
    md_path = out_dir / f"{stem}.md"
    md_path.write_text(md, encoding="utf-8")

    written = [str(md_path)]

    if args.checklist_out:
        cl_path = Path(args.checklist_out).expanduser()
        if not cl_path.is_absolute():
            cl_path = out_dir / cl_path
        cl_path.write_text(render_checklist_only(sop), encoding="utf-8")
        written.append(str(cl_path))

    if args.print:
        print(md)

    result = {
        "ok": True,
        "files": written,
        "open_items": _collect_unknowns(sop),
        "destination_hint": (config.get("output") or {}).get("primary", "local_file"),
    }
    print(json.dumps(result, ensure_ascii=False, indent=2), file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
