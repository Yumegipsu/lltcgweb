#!/usr/bin/env python3
"""Force-repair text_zh quality: re-MT any entry with residual English prose."""
from __future__ import annotations

import json
import re
import time
from pathlib import Path

from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parent.parent
CARDS = ROOT / "cards.json"
CACHE = ROOT / "locales" / "_zh_skill_mt_cache.json"
ZH_NAMES = ROOT / "locales" / "zh_names.json"
ZH_SONGS = ROOT / "locales" / "zh_songs.json"

BRACKET_EN_TO_ZH = {
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

KEEP_LATIN = re.compile(
    r"\b(?:Blade|Yell|Wait|Live|μ's|Aqours|Liella!?|Nijigasaki|Hasunosora|"
    r"QU4RTZ|R3BIRTH|DOLLCHESTRA|CatChu!?|Saint Snow|Sunny Passion|"
    r"Printemps|BiBi|lily white|AZALEA|CYaRon|Guilty Kiss|A-RISE|"
    r"DiverDiva|QU4RTZ|Cerise|Bouquet|Edel Note|KALEIDOSCORE|"
    r"START:DASH!!|WE WILL!!|CPU)\b",
    re.I,
)

# English leftover detector (after scrubbing quotes + brand Latin)
RESIDUAL_EN = re.compile(r"[A-Za-z]{3,}")


def localize_brackets(text: str) -> str:
    out = text
    for en, zh in BRACKET_EN_TO_ZH.items():
        out = out.replace(en, zh)
    return out


def name_map() -> dict[str, str]:
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


def replace_quoted(text: str, nm: dict[str, str]) -> str:
    def repl(mo: re.Match[str]) -> str:
        inner = mo.group(1)
        return f'"{nm[inner]}"' if inner in nm else mo.group(0)

    return re.sub(r'"([^"]+)"', repl, text)


def needs_repair(zh: str) -> bool:
    scrub = re.sub(r'"[^"]*"', "", zh or "")
    scrub = KEEP_LATIN.sub("", scrub)
    scrub = re.sub(r"\[|/|\+|×|\d+", " ", scrub)
    return bool(RESIDUAL_EN.search(scrub))


def mt_en(en: str, cache: dict[str, str], tr: GoogleTranslator) -> str:
    key = "full::" + en
    if key in cache:
        return cache[key]
    protected = en
    vault = []
    for br in BRACKET_EN_TO_ZH:
        if br in protected:
            token = f"⟦B{len(vault)}⟧"
            vault.append(br)
            protected = protected.replace(br, token)
    try:
        zh = tr.translate(protected)
    except Exception as e:
        print("fail", e, en[:60])
        zh = protected
    for i, br in enumerate(vault):
        zh = zh.replace(f"⟦B{i}⟧", br)
        zh = zh.replace(f"[B{i}]", br)
    zh = localize_brackets(zh or "")
    # Post term fixes
    fixes = [
        ("等候室", "等候室"),
        ("候车室", "等候室"),
        ("等待室", "等候室"),
        ("主阶段", "主要阶段"),
        ("会员", "成员"),
        ("刀片", "Blade"),
        ("喊叫", "Yell"),
    ]
    for a, b in fixes:
        zh = zh.replace(a, b)
    cache[key] = zh
    time.sleep(0.05)
    return zh


def main() -> None:
    data = json.loads(CARDS.read_text(encoding="utf-8"))
    cache = json.loads(CACHE.read_text(encoding="utf-8")) if CACHE.exists() else {}
    nm = name_map()
    tr = GoogleTranslator(source="en", target="zh-CN")
    fixed = 0
    for card in data["cards"]:
        en = (card.get("text") or "").strip()
        zh = (card.get("text_zh") or "").strip()
        if not en:
            continue
        if zh and not needs_repair(zh):
            continue
        new_zh = mt_en(en, cache, tr)
        new_zh = replace_quoted(new_zh, nm)
        new_zh = localize_brackets(new_zh)
        if card.get("text_zh") != new_zh:
            card["text_zh"] = new_zh
            fixed += 1
            if fixed % 50 == 0:
                print(f"… fixed {fixed}")
                CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
                CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    remaining = sum(1 for c in data["cards"] if needs_repair(c.get("text_zh") or "") and (c.get("text") or "").strip())
    print(f"repaired={fixed} still_need_review={remaining} cache={len(cache)}")


if __name__ == "__main__":
    main()
