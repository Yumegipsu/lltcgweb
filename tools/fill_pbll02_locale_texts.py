#!/usr/bin/env python3
"""Fill/repair PBLL02 text_* from unique English strings (cached MT)."""
from __future__ import annotations

import json
import re
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from translate_text_es_batch import localize_brackets_to_es  # type: ignore
from translate_text_fr_batch import localize_brackets_to_fr  # type: ignore
from translate_text_ko_batch import localize_brackets_to_ko  # type: ignore
from translate_text_th_batch import localize_brackets as localize_brackets_to_th  # type: ignore
from translate_text_zh_batch import localize_brackets as localize_brackets_to_zh  # type: ignore

PACK = "プレミアムブースター ラブライブ！DUO"
CARDS = ROOT / "cards.json"
CACHE = ROOT / "locales" / "pbll02_mt_cache.json"

BRACKET_EN_TO_PT = {
    "[On Enter]": "[Ao Entrar]",
    "[Live Start]": "[Início de Live]",
    "[Live Success]": "[Sucesso de Live]",
    "[Activated]": "[Ativado]",
    "[Always]": "[Sempre]",
    "[Automatic]": "[Automático]",
    "[Auto]": "[Automático]",
    "[Once per turn]": "[Uma vez por turno]",
    "[Once per Turn]": "[Uma vez por turno]",
    "[Center]": "[Centro]",
}


def localize_brackets_to_pt(text: str) -> str:
    out = text
    for en, pt in sorted(BRACKET_EN_TO_PT.items(), key=lambda kv: -len(kv[0])):
        out = out.replace(en, pt)
    return out


def looks_like_en(text: str) -> bool:
    hits = sum(
        1
        for w in (" your ", " until ", " from ", " Member", " Waiting Room", "you gain", "Among ")
        if w in text
    )
    return hits >= 2


def protect_brackets(text: str) -> tuple[str, list[str]]:
    toks = re.findall(r"\[[^\]]+\]", text)
    out = text
    for i, tok in enumerate(toks):
        out = out.replace(tok, f"<<B{i}>>", 1)
    return out, toks


def restore_brackets(text: str, toks: list[str]) -> str:
    out = text
    for i, tok in enumerate(toks):
        for form in (f"<<B{i}>>", f"<<b{i}>>"):
            out = out.replace(form, tok)
    return out


def mt(text: str, target: str, cache: dict[str, str]) -> str:
    key = f"{target}::{text}"
    if key in cache and not looks_like_en(cache[key]):
        return cache[key]
    from deep_translator import GoogleTranslator  # type: ignore

    for attempt in range(5):
        try:
            out = GoogleTranslator(source="en", target=target).translate(text)
            time.sleep(0.12)
            if out and out.strip() and not looks_like_en(out):
                cache[key] = out
                return out
            if out and out.strip():
                cache[key] = out
                return out
        except Exception:
            time.sleep(0.8 * (attempt + 1))
    cache[key] = text
    return text


LOCALES = [
    ("text_es", "es", localize_brackets_to_es),
    ("text_ko", "ko", localize_brackets_to_ko),
    ("text_zh", "zh-CN", localize_brackets_to_zh),
    ("text_th", "th", localize_brackets_to_th),
    ("text_pt", "pt", localize_brackets_to_pt),
    ("text_fr", "fr", localize_brackets_to_fr),
]


def main() -> int:
    data = json.loads(CARDS.read_text(encoding="utf-8"))
    cache: dict[str, str] = {}
    if CACHE.is_file():
        cache = json.loads(CACHE.read_text(encoding="utf-8"))

    unique_en: dict[str, list[dict]] = {}
    for card in data["cards"]:
        if card.get("booster_pack") != PACK:
            continue
        en = (card.get("text") or "").strip()
        if not en:
            continue
        unique_en.setdefault(en, []).append(card)

    print(f"unique EN skill strings: {len(unique_en)}")
    filled = {k: 0 for k, _, _ in LOCALES}
    for en, cards in unique_en.items():
        body, toks = protect_brackets(en)
        for field, lang, localize in LOCALES:
            need = any(looks_like_en(c.get(field) or "") or not (c.get(field) or "").strip() for c in cards)
            if not need:
                continue
            translated = mt(body, lang, cache)
            loc = localize(restore_brackets(translated, toks))
            for c in cards:
                c[field] = loc
                filled[field] += 1

    CACHE.parent.mkdir(parents=True, exist_ok=True)
    CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("updated card-fields", filled, "cache", len(cache))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
