#!/usr/bin/env python3
"""Import PBLL02 scrape JSON directly into cards.json (no Chiichan SQLCipher DB).

Usage:
  python tools/import_pbll02_from_scrape.py
  python tools/import_pbll02_from_scrape.py --scrape ../Chiichan/all_cards_pbll02_*.json
"""
from __future__ import annotations

import argparse
import glob
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from import_from_db import (  # noqa: E402
    CARDS_JSON,
    merge_cards,
    repair_proteinbar_promo_images,
    row_to_card,
)

CHIICHAN = ROOT.parent / "Chiichan"
PACK_JP = "プレミアムブースター ラブライブ！DUO"


def newest_scrape() -> Path:
    files = sorted(CHIICHAN.glob("all_cards_pbll02_*.json"))
    if not files:
        raise SystemExit("No all_cards_pbll02_*.json in Chiichan")
    return files[-1]


def scrape_row_to_db_row(card: dict) -> dict:
    """Map scrape JSON row → shape expected by row_to_card()."""
    return {
        "card_number": card.get("card_number") or "",
        "card_name": card.get("card_name") or "",
        "card_type": card.get("card_type") or "",
        "rarity": (card.get("rarity") or "").replace("＋", "+"),
        "booster_pack": PACK_JP,
        "series_name": card.get("series_name") or "",
        "participating_units": card.get("participating_units") or "",
        "cost": card.get("cost") or 0,
        "score": card.get("score") or 0,
        "blade_value": card.get("blade_value"),
        "special_info": card.get("special_info") or "",
        "special_heart": card.get("special_heart") or "",
        "hearts": card.get("hearts") or json.dumps({"hearts": {}, "blade_hearts": []}),
        "card_image": card.get("card_image") or card.get("image_url") or "",
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--scrape", default="", help="Path or glob to scrape JSON")
    args = ap.parse_args()
    if args.scrape:
        matches = [Path(p) for p in sorted(glob.glob(args.scrape))]
        if not matches and Path(args.scrape).is_file():
            matches = [Path(args.scrape)]
        if not matches:
            raise SystemExit(f"No scrape file: {args.scrape}")
        path = matches[-1]
    else:
        path = newest_scrape()

    payload = json.loads(path.read_text(encoding="utf-8"))
    scrape_cards = payload.get("cards") or []
    print(f"Loading {len(scrape_cards)} cards from {path.name}")

    rows = [scrape_row_to_db_row(c) for c in scrape_cards]
    new_cards = [row_to_card(r) for r in rows if r["card_number"]]

    data = json.loads(CARDS_JSON.read_text(encoding="utf-8"))
    before = {c.get("card_no") for c in data.get("cards", [])}
    data["cards"] = merge_cards(data.get("cards", []), new_cards)
    nfix = repair_proteinbar_promo_images(data["cards"])
    CARDS_JSON.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    added = [c for c in new_cards if c["card_no"] not in before]
    updated = [c for c in new_cards if c["card_no"] in before]
    print(f"Wrote cards.json: added={len(added)} updated/overwrote={len(updated)} repaired_images={nfix}")
    with_ab = sum(1 for c in new_cards if c.get("abilities"))
    print(f"With abilities: {with_ab}/{len(new_cards)}")
    for c in new_cards:
        ab = len(c.get("abilities") or [])
        print(f"  {c['card_no']:28} {c.get('name_en','')[:40]:40} ab={ab}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
