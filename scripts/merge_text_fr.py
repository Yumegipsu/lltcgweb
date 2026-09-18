#!/usr/bin/env python3
"""Merge icon-tagged FR card rules from sheet CSV into cards.json text_fr field."""

from __future__ import annotations

import argparse
import csv
import json
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from card_text_line_breaks import format_card_rules_text  # noqa: E402

ROOT = Path(__file__).resolve().parents[1]
CARDS_PATH = ROOT / "cards.json"
DEFAULT_CSV = ROOT / "exports" / "loveca_sheet_latest.csv"


def load_sheet(path: Path) -> dict[str, str]:
    with path.open(encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        if not reader.fieldnames:
            raise SystemExit("Empty CSV")
        out: dict[str, str] = {}
        for row in reader:
            no = (row.get("card_no") or row.get("card_number") or "").strip()
            fr = (
                row.get("text_fr_edited")
                or row.get("Special Info [FR]")
                or row.get("text_fr")
                or ""
            ).strip()
            if no and fr:
                out[no] = fr
        return out


def main() -> int:
    parser = argparse.ArgumentParser(description="Merge FR sheet into cards.json text_fr")
    parser.add_argument("csv", nargs="?", type=Path, default=DEFAULT_CSV)
    parser.add_argument("--dry-run", action="store_true", help="Report diff only")
    parser.add_argument("--apply", action="store_true", help="Write cards.json")
    args = parser.parse_args()
    if not args.dry_run and not args.apply:
        args.dry_run = True

    if not args.csv.is_file():
        print(f"Missing CSV: {args.csv}", file=sys.stderr)
        print("Run: python scripts/fetch_loveca_sheet.py", file=sys.stderr)
        return 1
    if not CARDS_PATH.is_file():
        print("Missing cards.json", file=sys.stderr)
        return 1

    sheet = load_sheet(args.csv)
    data = json.loads(CARDS_PATH.read_text(encoding="utf-8"))
    cards = data.get("cards") or []
    by_no = {c.get("card_no"): c for c in cards if c.get("card_no")}

    updated = unchanged = skipped = unknown = 0
    samples: list[tuple[str, str, str]] = []

    for no, new_text in sheet.items():
        card = by_no.get(no)
        if not card:
            unknown += 1
            continue
        old = (card.get("text_fr") or "").strip()
        if old == new_text.strip():
            unchanged += 1
            continue
        updated += 1
        if len(samples) < 8:
            samples.append((no, old[:80], new_text[:80]))
        if args.apply:
            ref = (card.get("text_es") or card.get("text") or "").strip()
            ref_use = ref if "\n" in ref else None
            card["text_fr"] = format_card_rules_text(new_text.strip(), ref_use)

    for no in by_no:
        if no not in sheet:
            skipped += 1

    print(f"Sheet rows with FR: {len(sheet)}")
    print(
        f"Updated: {updated}  Unchanged: {unchanged}  "
        f"Skipped (no sheet FR): {skipped}  Unknown id: {unknown}"
    )
    if samples:
        print("\nSample changes:")
        for no, old, new in samples:
            print(f"  {no}")
            print(f"    - {old!r}...")
            print(f"    + {new!r}...")

    if not args.apply:
        print("\nDry run — pass --apply to write cards.json")
        return 0

    CARDS_PATH.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"\nWrote {CARDS_PATH}")

    for script in ("validate_json.php", "validate_cards.php"):
        cmd = ["php", str(ROOT / "scripts" / script)]
        print("Running:", " ".join(cmd))
        rc = subprocess.call(cmd, cwd=str(ROOT))
        if rc != 0:
            return rc
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
