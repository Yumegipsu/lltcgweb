#!/usr/bin/env python3
"""Apply line breaks to cards.json text / text_pt (reference text_es when bracket counts match)."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from card_text_line_breaks import format_card_rules_text  # noqa: E402

CARDS_PATH = ROOT / "cards.json"
FIELDS = ("text", "text_pt", "text_fr")


def main() -> int:
    data = json.loads(CARDS_PATH.read_text(encoding="utf-8"))
    updated = {f: 0 for f in FIELDS}
    ref_hits = {f: 0 for f in FIELDS}

    for card in data.get("cards") or []:
        ref = (card.get("text_es") or "").strip()
        ref_use = ref if "\n" in ref else None
        for field in FIELDS:
            raw = (card.get(field) or "").strip()
            if not raw:
                continue
            new = format_card_rules_text(raw, ref_use)
            if new != raw:
                card[field] = new
                updated[field] += 1
                if ref_use and transfer_would_work(ref, raw):
                    ref_hits[field] += 1

    CARDS_PATH.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Updated text: {updated['text']} (ref-aligned ~{ref_hits['text']})")
    print(f"Updated text_pt: {updated['text_pt']} (ref-aligned ~{ref_hits['text_pt']})")
    return 0


def transfer_would_work(ref: str, tgt: str) -> bool:
    import re

    rb = len(re.findall(r"\[[^\]]+\]", ref))
    tb = len(re.findall(r"\[[^\]]+\]", tgt))
    return rb == tb and rb > 0


if __name__ == "__main__":
    raise SystemExit(main())
