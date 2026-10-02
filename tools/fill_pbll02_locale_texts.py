#!/usr/bin/env python3
"""Fill/repair PBLL02 English skill bodies into es/ko/zh/th/pt/fr via MyMemory."""
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

MM_LANG = {
    "text_es": ("es-ES", localize_brackets_to_es),
    "text_ko": ("ko-KR", localize_brackets_to_ko),
    "text_zh": ("zh-CN", localize_brackets_to_zh),
    "text_th": ("th-TH", localize_brackets_to_th),
    "text_pt": ("pt-BR", lambda t: localize_brackets_to_pt(t)),
    "text_fr": ("fr-FR", localize_brackets_to_fr),
}


def localize_brackets_to_pt(text: str) -> str:
    out = text
    for en, pt in sorted(BRACKET_EN_TO_PT.items(), key=lambda kv: -len(kv[0])):
        out = out.replace(en, pt)
    return out


def is_english_skill(text: str) -> bool:
    t = text or ""
    if not t.strip() or "自分" in t or "カード" in t or "&#" in t:
        return False
    return t.startswith("[") or "Member" in t or " you " in f" {t.lower()} "


def looks_like_en(text: str) -> bool:
    hits = sum(
        1
        for w in (
            " your ",
            " until ",
            " from ",
            " Member",
            " Waiting Room",
            "you gain",
            "Among ",
            " this ",
            " discard ",
            " choose ",
            " opponent ",
            " put ",
            " draw ",
        )
        if w in (text or "")
    )
    return hits >= 2


def protect_brackets(text: str) -> tuple[str, list[str]]:
    toks = re.findall(r"\[[^\]]+\]", text)
    out = text
    for i, tok in enumerate(toks):
        out = out.replace(tok, f"BR{i}X", 1)
    return out, toks


def restore_brackets(text: str, toks: list[str]) -> str:
    out = text
    for i, tok in enumerate(toks):
        for form in (f"BR{i}X", f"br{i}x", f"Br{i}x"):
            out = out.replace(form, tok)
    return out


def mt(text: str, target: str, cache: dict[str, str], force: bool = False) -> str:
    from deep_translator import MyMemoryTranslator  # type: ignore

    key = f"{target}::{text}"
    if not force and key in cache and cache[key] and not looks_like_en(cache[key]):
        return cache[key]
    # MyMemory ~500 char limit — chunk by paragraphs
    parts = text.split("\n")
    outs: list[str] = []
    for part in parts:
        if not part.strip():
            outs.append(part)
            continue
        chunks = [part[i : i + 450] for i in range(0, len(part), 450)] or [part]
        built = []
        for ch in chunks:
            ok = False
            for attempt in range(6):
                try:
                    tr = MyMemoryTranslator(source="en-GB", target=target).translate(ch)
                    time.sleep(0.35)
                    if tr and tr.strip():
                        built.append(tr.strip())
                        ok = True
                        break
                except Exception:
                    time.sleep(0.8 * (attempt + 1))
            if not ok:
                built.append(ch)
        outs.append(" ".join(built))
    result = "\n".join(outs)
    cache[key] = result
    return result


def main() -> int:
    data = json.loads(CARDS.read_text(encoding="utf-8"))
    cache: dict[str, str] = {}
    if CACHE.is_file():
        raw = json.loads(CACHE.read_text(encoding="utf-8"))
        cache = {k: v for k, v in raw.items() if v and not looks_like_en(v)}

    unique_en: dict[str, list[dict]] = {}
    for card in data["cards"]:
        if card.get("booster_pack") != PACK:
            continue
        en = (card.get("text") or "").strip()
        if not is_english_skill(en):
            continue
        unique_en.setdefault(en, []).append(card)

    print(f"unique EN skill strings: {len(unique_en)}", flush=True)
    filled = {k: 0 for k in MM_LANG}
    for i, (en, cards) in enumerate(unique_en.items(), 1):
        body, toks = protect_brackets(en)
        for field, (lang, localize) in MM_LANG.items():
            need = any(
                looks_like_en(c.get(field) or "") or not (c.get(field) or "").strip()
                for c in cards
            )
            if not need:
                continue
            force = any(looks_like_en(c.get(field) or "") for c in cards)
            translated = mt(body, lang, cache, force=force)
            loc = localize(restore_brackets(translated, toks))
            for c in cards:
                c[field] = loc
                filled[field] += 1
        if i % 3 == 0:
            print(f"  … {i}/{len(unique_en)} cache={len(cache)}", flush=True)
            CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("updated", filled, "cache", len(cache), flush=True)
    left = {k: 0 for k in MM_LANG}
    for en, cards in unique_en.items():
        for field in MM_LANG:
            if looks_like_en(cards[0].get(field) or ""):
                left[field] += 1
    print("still_en_like_unique", left, flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
