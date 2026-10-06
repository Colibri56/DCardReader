#!/usr/bin/env python3
"""
scan_repeats.py - the "supply, not demand" engine.

The whole point of this skill is to stop waiting for the owner to *remember* to
write a procedure. This script reads a pile of past communications (a folder of
exported threads / transcripts, or one combined file), groups the ones that look
like the SAME kind of work repeated, and surfaces any pattern that recurred enough
times to be worth turning into an SOP.

It is deliberately model-free and dependency-free: it uses simple keyword-overlap
clustering, which is robust, explainable, and runs the same on Mac and Windows.
The owner's Claude session does the real inference later, only on the patterns
this scanner flags - so the expensive thinking happens once, on demand, on a
genuine repeat.

Input: a folder of .txt/.md/.eml/.json files (each = one thread/transcript), OR a
single file with threads separated by a line of '---'. With no input given, it
looks at config["scan"]["source_dir"], then falls back to the workspace
'communications' / 'inbox' folder if present.

Output: JSON of candidate recurring processes, each with a suggested title, the
member threads, and how many times it recurred. The caller turns the top ones into
a Telegram approval prompt ("process X recurred 3 times - document it?").

Run:
    python scan_repeats.py
    python scan_repeats.py --source ./exports --min-count 3
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
import re
import sys
from collections import Counter
from pathlib import Path

from common import find_workspace_root, load_config

# Hebrew + English stopwords kept short on purpose - we only need enough to stop
# clustering on filler. The signal is the rarer, work-specific words.
STOPWORDS = {
    # English
    "the", "a", "an", "and", "or", "to", "of", "in", "on", "for", "is", "are",
    "this", "that", "with", "as", "at", "by", "be", "it", "we", "you", "i",
    "from", "hi", "hello", "thanks", "thank", "regards", "best", "re", "fwd",
    # Hebrew common
    "של", "את", "על", "אני", "אתה", "היא", "הוא", "אנחנו", "זה", "זו", "יש",
    "לא", "כן", "גם", "אבל", "כי", "אם", "או", "עם", "אל", "כל", "מה", "כך",
    "שלום", "תודה", "היי", "בוקר", "ערב", "טוב",
}

WORD_RE = re.compile(r"[A-Za-z֐-׿]{2,}")
TEXT_EXT = {".txt", ".md", ".eml", ".json", ".text", ".log"}

# Hebrew attaches a lot of meaning as single-letter prefixes (ו ה ב ל מ ש כ),
# so 'בליד' / 'ללקוח' / 'ושיחה' should be ABLE to match 'ליד' / 'לקוח' / 'שיחה'
# when clustering, otherwise the same recurring process never clusters.
#
# But a blind single-letter strip is dangerous: it also eats real ROOT letters of
# common work-words (מחיר->חיר, כרטיס->רטיס, הצעת->צעת, שיחה->יחה, שלום->לום,
# לקוח->קוח). So we DO NOT replace the token with its stripped form. Instead the
# signature carries BOTH the original surface word AND the prefix-stripped form,
# so 'לקוח' and 'ללקוח' always share at least the stripped root ('קוח') and
# cluster together, while the human-readable surface word ('לקוח') is never lost.
# We only generate a stripped variant when the remainder stays >=3 chars.
HEB_PREFIX_RE = re.compile(r"^[והבלמשכ](?=.{3,})")


def _normalize(tok: str) -> str:
    """Lowercase only. Used for matching the raw surface word against stopwords."""
    return tok.lower()


def _stripped(tok: str) -> str | None:
    """Lowercase and strip a single leading Hebrew one-letter prefix, IF doing so
    leaves >=3 chars. Returns None when there is no prefix to strip (so the caller
    knows the surface word stands on its own). This is the cluster-bridging form,
    never shown to the owner."""
    low = tok.lower()
    stripped = HEB_PREFIX_RE.sub("", low)
    return stripped if stripped != low else None


# Stopwords also in their prefix-stripped form, so a mangled stem of a filler word
# (e.g. שלום->לום, שיחה->יחה) is filtered out instead of slipping into the title.
NORMALIZED_STOPWORDS = {
    s for w in STOPWORDS for s in ((_stripped(w),)) if s
}


def _resolve_source(arg: str | None, config: dict) -> Path | None:
    if arg:
        return Path(arg).expanduser()
    scan_cfg = (config.get("scan") or {})
    if scan_cfg.get("source_dir"):
        return Path(scan_cfg["source_dir"]).expanduser()
    root = find_workspace_root()
    for name in ("communications", "inbox", "threads", "exports"):
        cand = root / name
        if cand.exists():
            return cand
    return None


def _load_threads(source: Path) -> list[tuple[str, str]]:
    """Return a list of (label, text) threads. A folder = one thread per file; a
    single file = split on lines that are only dashes."""
    threads: list[tuple[str, str]] = []
    if source.is_dir():
        for f in sorted(source.rglob("*")):
            if f.is_file() and f.suffix.lower() in TEXT_EXT:
                try:
                    threads.append((f.name, f.read_text(encoding="utf-8", errors="ignore")))
                except OSError:
                    continue
    elif source.is_file():
        raw = source.read_text(encoding="utf-8", errors="ignore")
        chunks = re.split(r"(?m)^\s*-{3,}\s*$", raw)
        for i, chunk in enumerate(chunks, start=1):
            if chunk.strip():
                threads.append((f"{source.name}#{i}", chunk))
    return threads


def _raw_counts(text: str) -> Counter:
    """Count the real surface words in a thread, after stopword filtering on BOTH
    the raw word and its prefix-stripped stem (so 'לום'/'יחה' style stems of filler
    words never survive). These are the human-readable words used to title an SOP."""
    counts: Counter = Counter()
    for w in WORD_RE.findall(text):
        low = _normalize(w)
        if not low or low in STOPWORDS:
            continue
        stem = _stripped(low)
        if stem is not None and (stem in STOPWORDS or stem in NORMALIZED_STOPWORDS):
            continue
        if low in NORMALIZED_STOPWORDS:
            continue
        counts[low] += 1
    return counts


def _signature(text: str, top_n: int = 12) -> frozenset[str]:
    """A thread's signature = its top distinctive words, each contributing BOTH its
    surface form AND (when it has a Hebrew one-letter prefix) its stripped root.
    Two threads about the same recurring work then share these even across prefix
    variants - 'ללקוח' and 'לקוח' both carry the root 'קוח', so they cluster - while
    no root letter is ever destroyed (we keep 'לקוח'/'מחיר' intact, not 'קוח'/'חיר').

    top_n is a touch generous (12): because the signature now keeps readable surface
    words instead of aggressively-collapsed stems, a thread needs a few more of its
    top words to expose the full shared work-vocabulary to the overlap test.
    """
    common = [w for w, _ in _raw_counts(text).most_common(top_n)]
    sig: set[str] = set()
    for w in common:
        sig.add(w)
        stem = _stripped(w)
        if stem is not None:
            sig.add(stem)
    return frozenset(sig)


def _similar(a: frozenset[str], b: frozenset[str], threshold: float = 0.2) -> bool:
    """Overlap coefficient (intersection over the SMALLER set), not Jaccard. A
    short thread can then still match a longer one about the same process - Jaccard
    unfairly punished length differences and missed real repeats."""
    if not a or not b:
        return False
    inter = len(a & b)
    smaller = min(len(a), len(b))
    return smaller > 0 and (inter / smaller) >= threshold


def find_recurring(threads: list[tuple[str, str]], min_count: int) -> list[dict]:
    """Greedy single-pass clustering: assign each thread to the first existing
    cluster it is similar to, else start a new cluster. Good enough to catch
    'the same kind of thread happened 3 times this month', which is all we need."""
    clusters: list[dict] = []
    for label, text in threads:
        sig = _signature(text)
        raw = _raw_counts(text)
        placed = False
        for cl in clusters:
            if _similar(sig, cl["signature"]):
                cl["members"].append(label)
                cl["signature"] = cl["signature"] | sig  # widen the cluster vocab
                cl["raw_counts"].update(raw)  # accumulate readable surface words
                placed = True
                break
        if not placed:
            clusters.append({
                "signature": sig,
                "members": [label],
                "raw_counts": Counter(raw),
            })

    candidates = []
    for cl in clusters:
        if len(cl["members"]) >= min_count:
            # Title + keywords come from the most-common RAW surface words, so the
            # owner-facing Telegram prompt reads as real Hebrew ('ליד / לקוח / מחיר')
            # and never as stripped stems ('חיר / קוח').
            ranked = [w for w, _ in cl["raw_counts"].most_common()]
            kws = ranked[:5]
            title = " / ".join(kws) if kws else "תהליך חוזר"
            candidates.append({
                "suggested_title": title,
                "count": len(cl["members"]),
                "members": cl["members"],
                "keywords": ranked[:10],
            })
    candidates.sort(key=lambda c: c["count"], reverse=True)
    return candidates


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Find recurring processes worth documenting.")
    ap.add_argument("--source", default=None,
                    help="Folder of threads/transcripts, or a single combined file.")
    ap.add_argument("--min-count", type=int, default=3,
                    help="How many recurrences before it is worth an SOP (default 3).")
    args = ap.parse_args(argv)

    config = load_config()
    source = _resolve_source(args.source, config)

    if source is None or not source.exists():
        print(json.dumps({
            "ok": True,
            "candidates": [],
            "note": (
                "לא נמצא מקור תקשורת מקומי לסריקה. אפשר להצביע על תיקיית-תהליכים עם "
                "--source, או לחבר Gmail/WhatsApp ולתת ל-Claude למשוך שרשורים לתוכה. "
                "בלי מקור, הסקיל עדיין עובד לפי דרישה - פשוט מצביעים על תהליך ובונים נוהל."
            ),
        }, ensure_ascii=False, indent=2))
        return 0

    threads = _load_threads(source)
    candidates = find_recurring(threads, args.min_count)

    print(json.dumps({
        "ok": True,
        "source": str(source),
        "threads_scanned": len(threads),
        "min_count": args.min_count,
        "candidates": candidates,
        "next_step": (
            "לכל מועמד: שלח/י בטלגרם 'זוהה תהליך חוזר - לתעד לנוהל?' עם [כן]/[לא]. "
            "באישור, Claude קורא את ה-members ומסיק נוהל עם build_sop.py."
        ),
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
