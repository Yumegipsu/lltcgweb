#!/usr/bin/env python3
"""Populate cards.json text_zh for all rules-bearing cards (zh-Hans).

Pipeline:
  1. Localize EN skill brackets to locked ZH set
  2. Apply Chinese glossary (same English fragments as KO/ES pipelines)
  3. Replace \"quoted\" EN names with zh_names / zh_songs map forms
  4. Exact overrides from locales/batch_*_zh_exact.json when present
  5. Machine-translate remaining unique EN bodies (cached) then re-apply glossary

Usage:
  python scripts/translate_text_zh_batch.py --rebuild-all-zh
  python scripts/translate_text_zh_batch.py --repair-leaks
"""
from __future__ import annotations

import argparse
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

BRACKET_EN_TO_ZH: dict[str, str] = {
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

EN_SKILL_BRACKET_RE = re.compile(
    r"\[(?:On Enter|On Leave|Live Start|Live Success|Activated|Always|Continuous|"
    r"Once per [Tt]urn|Twice per [Tt]urn|Automatic|Auto|Center|Yell|On Play|Left Side|Right Side)\]"
)

# Longest-first EN → zh-Hans game terms
GLOSSARY: list[tuple[str, str]] = [
    ("Success Live area", "成功Live区"),
    ("Live Card Zone", "Live存放区"),
    ("Live zone", "Live存放区"),
    ("Left Side area", "左侧区域"),
    ("Left Side", "左侧"),
    ("Right Side", "右侧"),
    ("Center area", "中央区域"),
    ("Stage area", "舞台区域"),
    ("Energy Zone", "能量区"),
    ("Waiting Room", "等候室"),
    ("Required Hearts", "所需心形"),
    ("required hearts", "所需心形"),
    ("Live Score", "Live分数"),
    ("total Live Score", "总Live分数"),
    ("All Hearts", "全部心形"),
    ("revealed by Yell", "因Yell翻开的"),
    ("revealed for Yell", "为Yell翻开的"),
    ("entered your Stage this turn", "在本回合进入过你的舞台"),
    ("differently named", "名称不同的"),
    ("Cannot attempt a Live", "无法尝试Live"),
    ("While in Center", "在中央时"),
    ("opponent's", "对手的"),
    ("your opponent's", "对手的"),
    ("from your Stage", "从你的舞台"),
    ("from your Waiting Room", "从你的等候室"),
    ("into your Waiting Room", "放到你的等候室"),
    ("on top of your deck", "放到你的牌组顶"),
    ("on the bottom of your deck", "放到你的牌组底"),
    ("top of your deck", "牌组顶"),
    ("top 5 cards of your deck", "你的牌组上方5张"),
    ("top 3 cards of your deck", "你的牌组上方3张"),
    ("top 2 cards of your deck", "你的牌组上方2张"),
    ("You may", "你可以"),
    ("you may", "你可以"),
    ("choose", "选择"),
    ("Choose", "选择"),
    ("draw", "抽"),
    ("Draw", "抽"),
    ("shuffle", "洗切"),
    ("Shuffle", "洗切"),
    ("discard", "弃置"),
    ("Discard", "弃置"),
    ("return", "返回"),
    ("Return", "返回"),
    ("look at", "查看"),
    ("Look at", "查看"),
    ("put", "放置"),
    ("Put", "放置"),
    ("add", "加入"),
    ("Add", "加入"),
    ("gain", "获得"),
    ("Gain", "获得"),
    ("pay", "支付"),
    ("Pay", "支付"),
    ("Until this Live ends", "直到本次Live结束"),
    ("until end of turn", "直到回合结束"),
    ("this turn", "本回合"),
    ("this Live", "本次Live"),
    ("your Stage", "你的舞台"),
    ("your hand", "你的手牌"),
    ("your deck", "你的牌组"),
    ("your Waiting Room", "你的等候室"),
    ("Energy", "能量"),
    ("Member", "成员"),
    ("Members", "成员"),
    ("Hearts", "心形"),
    ("Heart", "心形"),
    ("Stage", "舞台"),
    ("hand", "手牌"),
    ("deck", "牌组"),
    ("Blade", "Blade"),
    ("Yell", "Yell"),
    ("Wait", "Wait"),
    ("Center", "中央"),
    ("Live", "Live"),
    ("score", "分数"),
    ("cost", "费用"),
    ("card", "卡"),
    ("cards", "卡"),
    ("and", "并"),
    ("or", "或"),
    ("then", "然后"),
]


def localize_brackets(text: str) -> str:
    out = text
    for en, zh in sorted(BRACKET_EN_TO_ZH.items(), key=lambda p: len(p[0]), reverse=True):
        out = out.replace(en, zh)
    return out


def apply_glossary(text: str) -> str:
    out = text
    for en, zh in sorted(GLOSSARY, key=lambda p: len(p[0]), reverse=True):
        out = re.sub(re.escape(en), zh, out)
    return out


def load_name_maps() -> dict[str, str]:
    names = json.loads(ZH_NAMES.read_text(encoding="utf-8"))
    songs = json.loads(ZH_SONGS.read_text(encoding="utf-8"))
    m: dict[str, str] = {}
    m.update(names.get("characters", {}))
    m.update(names.get("characters_jp", {}))
    for title, entry in songs.get("songs", {}).items():
        zh = entry.get("zh") if isinstance(entry, dict) else entry
        if zh:
            m[title] = zh
    return m


def replace_quoted_names(text: str, name_map: dict[str, str]) -> str:
    def repl(match: re.Match[str]) -> str:
        inner = match.group(1)
        if inner in name_map:
            return f'"{name_map[inner]}"'
        return match.group(0)

    return re.sub(r'"([^"]+)"', repl, text)


def load_exact_maps() -> dict[str, str]:
    exact: dict[str, str] = {}
    for path in sorted((ROOT / "locales").glob("batch_*_zh_exact.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            exact.update({str(k): str(v) for k, v in data.items()})
    return exact


def has_en_brackets(text: str) -> bool:
    return bool(EN_SKILL_BRACKET_RE.search(text or ""))


LEAK_RE = re.compile(
    r"\b(?:Waiting Room|Required Hearts|On Enter|Live Start|Activated|Once per turn|"
    r"Member|Energy|Stage|hand|deck|opponent|choose|draw|shuffle)\b",
    re.I,
)


def has_leak(text: str) -> bool:
    # Ignore quoted names and Latin brands
    scrub = re.sub(r'"[^"]*"', "", text or "")
    scrub = re.sub(r"\b(?:Blade|Yell|Wait|Live|μ's|Aqours|Liella!|Nijigasaki|Hasunosora)\b", "", scrub)
    return bool(LEAK_RE.search(scrub))


def translate_en_body(en: str, cache: dict[str, str], translator: GoogleTranslator) -> str:
    if en in cache:
        return cache[en]
    # Protect brackets
    protected = en
    vault: list[str] = []
    for br in BRACKET_EN_TO_ZH:
        if br in protected:
            idx = len(vault)
            vault.append(br)
            protected = protected.replace(br, f"⟦{idx}⟧")
    try:
        zh = translator.translate(protected)
    except Exception as e:
        print(f"MT fail: {e} :: {en[:80]}")
        zh = protected
    for idx, br in enumerate(vault):
        zh = zh.replace(f"⟦{idx}⟧", br)
        zh = zh.replace(f"[[{idx}]]", br)
    zh = localize_brackets(zh)
    zh = apply_glossary(zh)
    cache[en] = zh
    time.sleep(0.04)
    return zh


def build_text_zh(en: str, name_map: dict[str, str], exact: dict[str, str], cache: dict[str, str], translator: GoogleTranslator) -> str:
    en = (en or "").strip()
    if not en:
        return ""
    if en in exact:
        return exact[en]
    # Prefer glossary path first
    draft = localize_brackets(en)
    draft = apply_glossary(draft)
    draft = replace_quoted_names(draft, name_map)
    if not has_en_brackets(draft) and not has_leak(draft):
        return draft
    # MT remaining unique body
    zh = translate_en_body(en, cache, translator)
    zh = localize_brackets(zh)
    zh = apply_glossary(zh)
    zh = replace_quoted_names(zh, name_map)
    return zh


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--rebuild-all-zh", action="store_true")
    ap.add_argument("--repair-leaks", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    if not args.rebuild_all_zh and not args.repair_leaks:
        ap.error("Specify --rebuild-all-zh and/or --repair-leaks")

    data = json.loads(CARDS.read_text(encoding="utf-8"))
    cards = data["cards"]
    name_map = load_name_maps()
    exact = load_exact_maps()
    cache: dict[str, str] = {}
    if CACHE.exists():
        cache = json.loads(CACHE.read_text(encoding="utf-8"))
    translator = GoogleTranslator(source="en", target="zh-CN")

    updated = 0
    for card in cards:
        en = (card.get("text") or "").strip()
        if not en:
            # JP-only rules: copy text_jp through glossary lightly / leave empty
            jp = (card.get("text_jp") or "").strip()
            if not jp:
                continue
            # Prefer existing text_ko path? Use text_es if length short — MT from JP via Google ko?
            # Use English empty + JP as source with ja→zh MT
            if args.rebuild_all_zh or not (card.get("text_zh") or "").strip() or (
                args.repair_leaks and has_leak(card.get("text_zh") or "")
            ):
                key = f"jp::{jp}"
                if key not in cache:
                    try:
                        jt = GoogleTranslator(source="ja", target="zh-CN")
                        cache[key] = jt.translate(jp)
                        time.sleep(0.05)
                    except Exception:
                        cache[key] = jp
                zh = cache[key]
                if card.get("text_zh") != zh:
                    card["text_zh"] = zh
                    updated += 1
            continue

        need = args.rebuild_all_zh or not (card.get("text_zh") or "").strip()
        if args.repair_leaks and (has_en_brackets(card.get("text_zh") or "") or has_leak(card.get("text_zh") or "")):
            need = True
        if not need:
            continue
        zh = build_text_zh(en, name_map, exact, cache, translator)
        if card.get("text_zh") != zh:
            card["text_zh"] = zh
            updated += 1

    CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    if not args.dry_run:
        CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"text_zh updated={updated} cache={len(cache)}")


if __name__ == "__main__":
    main()
