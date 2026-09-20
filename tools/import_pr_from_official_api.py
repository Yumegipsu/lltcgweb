#!/usr/bin/env python3
"""Scrape missing PR cards from the new official card-list API and merge into cards.json.

The old /cardlist/cardsearch_ex and /cardlist/detail endpoints redirect/404; the site
now serves:

  GET https://llofficial-cardgame.com/manage/card-list-user/list?expansion=PR&page=N
  GET https://llofficial-cardgame.com/manage/card-list-user/detail?cardno=...

Example:
  python tools/import_pr_from_official_api.py --dry-run
  python tools/import_pr_from_official_api.py --apply
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import time
import unicodedata
from datetime import datetime
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[1]
CHIICHAN = ROOT.parent / "Chiichan"
sys.path.insert(0, str(ROOT))

from import_from_db import (  # noqa: E402
    GROUP_SHORT,
    name_en_for,
    official_text_jp,
    row_to_card,
    strip_reminder_text,
    translate_effect_text,
)

CARDS_JSON = ROOT / "cards.json"
LIST_URL = "https://llofficial-cardgame.com/manage/card-list-user/list"
DETAIL_URL = "https://llofficial-cardgame.com/manage/card-list-user/detail"
IMAGE_BASE = "https://llofficial-cardgame.com/wordpress/wp-content/images/cardlist/"
UA = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json",
}

# New API uses short work_title values.
WORK_TO_SERIES = {
    "蓮ノ空": "蓮ノ空女学院スクールアイドルクラブ",
    "蓮ノ空女学院スクールアイドルクラブ": "蓮ノ空女学院スクールアイドルクラブ",
    "Aqours": "ラブライブ！サンシャイン!!",
    "ラブライブ！サンシャイン!!": "ラブライブ！サンシャイン!!",
    "μ's": "ラブライブ！",
    "ラブライブ！": "ラブライブ！",
    "虹ヶ咲": "ラブライブ！虹ヶ咲学園スクールアイドル同好会",
    "Nijigasaki": "ラブライブ！虹ヶ咲学園スクールアイドル同好会",
    "ラブライブ！虹ヶ咲学園スクールアイドル同好会": "ラブライブ！虹ヶ咲学園スクールアイドル同好会",
    "Liella": "ラブライブ！スーパースター!!",
    "Liella!": "ラブライブ！スーパースター!!",
    "結ヶ丘": "ラブライブ！スーパースター!!",
    "ラブライブ！スーパースター!!": "ラブライブ！スーパースター!!",
    "IKZ": "いきづらい部！",
    "いきづらい部！": "いきづらい部！",
}

# Extend GROUP_SHORT for the new franchise (and short titles if series already expanded).
GROUP_SHORT.setdefault("いきづらい部！", "Ikizurai")
GROUP_SHORT.setdefault("蓮ノ空", "Hasunosora")
GROUP_SHORT.setdefault("Aqours", "Sunshine")
GROUP_SHORT.setdefault("結ヶ丘", "Superstar")
GROUP_SHORT.setdefault("IKZ", "Ikizurai")

HEART_KEYS = {
    "heart01": "pink",
    "heart02": "red",
    "heart03": "yellow",
    "heart04": "green",
    "heart05": "blue",
    "heart06": "purple",
    "heart0": "any",
}

BLADE_HEART_JP = {
    "桃": "pink",
    "赤": "red",
    "黄": "yellow",
    "緑": "green",
    "青": "blue",
    "紫": "purple",
    "無2": "all2",
    "無": "any",
}


def norm(s: str | None) -> str:
    return unicodedata.normalize("NFKC", str(s or "").strip())


def session() -> requests.Session:
    s = requests.Session()
    s.headers.update(UA)
    return s


def list_pr_cards(sess: requests.Session, *, per_page: int = 100) -> list[dict]:
    items: list[dict] = []
    page = 1
    total = None
    while True:
        r = sess.get(
            LIST_URL,
            params={"expansion": "PR", "page": page, "per_page": per_page},
            timeout=45,
        )
        r.raise_for_status()
        data = r.json()
        batch = data.get("items") or []
        total = int(data.get("total") or total or 0)
        items.extend(batch)
        print(f"list page {page}: +{len(batch)} (have {len(items)}/{total})")
        if not batch or (total and len(items) >= total):
            break
        page += 1
        time.sleep(0.15)
    return items


def fetch_detail(sess: requests.Session, card_no: str) -> dict:
    r = sess.get(DETAIL_URL, params={"cardno": card_no}, timeout=45)
    r.raise_for_status()
    data = r.json()
    card = data.get("card")
    if not isinstance(card, dict):
        raise RuntimeError(f"No card payload for {card_no}")
    return data


def parse_blade_hearts(raw: str | None, blade_value: int | None) -> list[str]:
    text = norm(raw)
    if not text or text in {"-", ""}:
        return []
    out: list[str] = []
    # e.g. 緑1 / 紫2 / 無21
    for m in re.finditer(r"(桃|赤|黄|緑|青|紫|無2|無)\s*(\d*)", text):
        color = BLADE_HEART_JP.get(m.group(1))
        if not color:
            continue
        count = int(m.group(2) or "1")
        for _ in range(max(1, count)):
            if color not in out:
                out.append(color)
            elif color in ("all2", "any"):
                out.append(color)
    if not out and blade_value:
        # Blade present but no typed heart listed — leave empty (printed blade only).
        return []
    return out


def hearts_blob(api_card: dict) -> str:
    hearts: dict[str, int] = {}
    for key, color in HEART_KEYS.items():
        try:
            n = int(str(api_card.get(key) or "0"))
        except ValueError:
            n = 0
        if n > 0:
            hearts[color] = n
    blade_raw = str(api_card.get("blade_heart") or "")
    blade_val = None
    try:
        atk = str(api_card.get("attack") or "").strip()
        if atk not in {"", "-", "－"}:
            blade_val = int(atk)
    except ValueError:
        blade_val = None
    blade_hearts = parse_blade_hearts(blade_raw, blade_val)
    return json.dumps({"hearts": hearts, "blade_hearts": blade_hearts}, ensure_ascii=False)


def series_name_for(api_card: dict) -> str:
    work = norm(api_card.get("work_title"))
    return WORK_TO_SERIES.get(work, work)


def booster_pack_for(detail: dict, api_card: dict) -> str:
    exp = detail.get("expansion") if isinstance(detail.get("expansion"), dict) else {}
    name = norm(exp.get("name") if isinstance(exp, dict) else "")
    if name:
        return name
    if norm(api_card.get("expansion")) == "PR":
        return "PRカード"
    return "PRカード"


def rarity_for(api_card: dict) -> str:
    rare = norm(api_card.get("rare"))
    # Prefer fullwidth plus to match existing cards.json / deck rules.
    if rare == "PR+":
        return "PR＋"
    return rare


def card_number_for(api_card: dict) -> str:
    no = norm(api_card.get("card_number"))
    # Match catalog convention: PR＋ with fullwidth plus.
    no = no.replace("PR+", "PR＋")
    return no


def int_or_none(raw) -> int | None:
    s = norm(raw)
    if not s or s in {"-", "－"}:
        return None
    try:
        return int(s)
    except ValueError:
        return None


def api_to_scrape_row(detail: dict) -> dict:
    api_card = detail["card"]
    card_no = card_number_for(api_card)
    kind = norm(api_card.get("card_kind")) or "メンバー"
    text_html = api_card.get("text_html") or ""
    if not text_html and api_card.get("text") and str(api_card.get("text")).strip() not in {"", "-"}:
        # Fallback: wrap plain text (no icons).
        text_html = str(api_card.get("text")).replace("\n", "<br>")
    picture = norm(api_card.get("picture"))
    image_path = f"/wordpress/wp-content/images/cardlist/{picture}" if picture else ""
    blade = int_or_none(api_card.get("attack"))
    cost = int_or_none(api_card.get("cost"))
    score = int_or_none(api_card.get("power")) if kind == "ライブ" else None
    unit = norm(api_card.get("unit_name"))
    if unit in {"-", ""}:
        unit = ""
    name = norm(api_card.get("card_name"))
    if name in {"-", ""}:
        name = "Energy Card" if "エネルギー" in kind else f"Card {card_no}"
    return {
        "card_number": card_no,
        "card_name": name,
        "booster_pack": booster_pack_for(detail, api_card),
        "card_type": kind,
        "series_name": series_name_for(api_card),
        "participating_units": unit,
        "cost": cost,
        "hearts": hearts_blob(api_card),
        "blade_value": blade,
        "rarity": rarity_for(api_card),
        "card_image": image_path,
        "special_info": text_html if text_html not in {"-", ""} else "",
        "score": score,
        "special_heart": "",
    }


def existing_card_nos(cards: list[dict]) -> set[str]:
    return {norm(c.get("card_no")) for c in cards if c.get("card_no")}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--apply", action="store_true", help="Write cards.json + Chiichan scrape JSON")
    ap.add_argument("--delay", type=float, default=0.35)
    ap.add_argument(
        "--output-dir",
        type=Path,
        default=CHIICHAN,
        help="Where to write all_cards_pr_*.json",
    )
    args = ap.parse_args()

    data = json.loads(CARDS_JSON.read_text(encoding="utf-8"))
    cards: list[dict] = data.get("cards") or []
    have = existing_card_nos(cards)

    sess = session()
    listed = list_pr_cards(sess)
    official_nos = []
    for item in listed:
        no = card_number_for(item)
        if no:
            official_nos.append(no)
    missing = [n for n in official_nos if norm(n) not in have]
    print(f"Official PR: {len(official_nos)}; missing from cards.json: {len(missing)}")
    for n in missing:
        print(f"  missing {n}")

    scrape_rows: list[dict] = []
    new_app_cards: list[dict] = []
    failed: list[str] = []
    for i, no in enumerate(missing, 1):
        # Detail API accepts ASCII +; try both.
        tried = [no, no.replace("PR＋", "PR+")]
        detail = None
        last_err = None
        for candidate in tried:
            try:
                print(f"[{i}/{len(missing)}] detail {candidate}")
                detail = fetch_detail(sess, candidate)
                break
            except Exception as e:  # noqa: BLE001
                last_err = e
                time.sleep(args.delay)
        if detail is None:
            print(f"  FAIL {no}: {last_err}")
            failed.append(no)
            continue
        row = api_to_scrape_row(detail)
        scrape_rows.append(row)
        app = row_to_card(row)
        # Ensure EN name / JP text populated even when translate table misses.
        if not app.get("name_en"):
            app["name_en"] = name_en_for(app.get("name") or "")
        if app.get("text") == "" and row.get("special_info"):
            app["text"] = strip_reminder_text(
                translate_effect_text(row["special_info"], row["card_number"])
            )
        if app.get("text_jp") == "" and row.get("special_info"):
            app["text_jp"] = official_text_jp(row["special_info"])
        new_app_cards.append(app)
        time.sleep(args.delay)

    print(f"Fetched {len(scrape_rows)} / {len(missing)}; failed {len(failed)}")
    if failed:
        print("Failed:", ", ".join(failed))

    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    scrape_path = args.output_dir / f"all_cards_pr_{ts}.json"
    payload = {
        "timestamp": ts,
        "scrape_date": datetime.now().isoformat(),
        "expansion": "PR",
        "source": "manage/card-list-user",
        "total_cards": len(scrape_rows),
        "cards": scrape_rows,
    }

    if not args.apply:
        print(f"Dry-run only. Would write {scrape_path} and merge {len(new_app_cards)} cards.")
        for c in new_app_cards[:5]:
            print(
                f"  {c.get('card_no')} | {c.get('name_en')} | {c.get('card_type_en')} | "
                f"blade={c.get('blade')} | text={str(c.get('text') or '')[:80]!r}"
            )
        return 0 if not failed else 1

    args.output_dir.mkdir(parents=True, exist_ok=True)
    scrape_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote scrape JSON: {scrape_path}")

    by_no = {norm(c.get("card_no")): c for c in cards}
    added = 0
    for c in new_app_cards:
        key = norm(c.get("card_no"))
        if key in by_no:
            continue
        cards.append(c)
        by_no[key] = c
        added += 1
    data["cards"] = cards
    CARDS_JSON.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Merged {added} cards into {CARDS_JSON}")
    return 0 if not failed else 1


if __name__ == "__main__":
    raise SystemExit(main())
