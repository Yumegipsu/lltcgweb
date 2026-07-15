#!/usr/bin/env python3
"""
Build tutorial_{locale}.json from tutorial.json + locales/{locale}.json + glossary.

Usage:
  python scripts/build_tutorial_locale_json.py --locale es
  python scripts/build_tutorial_locale_json.py --locale es --dry-run
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts"))

from tutorial_i18n_glossary import (  # noqa: E402
    TUTORIAL_UI_KEYS,
    has_english_tutorial_leak,
    has_mixed_english,
    translate_tutorial_line,
    tutorial_exact_override,
)

LOCALE_FILES = {
    "es": ROOT / "tutorial_es.json",
    "ja": ROOT / "tutorial_ja.json",
    "ko": ROOT / "tutorial_ko.json",
    "zh": ROOT / "tutorial_zh.json",
}


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def step_dialogues(tutorial: dict) -> dict[str, str]:
    return {s["id"]: (s.get("dialogue") or "").strip() for s in tutorial.get("steps", []) if s.get("id")}


def locale_tutorial_strings(locales: dict, locale: str) -> dict[str, str]:
    block = locales.get("tutorial") or {}
    if not isinstance(block, dict):
        return {}
    return {k: v for k, v in block.items() if k not in TUTORIAL_UI_KEYS and isinstance(v, str)}


def pick_translation(
    locale: str,
    step_id: str,
    en: str,
    curated: dict[str, str],
    existing: dict[str, str],
) -> str:
    exact = tutorial_exact_override(locale, step_id)
    if exact and not has_english_tutorial_leak(exact) and not has_mixed_english(exact):
        return exact
    if step_id in curated and curated[step_id].strip():
        candidate = translate_tutorial_line(curated[step_id].strip(), locale)
        if not has_english_tutorial_leak(candidate) and not has_mixed_english(candidate):
            return candidate
    if en:
        translated = translate_tutorial_line(en, locale)
        if not has_english_tutorial_leak(translated) and not has_mixed_english(translated):
            return translated
    if step_id in curated and curated[step_id].strip():
        return translate_tutorial_line(curated[step_id].strip(), locale)
    if en:
        return translate_tutorial_line(en, locale)
    return translate_tutorial_line(existing.get(step_id, ""), locale)


def build_locale(locale: str, dry_run: bool = False) -> int:
    if locale not in LOCALE_FILES:
        print(f"Unsupported locale: {locale}", file=sys.stderr)
        return 1

    tutorial_path = ROOT / "tutorial.json"
    locales_path = ROOT / "locales" / f"{locale}.json"
    out_path = LOCALE_FILES[locale]

    tutorial = load_json(tutorial_path)
    en_steps = step_dialogues(tutorial)
    curated: dict[str, str] = {}
    if locales_path.is_file():
        curated = locale_tutorial_strings(load_json(locales_path), locale)

    existing: dict[str, str] = {}
    if out_path.is_file():
        raw = load_json(out_path)
        existing = {k: v for k, v in raw.items() if isinstance(v, str)}

    out: dict[str, str] = {}
    repaired = 0

    for step_id, en in en_steps.items():
        text = pick_translation(locale, step_id, en, curated, existing)
        if has_english_tutorial_leak(text) or has_mixed_english(text):
            fallback = tutorial_exact_override(locale, step_id) or translate_tutorial_line(en or text)
            if not has_english_tutorial_leak(fallback) and not has_mixed_english(fallback):
                text = fallback
                repaired += 1
        out[step_id] = text

    # Legacy short-tutorial keys (welcome, goal, …) — glossary-pass only.
    for key, val in existing.items():
        if key in out or key in TUTORIAL_UI_KEYS:
            continue
        cleaned = translate_tutorial_line(val, locale)
        if has_english_tutorial_leak(cleaned):
            repaired += 1
        out[key] = cleaned

    # Stable key order: tutorial.json step order, then legacy alpha.
    ordered: dict[str, str] = {}
    for s in tutorial.get("steps", []):
        sid = s.get("id")
        if sid and sid in out:
            ordered[sid] = out[sid]
    for key in sorted(k for k in out if k not in ordered):
        ordered[key] = out[key]

    leaks = [
        k
        for k, v in ordered.items()
        if has_english_tutorial_leak(v) or (locale in ("es", "ko") and has_mixed_english(v))
    ]
    print(f"Built {locale}: {len(ordered)} strings, {repaired} glossary repairs, {len(leaks)} leaks remaining")
    if leaks:
        print("WARNING: English terms still present:", ", ".join(leaks[:12]), ("…" if len(leaks) > 12 else ""))

    if dry_run:
        for k in leaks[:5]:
            print(f"  {k}: {ordered[k][:100]!r}")
        return 1 if leaks else 0

    out_path.write_text(json.dumps(ordered, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {out_path}")
    return 1 if leaks else 0


def main() -> int:
    ap = argparse.ArgumentParser(description="Build tutorial_{locale}.json with full game-term translation.")
    ap.add_argument("--locale", default="es", choices=sorted(LOCALE_FILES.keys()))
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    return build_locale(args.locale, dry_run=args.dry_run)


if __name__ == "__main__":
    raise SystemExit(main())
