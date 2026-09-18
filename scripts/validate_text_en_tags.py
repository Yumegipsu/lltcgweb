#!/usr/bin/env python3
"""Validate icon tags in cards.json rules text (English text + text_pt)."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CARDS_PATH = ROOT / "cards.json"

ALLOWED_TAGS = {
    "energy",
    "blade",
    "pinkH",
    "redH",
    "yellowH",
    "greenH",
    "blueH",
    "purpleH",
    "anyH",
    "allH",
    "pinkBH",
    "redBH",
    "yellowBH",
    "greenBH",
    "blueBH",
    "purpleBH",
    "allBH",
    "score+1",
    "card+1",
}

TAG_RE = re.compile(r"<([^>/]+)>")
MIXED_BLADE_RE = re.compile(r"\+?\d+\s*Blade.*<blade>|<blade>.*\+?\d+\s*Blade", re.IGNORECASE)
MIXED_ENERGY_RE = re.compile(
    r"Pay\s+\d+\s+Energy.*<energy>|<energy>.*Pay\s+\d+\s+Energy", re.IGNORECASE
)
MIXED_BLADE_PT_RE = re.compile(r"\+?\d+\s*Blade.*<blade>|<blade>.*\+?\d+\s*Blade", re.IGNORECASE)
MIXED_ENERGY_PT_RE = re.compile(
    r"Pague\s+\d+\s+Energia.*<energy>|<energy>.*Pague\s+\d+\s+Energia", re.IGNORECASE
)

NOTE_CARDS = {
    "PL!-bp5-014-N",
    "PL!-bp6-005-P",
    "PL!-bp6-005-R",
    "PL!N-sd2-019-SD2",
    "PL!SP-pb2-002-PP",
    "PL!SP-pb2-002-R",
    "PL!SP-pb2-022-P＋",
    "PL!SP-pb2-023-N",
    "PL!SP-pb2-026-N",
    "PL!SP-pb2-027-N",
    "PL!SP-pb2-030-N",
    "PL!SP-pb2-032-N",
    "PL!S-pb1-003-P＋",
    "PL!S-pb1-003-R",
    "PL!S-sd1-007-SD",
}

TEXT_FIELDS = ("text", "text_pt", "text_fr")


def check_text(no: str, text: str, field: str, errors: list[str], warnings: list[str]) -> bool:
    if not text:
        return False
    has_tags = "<" in text
    for m in TAG_RE.finditer(text):
        tag = m.group(1)
        if tag not in ALLOWED_TAGS:
            errors.append(f"{no}: unknown tag <{tag}> in {field}")
    stripped = TAG_RE.sub("", text)
    if "<" in stripped or ">" in stripped:
        errors.append(f"{no}: malformed angle brackets in {field}")
    if field == "text":
        if MIXED_BLADE_RE.search(text):
            warnings.append(f"{no}: mixed prose Blade and <blade> tag in {field}")
        if MIXED_ENERGY_RE.search(text):
            warnings.append(f"{no}: mixed prose Energy and <energy> tag in {field}")
    elif field == "text_pt":
        if MIXED_BLADE_PT_RE.search(text):
            warnings.append(f"{no}: mixed prose Blade and <blade> tag in {field}")
        if MIXED_ENERGY_PT_RE.search(text):
            warnings.append(f"{no}: mixed prose Energia and <energy> tag in {field}")
    return has_tags


def main() -> int:
    data = json.loads(CARDS_PATH.read_text(encoding="utf-8"))
    errors: list[str] = []
    warnings: list[str] = []
    tagged_cards = 0

    for card in data.get("cards") or []:
        no = card.get("card_no") or "?"
        for field in TEXT_FIELDS:
            text = card.get(field) or ""
            if check_text(no, text, field, errors, warnings) and field == "text":
                tagged_cards += 1
            elif check_text(no, text, field, errors, warnings) and field == "text_pt":
                tagged_cards += 1

    for no in NOTE_CARDS:
        found = next((c for c in data["cards"] if c.get("card_no") == no), None)
        if not found:
            errors.append(f"{no}: note-card missing from catalog")
        elif not (found.get("text") or "").strip():
            errors.append(f"{no}: note-card has empty text")

    pt_count = sum(1 for c in data.get("cards") or [] if (c.get("text_pt") or "").strip())
    print(f"Checked {len(data.get('cards') or [])} cards; {tagged_cards} with inline tags; {pt_count} with text_pt")
    if warnings:
        print(f"WARN ({len(warnings)}):")
        for w in warnings[:20]:
            print(f"  {w}")
        if len(warnings) > 20:
            print(f"  ... and {len(warnings) - 20} more")
    if errors:
        print(f"ERROR ({len(errors)}):")
        for e in errors[:40]:
            print(f"  {e}")
        if len(errors) > 40:
            print(f"  ... and {len(errors) - 40} more")
        return 1
    print("OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
