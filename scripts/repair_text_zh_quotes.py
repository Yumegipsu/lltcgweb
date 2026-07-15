#!/usr/bin/env python3
"""Remake text_zh for cards whose EN rules quote character/song names."""
from __future__ import annotations

import json
import re
import time
from pathlib import Path

from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parent.parent
CARDS = ROOT / "cards.json"
CACHE = ROOT / "locales" / "_zh_skill_mt_cache3.json"
ZH_NAMES = ROOT / "locales" / "zh_names.json"
ZH_SONGS = ROOT / "locales" / "zh_songs.json"

BRACKETS = {
    "[On Enter]": "[入场时]",
    "[On Leave]": "[离场时]",
    "[Live Start]": "[Live开始]",
    "[Live Success]": "[Live成功]",
    "[Activated]": "[起动]",
    "[Always]": "[永续]",
    "[Continuous]": "[永续]",
    "[On Play]": "[永续]",
    "[Automatic]": "[自动]",
    "[Auto]": "[自动]",
    "[Once per Turn]": "[每回合1次]",
    "[Once per turn]": "[每回合1次]",
    "[Twice per Turn]": "[每回合2次]",
    "[Twice per turn]": "[每回合2次]",
    "[Center]": "[中央]",
    "[Yell]": "[Yell]",
    "[Left Side]": "[左侧]",
    "[Right Side]": "[右侧]",
}

GLOBAL_FIX = [
    ("虹崎", "虹咲"),
    ("休息室", "等候室"),
    ("候车室", "等候室"),
    ("等待室", "等候室"),
    ("剑刃", "Blade"),
    ("刀刃", "Blade"),
    ("Baton Touch", "接棒"),
    ("直播卡", "Live卡"),
    ("直播区", "Live区"),
    ("本场直播", "本次Live"),
    ("现场得分", "Live分数"),
    ("总现场得分", "总Live分数"),
    ("红心", "心形"),
    ("紫心勋章", "紫心形"),
    ("心勋章", "心形"),
]


def load_maps() -> dict[str, str]:
    names = json.loads(ZH_NAMES.read_text(encoding="utf-8"))
    songs = json.loads(ZH_SONGS.read_text(encoding="utf-8"))
    m = {}
    m.update(names.get("characters", {}))
    m.update(names.get("characters_jp", {}))
    for title, entry in songs.get("songs", {}).items():
        zh = entry.get("zh") if isinstance(entry, dict) else entry
        if zh:
            m[title] = zh
    return m


def protect(en: str, nm: dict[str, str]) -> tuple[str, list[str]]:
    vault: list[str] = []
    out = en
    for br, zhbr in BRACKETS.items():
        if br in out:
            tok = f"⟦B{len(vault)}⟧"
            vault.append(zhbr)
            out = out.replace(br, tok)

    def qrepl(mo: re.Match[str]) -> str:
        inner = mo.group(1)
        zh = nm.get(inner, inner)
        tok = f"⟦Q{len(vault)}⟧"
        vault.append(f'"{zh}"')
        return tok

    out = re.sub(r'"([^"]+)"', qrepl, out)
    return out, vault


def restore(text: str, vault: list[str]) -> str:
    out = text or ""
    for i, val in enumerate(vault):
        for tok in (f"⟦B{i}⟧", f"⟦Q{i}⟧", f"[B{i}]", f"[Q{i}]"):
            out = out.replace(tok, val)
    return out


def post(text: str) -> str:
    out = text
    for a, b in GLOBAL_FIX:
        out = out.replace(a, b)
    for en, zh in BRACKETS.items():
        out = out.replace(en, zh)
    return out


def missing_mapped_quotes(en: str, zh: str, nm: dict[str, str]) -> bool:
    quotes = re.findall(r'"([^"]+)"', en)
    for q in quotes:
        mapped = nm.get(q)
        if not mapped:
            continue
        if f'"{mapped}"' not in zh and ("「" + mapped + "」") not in zh:
            return True
    return False


def main() -> None:
    data = json.loads(CARDS.read_text(encoding="utf-8"))
    nm = load_maps()
    cache = json.loads(CACHE.read_text(encoding="utf-8")) if CACHE.exists() else {}
    tr = GoogleTranslator(source="en", target="zh-CN")
    fixed = 0
    for card in data["cards"]:
        en = (card.get("text") or "").strip()
        zh = (card.get("text_zh") or "").strip()
        if not en:
            if zh:
                card["text_zh"] = post(zh)
            continue
        need = (not zh) or missing_mapped_quotes(en, zh, nm) or any(bad in zh for bad in ("虹崎", "休息室", "Baton Touch", "剑刃"))
        if need:
            key = "p3::" + en
            if key in cache:
                new_zh = cache[key]
            else:
                protected, vault = protect(en, nm)
                try:
                    new_zh = tr.translate(protected)
                except Exception as e:
                    print("fail", e)
                    new_zh = protected
                new_zh = restore(new_zh, vault)
                new_zh = post(new_zh)
                cache[key] = new_zh
                time.sleep(0.04)
            card["text_zh"] = new_zh
            fixed += 1
        else:
            card["text_zh"] = post(zh)
        if fixed and fixed % 60 == 0:
            print("…", fixed)
            CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # Always apply global post to all
    for card in data["cards"]:
        if card.get("text_zh"):
            card["text_zh"] = post(card["text_zh"])

    CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"quote-aware remakes={fixed}")


if __name__ == "__main__":
    main()
