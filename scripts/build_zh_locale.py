#!/usr/bin/env python3
"""Build locales/zh.json (zh-Hans) from en_extracted + ko supplemental keys.

Uses GoogleTranslator + post-pass glossary so game terms stay consistent.
skillKw titles are forced to the locked bracket glossary.
"""
from __future__ import annotations

import json
import re
import time
from pathlib import Path

from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parent.parent
EN_PATH = ROOT / "locales" / "en_extracted.json"
KO_PATH = ROOT / "locales" / "ko.json"
OUT_PATH = ROOT / "locales" / "zh.json"
CACHE_PATH = ROOT / "locales" / "_zh_mt_cache.json"

# Locked skillKw titles / critical UI (never leave to MT)
OVERRIDES: dict[str, str] = {
    "language.label": "语言",
    "language.en": "English",
    "language.ja": "日本語",
    "language.es": "Español",
    "language.ko": "한국어",
    "language.zh": "简体中文",
    "skillKw.onEnter.title": "入场时",
    "skillKw.onLeave.title": "离场时",
    "skillKw.liveStart.title": "Live开始",
    "skillKw.liveSuccess.title": "Live成功",
    "skillKw.activated.title": "起动",
    "skillKw.always.title": "永续",
    "skillKw.oncePerTurn.title": "每回合1次",
    "skillKw.automatic.title": "自动",
    "skillKw.center.title": "中央",
    "skillKw.yell.title": "Yell",
    "skillKw.wait.title": "Wait（待机）",
    "cardType.member": "成员",
    "cardType.live": "Live",
    "cardType.energy": "能量",
}

# Longest-first post-MT term fixes (bad MT → preferred Loveca CN)
TERM_FIX: list[tuple[str, str]] = [
    ("主相", "主要阶段"),
    ("主要阶段位", "主要阶段"),
    ("等候室", "等候室"),
    ("候车室", "等候室"),
    ("等待室", "等候室"),
    ("成员卡", "成员卡"),
    ("会员", "成员"),
    ("心形", "心形"),
    ("红心", "心形"),
    ("能量甲板", "能量牌组"),
    ("马林甘", "换牌"),
    ("门禁", "换牌"),
    ("指挥棒通行证", "接棒"),
    ("指挥交接", "接棒"),
    ("接力棒", "接棒"),
    ("舞台", "舞台"),
    ("成功桩", "成功区"),
    ("成功堆", "成功区"),
    ("成功生活", "成功Live"),
    ("现场阶段", "Live阶段"),
    ("性能阶段", "表演阶段"),
    ("性能", "表演"),
    ("刀片", "Blade"),
    ("喊叫", "Yell"),
    ("黄包车", "接棒"),
]


def leaf_items(node: dict, prefix: str = "") -> list[tuple[str, str]]:
    out: list[tuple[str, str]] = []
    for k, v in node.items():
        path = f"{prefix}.{k}" if prefix else str(k)
        if isinstance(v, dict):
            out.extend(leaf_items(v, path))
        else:
            out.append((path, str(v)))
    return out


def set_leaf(root: dict, dotted: str, value: str) -> None:
    parts = dotted.split(".")
    cur = root
    for p in parts[:-1]:
        cur = cur.setdefault(p, {})
    cur[parts[-1]] = value


def apply_term_fix(text: str) -> str:
    out = text
    for bad, good in sorted(TERM_FIX, key=lambda p: len(p[0]), reverse=True):
        out = out.replace(bad, good)
    return out


def translate_batch(texts: list[str], cache: dict[str, str], translator: GoogleTranslator) -> dict[str, str]:
    result: dict[str, str] = {}
    pending: list[str] = []
    for t in texts:
        if t in cache:
            result[t] = cache[t]
        elif not t.strip():
            result[t] = t
            cache[t] = t
        elif not re.search(r"[A-Za-z]", t) and re.search(r"[\u4e00-\u9fff\u3040-\u30ff\uac00-\ud7af]", t):
            # Already CJK / mostly non-Latin — keep
            result[t] = t
            cache[t] = t
        else:
            pending.append(t)

    # Translate in small chunks (Google limit ~4500 chars)
    chunk: list[str] = []
    size = 0
    for t in pending:
        # Use newline join with markers
        if size + len(t) + 8 > 4000 and chunk:
            _flush(chunk, cache, result, translator)
            chunk = []
            size = 0
            time.sleep(0.4)
        chunk.append(t)
        size += len(t) + 8
    if chunk:
        _flush(chunk, cache, result, translator)
    return result


def _flush(chunk: list[str], cache: dict[str, str], result: dict[str, str], translator: GoogleTranslator) -> None:
    # Translate one-by-one for reliability (avoid split issues)
    for t in chunk:
        try:
            zh = translator.translate(t)
        except Exception as e:
            print(f"MT fail for {t[:60]!r}: {e}")
            zh = t
        zh = apply_term_fix(zh or t)
        cache[t] = zh
        result[t] = zh
        time.sleep(0.05)


def merge_structure_from_ko(en: dict, ko: dict) -> dict:
    """Prefer ko key tree (superset), values will be filled."""
    return json.loads(json.dumps(ko))  # deep copy structure


def main() -> None:
    en = json.loads(EN_PATH.read_text(encoding="utf-8"))
    ko = json.loads(KO_PATH.read_text(encoding="utf-8"))
    cache: dict[str, str] = {}
    if CACHE_PATH.exists():
        cache = json.loads(CACHE_PATH.read_text(encoding="utf-8"))

    en_map = dict(leaf_items(en))
    ko_map = dict(leaf_items(ko))
    # All keys = ko keys (superset of en)
    all_keys = sorted(set(en_map) | set(ko_map) | set(OVERRIDES))
    # Ensure language.zh exists
    all_keys = sorted(set(all_keys) | {"language.zh"})

    # Source English for each key
    sources: dict[str, str] = {}
    for key in all_keys:
        if key in OVERRIDES:
            continue
        if key in en_map:
            sources[key] = en_map[key]
        elif key.startswith("language.") and key.split(".", 1)[1] in ("en", "ja", "es", "ko"):
            # language labels in ko
            sources[key] = ko_map.get(key, key.split(".", 1)[1])
        else:
            # supplemental: use KO value only as hint → still need ZH; translate from EN-like if available
            # Prefer translating a reconstructed English: if missing from en, leave placeholder then fix
            sources[key] = en_map.get(key) or ""  # empty → later use Korean→ZH

    translator = GoogleTranslator(source="en", target="zh-CN")
    unique_en = sorted({v for v in sources.values() if v})
    print(f"Translating {len(unique_en)} unique EN strings (cache={len(cache)})…")
    mt = translate_batch(unique_en, cache, translator)
    CACHE_PATH.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # Also translate KO-only keys that have no EN (ko string → zh)
    ko_only_keys = [k for k in all_keys if k not in OVERRIDES and not sources.get(k) and k in ko_map]
    if ko_only_keys:
        print(f"Translating {len(ko_only_keys)} KO-only keys via ko→zh…")
        ko_translator = GoogleTranslator(source="ko", target="zh-CN")
        for k in ko_only_keys:
            ko_s = ko_map[k]
            if ko_s in cache:
                continue
            try:
                cache[ko_s] = apply_term_fix(ko_translator.translate(ko_s))
            except Exception:
                cache[ko_s] = ko_s
            time.sleep(0.05)
        CACHE_PATH.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # Build zh tree starting from ko structure for key coverage
    zh: dict = json.loads(json.dumps(ko))
    # Clear all leaf values then fill
    for key, _ in leaf_items(zh):
        set_leaf(zh, key, "")

    for key in all_keys:
        if key in OVERRIDES:
            set_leaf(zh, key, OVERRIDES[key])
            continue
        src = sources.get(key) or ""
        if src:
            set_leaf(zh, key, mt.get(src) or cache.get(src) or src)
        elif key in ko_map:
            set_leaf(zh, key, cache.get(ko_map[key], ko_map[key]))
        else:
            set_leaf(zh, key, key)

    # skillKw bodies via overrides of titles already set; fix empty leaves
    for key, val in leaf_items(zh):
        if not str(val).strip():
            set_leaf(zh, key, en_map.get(key) or ko_map.get(key) or key)

    # Ensure language.zh
    set_leaf(zh, "language.zh", "简体中文")

    OUT_PATH.write_text(json.dumps(zh, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # Coverage check vs en
    zh_keys = {k for k, _ in leaf_items(zh)}
    missing = [k for k in en_map if k not in zh_keys]
    print(f"Wrote {OUT_PATH} leaves={len(zh_keys)} en_missing={len(missing)}")
    if missing:
        print("MISSING", missing[:20])
        raise SystemExit(1)


if __name__ == "__main__":
    main()
