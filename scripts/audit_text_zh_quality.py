#!/usr/bin/env python3
"""Audit text_zh for English skill brackets and residual English prose."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CARDS = ROOT / "cards.json"

EN_BRACKET = re.compile(
    r"\[(?:On Enter|On Leave|Live Start|Live Success|Activated|Always|Continuous|"
    r"Once per [Tt]urn|Twice per [Tt]urn|Automatic|Auto|Center|Yell|On Play|Left Side|Right Side)\]"
)
KEEP = re.compile(
    r"\b(?:Blade|Yell|Wait|Live|μ's|Aqours|Liella!?|Nijigasaki|Hasunosora|QU4RTZ|R3BIRTH|"
    r"DOLLCHESTRA|CatChu!?|Saint Snow|START:DASH!!|WE WILL!!|CPU)\b",
    re.I,
)
RESIDUAL = re.compile(r"[A-Za-z]{4,}")


def main() -> int:
    cards = json.loads(CARDS.read_text(encoding="utf-8"))["cards"]
    bracket_leaks = []
    residual = []
    for c in cards:
        zh = (c.get("text_zh") or "").strip()
        if not zh:
            continue
        if EN_BRACKET.search(zh):
            bracket_leaks.append(c.get("card_no"))
            continue
        scrub = re.sub(r'"[^"]*"', "", zh)
        scrub = re.sub(r"\[[^\]]+\]", "", scrub)
        scrub = KEEP.sub("", scrub)
        # Ignore leftover short Latin noise: only flag clear English connectors
        if re.search(r"\b(?:the|and|from|into|your|this|that|with|for|Member|Energy|Waiting)\b", scrub, re.I):
            residual.append(c.get("card_no"))
    print(f"text_zh audit: bracket_leaks={len(bracket_leaks)} residual_en={len(residual)}")
    if bracket_leaks[:10]:
        print(" bracket sample:", ", ".join(bracket_leaks[:10]))
    if residual[:10]:
        print(" residual sample:", ", ".join(residual[:10]))
    return 1 if bracket_leaks or residual else 0


if __name__ == "__main__":
    raise SystemExit(main())
