#!/usr/bin/env python3
"""Scrape an expansion via manage/card-list-user JSON API (post-2026 card manager).

Produces Chiichan all_cards_*.json rows compatible with tools/import_cards_json_to_db.py.

Example:
  python tools/scrape_expansion_v2.py PBLL02
  python tools/scrape_expansion_v2.py PBLL02 --output-dir ../Chiichan
"""
from __future__ import annotations

import argparse
import json
import re
import time
from datetime import datetime
from pathlib import Path

import requests

TOOLS_DIR = Path(__file__).resolve().parent
LLTCGWEB_ROOT = TOOLS_DIR.parent
CHIICHAN_ROOT = LLTCGWEB_ROOT.parent / "Chiichan"

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

_COLOR_JP = {
    "赤": "red",
    "青": "blue",
    "黄": "yellow",
    "緑": "green",
    "桃": "pink",
    "紫": "purple",
    "無": "any",
}


def _strip_ability_text(text: str, text_html: str = "") -> str:
    """Prefer HTML (has icon alts) → 【brackets】 plain text for special_info."""
    src = text_html or text or ""
    if not src:
        return ""
    # Normalize icon imgs to 【alt】 when alt present
    def _img_repl(m: re.Match) -> str:
        alt = m.group(1) or ""
        alt = alt.replace("&#039;", "'").replace("&amp;", "&").strip()
        if not alt:
            return ""
        # Already bracketed names from site alts
        if alt.startswith("【") and alt.endswith("】"):
            return alt
        return f"【{alt}】"

    out = re.sub(
        r'<img[^>]*\salt=["\']([^"\']*)["\'][^>]*>',
        _img_repl,
        src,
        flags=re.I,
    )
    out = re.sub(r"<br\s*/?>", "\n", out, flags=re.I)
    out = re.sub(r"<[^>]+>", "", out)
    out = (
        out.replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&amp;", "&")
        .replace("&quot;", '"')
        .replace("&#039;", "'")
        .replace("&apos;", "'")
    )
    # Collapse whitespace but keep newlines
    lines = [" ".join(line.split()) for line in out.splitlines()]
    return "\n".join(line for line in lines if line).strip()


def _parse_heart_string(heart: str) -> dict[str, int]:
    hearts: dict[str, int] = {}
    if not heart:
        return hearts
    for color_jp, n in re.findall(r"([赤青黄緑桃紫無])(\d+)", heart):
        color = _COLOR_JP.get(color_jp)
        if not color:
            continue
        hearts[color] = hearts.get(color, 0) + int(n)
    return hearts


def _parse_blade_hearts(blade_heart: str, attack: str, card_kind: str) -> tuple[list, int | None]:
    """Return (blade_hearts list for DB/json, numeric blade for members)."""
    blade_value = None
    blade_hearts: list = []
    kind = card_kind or ""

    if "メンバー" in kind:
        # attack = blade count; blade_heart = color like 黄1
        try:
            blade_value = int(attack) if str(attack).isdigit() else 0
        except ValueError:
            blade_value = 0
        for color_jp, _n in re.findall(r"([赤青黄緑桃紫無])(\d+)", blade_heart or ""):
            color = _COLOR_JP.get(color_jp)
            if color and color not in blade_hearts:
                blade_hearts.append(color)
        if "ALL" in (blade_heart or "").upper() or "全" in (blade_heart or ""):
            if "all_blades" not in blade_hearts:
                blade_hearts.append("all_blades")
        return blade_hearts, blade_value

    # Lives / other: attack may be ALL1 / 黄1 / 無2 / -
    atk = (attack or "").strip()
    if atk.upper().startswith("ALL") or "全" in atk:
        blade_hearts.append("all_blades")
    else:
        for color_jp, _n in re.findall(r"([赤青黄緑桃紫無])(\d+)", atk):
            color = _COLOR_JP.get(color_jp)
            if color and color not in blade_hearts:
                blade_hearts.append(color)
    return blade_hearts, None


def _live_score_and_special(card: dict) -> tuple[int, str | None]:
    """Map new-API live fields → (score, special_heart filename)."""
    cost = (card.get("cost") or "").strip()
    special = None
    if "ドロー" in cost or cost.lower() == "draw":
        special = "icon_draw.png"
    elif "スコア" in cost or cost.lower() == "score":
        special = "icon_score.png"

    bh = card.get("blade_heart")
    try:
        score = int(bh) if str(bh).isdigit() else 0
    except ValueError:
        score = 0
    # Fallback: numeric attack
    if score == 0:
        atk = (card.get("attack") or "").strip()
        if atk.isdigit():
            score = int(atk)
    return score, special


def list_items(expansion: str, delay: float = 0.1) -> list[dict]:
    items: list[dict] = []
    page = 1
    while True:
        r = requests.get(
            LIST_URL,
            params={"expansion": expansion, "page": page},
            headers=UA,
            timeout=30,
        )
        r.raise_for_status()
        data = r.json()
        batch = data.get("items") or []
        total = int(data.get("total") or 0)
        print(f"page {page}: {len(batch)} (running {len(items) + len(batch)}/{total})")
        if not batch:
            break
        items.extend(batch)
        if len(items) >= total:
            break
        page += 1
        time.sleep(delay)
    return items


def fetch_detail(card_number: str) -> dict | None:
    r = requests.get(DETAIL_URL, params={"cardno": card_number}, headers=UA, timeout=30)
    if r.status_code != 200 or "json" not in (r.headers.get("content-type") or ""):
        return None
    data = r.json()
    return data.get("card") or data


def to_chiichan_row(card: dict, expansion_name: str) -> dict:
    picture = card.get("picture") or ""
    image = IMAGE_BASE + picture if picture and not picture.startswith("http") else picture
    kind = card.get("card_kind") or ""
    hearts = _parse_heart_string(card.get("heart") or "")
    blade_hearts, blade_value = _parse_blade_hearts(
        card.get("blade_heart") or "",
        card.get("attack") or "",
        kind,
    )
    # Prefer HTML so official_text_jp / texticon conversion works in import_from_db.
    special_html = (card.get("text_html") or "").strip() or (card.get("text") or "")
    special_plain = _strip_ability_text(card.get("text") or "", card.get("text_html") or "")

    cost = 0
    score = 0
    special_heart = None
    if "メンバー" in kind:
        try:
            cost = int(card.get("cost") or 0)
        except ValueError:
            cost = 0
    elif "ライブ" in kind:
        score, special_heart = _live_score_and_special(card)
        cost = 0
    else:
        cost = 0

    hearts_payload = {
        "hearts": hearts,
        "blade_hearts": blade_hearts,
    }

    # Normalize rarity PE＋ / P＋ fullwidth plus
    rarity = (card.get("rare") or "").replace("＋", "+")
    if rarity == "P＋":
        rarity = "P+"

    return {
        "card_number": card.get("card_number") or "",
        "card_name": card.get("card_name") or "",
        "card_type": kind,
        "rarity": rarity,
        "booster_pack": expansion_name,
        "series_name": card.get("work_title") or "",
        "participating_units": card.get("unit_name") or "",
        "cost": cost,
        "score": score,
        "blade_value": blade_value,
        "special_info": special_html,
        "special_ability": special_plain,
        "special_heart": special_heart or "",
        "hearts": json.dumps(hearts_payload, ensure_ascii=False),
        "card_image": image,
        "image_url": image,
        "expansion": card.get("expansion") or "",
        "color": card.get("color") or "",
    }


def scrape(expansion: str, output_dir: Path, delay: float = 0.2) -> Path:
    items = list_items(expansion, delay=delay)
    if not items:
        raise SystemExit(f"No cards for {expansion}")
    expansion_name = items[0].get("expansion_name") or expansion
    rows = []
    failed = []
    for i, item in enumerate(items, 1):
        code = item["card_number"]
        print(f"[{i}/{len(items)}] detail {code}")
        detail = fetch_detail(code)
        if not detail:
            print(f"  FAILED {code} — using list row")
            detail = item
            failed.append(code)
        if not detail.get("expansion_name"):
            detail["expansion_name"] = expansion_name
        # List row sometimes has better expansion_name
        rows.append(to_chiichan_row(detail, expansion_name))
        time.sleep(delay)

    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    out = output_dir / f"all_cards_{expansion.lower()}_{ts}.json"
    payload = {
        "timestamp": ts,
        "scrape_date": datetime.now().isoformat(),
        "expansion": expansion,
        "expansion_name": expansion_name,
        "total_cards": len(rows),
        "failed_detail": failed,
        "cards": rows,
        "api": "manage/card-list-user",
    }
    output_dir.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote {len(rows)} cards to {out}")
    if failed:
        print(f"Detail fallbacks: {len(failed)}")
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("expansion")
    ap.add_argument("--output-dir", type=Path, default=CHIICHAN_ROOT)
    ap.add_argument("--delay", type=float, default=0.2)
    ap.add_argument("--list-only", action="store_true")
    args = ap.parse_args()
    if args.list_only:
        items = list_items(args.expansion.upper())
        print(f"Total: {len(items)}")
        for it in items:
            print(it["card_number"])
        return 0
    scrape(args.expansion.upper(), args.output_dir, delay=args.delay)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
