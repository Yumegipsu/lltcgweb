#!/usr/bin/env python3
"""Dump unique PBLL02 skill texts for ability authoring."""
from __future__ import annotations

import json
from collections import OrderedDict
from pathlib import Path

SCRAPE = Path(__file__).resolve().parents[1].parent / "Chiichan" / "all_cards_pbll02_20261002_005319.json"
# allow override via newest match
CHIICHAN = Path(__file__).resolve().parents[1].parent / "Chiichan"


def newest_scrape() -> Path:
    files = sorted(CHIICHAN.glob("all_cards_pbll02_*.json"))
    if not files:
        raise SystemExit("No PBLL02 scrape JSON found")
    return files[-1]


def base_no(card_no: str) -> str:
    parts = card_no.split("-")
    return "-".join(parts[:3])


def main() -> None:
    path = newest_scrape()
    data = json.loads(path.read_text(encoding="utf-8"))
    cards = data["cards"]
    out_lines = []
    out_lines.append(f"# Source: {path.name}")
    out_lines.append(f"# Total rows: {len(cards)}")
    out_lines.append("")

    pb2: OrderedDict[str, dict] = OrderedDict()
    reprints = []
    for c in cards:
        no = c["card_number"]
        if "-pb2-" in no:
            b = base_no(no)
            if b not in pb2:
                pb2[b] = c
        else:
            reprints.append(c)

    out_lines.append(f"## New DUO bases ({len(pb2)})")
    out_lines.append("")
    for base, c in pb2.items():
        skill = c.get("special_info") or "(none)"
        out_lines.append(f"### {base} — {c['card_name']} [{c['card_type']}]")
        out_lines.append(
            f"cost={c.get('cost')} blade={c.get('blade_value')} score={c.get('score')} "
            f"rarity_sample={c.get('rarity')} hearts={c.get('hearts')}"
        )
        out_lines.append("")
        out_lines.append(skill)
        out_lines.append("")
        out_lines.append("---")
        out_lines.append("")

    out_lines.append(f"## Reprints / SRL ({len(reprints)} rows)")
    for c in reprints:
        out_lines.append(f"- {c['card_number']} {c['card_name']}")

    out = Path(__file__).resolve().parents[1] / "docs" / "PBLL02_SKILLS_JP.md"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text("\n".join(out_lines), encoding="utf-8")
    print(f"Wrote {out} ({len(pb2)} bases, {len(reprints)} reprints)")


if __name__ == "__main__":
    main()
