#!/usr/bin/env python3
"""Add zh labels to stamps_i18n.json (preserve existing ja/en/es/ko)."""
from __future__ import annotations

import json
import time
from pathlib import Path

from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parent.parent
STAMPS = ROOT / "stamps_i18n.json"
CACHE = ROOT / "locales" / "_zh_stamp_mt_cache.json"

# Hand fixes for catchphrases
ZH_BY_JA_EN: dict[tuple[str, str], str] = {
    ("", ""): "",
    ("ラブアローシュート！", "Love Arrow SHOT!"): "爱心箭射击！",
    ("にっこにっこにー♪", "Nico-Nico♪ Nii!"): "妮可妮可妮♪",
    ("ヨーソロー！", "Aye-Aye!"): "右满舵！",
    ("がんばルビィ！", "I'll Do My Rubesty"): "加油露比！",
    ("ハラショー！", "Harasho!"): "好极了！",
}


def main() -> None:
    data = json.loads(STAMPS.read_text(encoding="utf-8"))
    cache: dict[str, str] = {}
    if CACHE.exists():
        cache = json.loads(CACHE.read_text(encoding="utf-8"))
    tr = GoogleTranslator(source="en", target="zh-CN")
    labels = data.get("labels", {})
    for sid, row in labels.items():
        ja = row.get("ja", "")
        en = row.get("en", "")
        key = (ja, en)
        if key in ZH_BY_JA_EN:
            row["zh"] = ZH_BY_JA_EN[key]
            continue
        if not en and not ja:
            row["zh"] = ""
            continue
        src = en or ja
        if src in cache:
            row["zh"] = cache[src]
            continue
        try:
            zh = tr.translate(src)
        except Exception:
            zh = src
        cache[src] = zh
        row["zh"] = zh
        time.sleep(0.05)
    CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    STAMPS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    filled = sum(1 for r in labels.values() if r.get("zh"))
    print(f"Injected zh into {len(labels)} stamps ({filled} non-empty)")


if __name__ == "__main__":
    main()
