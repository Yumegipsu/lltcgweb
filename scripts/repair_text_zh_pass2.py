#!/usr/bin/env python3
"""Second-pass text_zh cleanup: protect quotes during MT, fix glossary drift."""
from __future__ import annotations

import json
import re
import time
from pathlib import Path

from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parent.parent
CARDS = ROOT / "cards.json"
CACHE = ROOT / "locales" / "_zh_skill_mt_cache2.json"
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

POST = [
    ("休息室", "等候室"),
    ("候车室", "等候室"),
    ("等待室", "等候室"),
    ("剑刃", "Blade"),
    ("刀刃", "Blade"),
    ("刀片", "Blade"),
    ("Baton Touch", "接棒"),
    ("指挥棒触摸", "接棒"),
    ("接力棒触摸", "接棒"),
    ("接棒触摸", "接棒"),
    ("主阶段", "主要阶段"),
    ("会员", "成员"),
    ("狂野之心", "任意心形"),
    ("狂野心", "任意心形"),
    ("喊叫", "Yell"),
    ("大喊", "Yell"),
    ("活体卡", "Live卡"),
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
    for br in BRACKETS:
        if br in out:
            tok = f"⟦B{len(vault)}⟧"
            vault.append(BRACKETS[br])
            out = out.replace(br, tok)
    # Protect quoted names — store already-localized form
    def qrepl(mo: re.Match[str]) -> str:
        inner = mo.group(1)
        zh = nm.get(inner, inner)
        tok = f"⟦Q{len(vault)}⟧"
        vault.append(f'"{zh}"')
        return tok

    out = re.sub(r'"([^"]+)"', qrepl, out)
    return out, vault


def restore(text: str, vault: list[str]) -> str:
    out = text
    for i, val in enumerate(vault):
        for tok in (f"⟦B{i}⟧", f"⟦Q{i}⟧", f"[B{i}]", f"[Q{i}]"):
            out = out.replace(tok, val)
    return out


def post_fix(text: str) -> str:
    out = text
    for a, b in POST:
        out = out.replace(a, b)
    for en, zh in BRACKETS.items():
        out = out.replace(en, zh)
    return out


def looks_bad(zh: str) -> bool:
    if not zh:
        return True
    # leftover EN connectors outside brackets/quotes/brands
    scrub = re.sub(r'"[^"]*"', "", zh)
    scrub = re.sub(r"\[[^\]]+\]", "", scrub)
    scrub = re.sub(
        r"\b(?:Blade|Yell|Wait|Live|μ's|Aqours|Liella!?|Nijigasaki|Hasunosora|CPU)\b",
        "",
        scrub,
        flags=re.I,
    )
    return bool(re.search(r"\b(?:the|and|from|into|your|this|that|with|for|or|to|of)\b", scrub, re.I))


def main() -> None:
    data = json.loads(CARDS.read_text(encoding="utf-8"))
    nm = load_maps()
    cache = json.loads(CACHE.read_text(encoding="utf-8")) if CACHE.exists() else {}
    tr = GoogleTranslator(source="en", target="zh-CN")
    fixed = 0
    for card in data["cards"]:
        en = (card.get("text") or "").strip()
        if not en:
            # still post-fix existing
            zh0 = card.get("text_zh") or ""
            zh1 = post_fix(zh0)
            if zh1 != zh0:
                card["text_zh"] = zh1
                fixed += 1
            continue
        cur = card.get("text_zh") or ""
        if cur and not looks_bad(cur):
            zh1 = post_fix(cur)
            # also refresh quoted names from maps when EN quotes present
            for en_name, zh_name in nm.items():
                zh1 = zh1.replace(f'"{en_name}"', f'"{zh_name}"')
            if zh1 != cur:
                card["text_zh"] = zh1
                fixed += 1
            continue
        # full remake with protection
        key = "p2::" + en
        if key in cache:
            zh = cache[key]
        else:
            protected, vault = protect(en, nm)
            try:
                zh = tr.translate(protected)
            except Exception as e:
                print("fail", e)
                zh = protected
            zh = restore(zh or "", vault)
            zh = post_fix(zh)
            cache[key] = zh
            time.sleep(0.045)
        for en_name, zh_name in nm.items():
            zh = zh.replace(f'"{en_name}"', f'"{zh_name}"')
        if card.get("text_zh") != zh:
            card["text_zh"] = zh
            fixed += 1
        if fixed and fixed % 80 == 0:
            print("…", fixed)
            CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    bad = sum(1 for c in data["cards"] if looks_bad(c.get("text_zh") or "") and (c.get("text") or "").strip())
    print(f"pass2 fixed={fixed} still_bad_en_connectors={bad}")


if __name__ == "__main__":
    main()
