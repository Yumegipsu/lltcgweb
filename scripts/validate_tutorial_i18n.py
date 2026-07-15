#!/usr/bin/env python3
"""
Validate tutorial locale JSON: coverage + no English game-term leaks (es).

Usage:
  python scripts/validate_tutorial_i18n.py
  python scripts/validate_tutorial_i18n.py --locale es
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts"))

from tutorial_i18n_glossary import has_english_tutorial_leak, has_mixed_english  # noqa: E402

LOCALE_FILES = {
    "es": ROOT / "tutorial_es.json",
    "ja": ROOT / "tutorial_ja.json",
    "ko": ROOT / "tutorial_ko.json",
    "zh": ROOT / "tutorial_zh.json",
}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--locale", default="es", choices=sorted(LOCALE_FILES.keys()))
    args = ap.parse_args()

    tutorial = json.loads((ROOT / "tutorial.json").read_text(encoding="utf-8"))
    step_ids = [s["id"] for s in tutorial.get("steps", []) if s.get("id")]
    loc_path = LOCALE_FILES[args.locale]
    if not loc_path.is_file():
        print(f"Missing {loc_path}", file=sys.stderr)
        return 1

    loc = json.loads(loc_path.read_text(encoding="utf-8"))
    missing = [i for i in step_ids if i not in loc or not str(loc.get(i, "")).strip()]
    leaks = [
        i
        for i in step_ids
        if has_english_tutorial_leak(str(loc.get(i, ""))) or has_mixed_english(str(loc.get(i, "")))
    ]

    ok = True
    if missing:
        ok = False
        print(f"{args.locale}: missing {len(missing)} step(s):", ", ".join(missing[:20]), file=sys.stderr)
    if leaks and args.locale in ("es", "ko", "zh"):
        ok = False
        print(f"{args.locale}: {len(leaks)} step(s) with English game terms:", ", ".join(leaks[:20]), file=sys.stderr)
        for i in leaks[:3]:
            print(f"  {i}: {loc[i][:120]}", file=sys.stderr)

    if ok:
        print(f"tutorial i18n OK: {len(step_ids)} steps, locale={args.locale}")
        return 0
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
