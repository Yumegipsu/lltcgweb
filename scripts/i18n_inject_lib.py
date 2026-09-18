#!/usr/bin/env python3
"""Shared helpers to inject a STRINGS.<locale> block into i18n.js without eating peers."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
I18N = ROOT / "i18n.js"

LOCALE_ORDER = ["en", "ja", "es", "ko", "zh", "th", "pt", "fr"]


def format_locale_block(code: str, data: dict) -> str:
    inner = json.dumps(data, ensure_ascii=False, indent=2)
    lines = inner.split("\n")
    out = f'  "{code}": {lines[0]}'
    for line in lines[1:]:
        out += "\n  " + line
    return out


def find_strings_object(text: str) -> tuple[int, int]:
    """Return [start, end) indices of the STRINGS = { ... }; object body braces."""
    m = re.search(r"var STRINGS = \{", text)
    if not m:
        raise SystemExit("STRINGS object not found")
    start = m.end() - 1  # points at '{'
    depth = 0
    i = start
    while i < len(text):
        ch = text[i]
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                # expect };
                return start, i + 1
        i += 1
    raise SystemExit("unclosed STRINGS object")


def split_top_level_locales(body: str) -> dict[str, str]:
    """Parse top-level "xx": { ... } blocks inside STRINGS body (without outer braces)."""
    # body includes surrounding { }
    inner = body[1:-1]
    locales: dict[str, str] = {}
    # Find each "code": {
    for m in re.finditer(r'\n  "(en|ja|es|ko|zh|th|pt|fr)": \{', inner):
        code = m.group(1)
        brace_at = m.end() - 1
        depth = 0
        i = brace_at
        while i < len(inner):
            if inner[i] == "{":
                depth += 1
            elif inner[i] == "}":
                depth -= 1
                if depth == 0:
                    block = inner[m.start() + 1 : i + 1]  # from "code" through closing }
                    # m.start points at newline before spaces; +1 skip newline
                    # Actually keep leading spaces: inner[m.start():i+1] includes \n  "code"...
                    block = inner[m.start() + 1 : i + 1]
                    locales[code] = block.rstrip()
                    break
            i += 1
    return locales


def deep_merge(dst: dict, src: dict) -> dict:
    """Recursively merge src into dst (dicts only; scalars overwrite)."""
    for k, v in src.items():
        if isinstance(v, dict) and isinstance(dst.get(k), dict):
            deep_merge(dst[k], v)
        else:
            dst[k] = v
    return dst


def inject_locale(code: str, data: dict, locales_js: list[str] | None = None) -> None:
    """Replace STRINGS.<code> with data (full pack). Prefer inject_locale_merge for patches."""
    text = I18N.read_text(encoding="utf-8")
    if locales_js is None:
        locales_js = LOCALE_ORDER[:]
    # Normalize LOCALES array
    text = re.sub(
        r"var LOCALES = \[[^\]]*\];",
        "var LOCALES = [" + ", ".join(f"'{c}'" for c in locales_js) + "];",
        text,
        count=1,
    )

    start, end = find_strings_object(text)
    body = text[start:end]
    locales = split_top_level_locales(body)
    locales[code] = format_locale_block(code, data)

    # Rebuild in canonical order for known locales; keep any unknown after
    parts = []
    for c in LOCALE_ORDER:
        if c in locales:
            parts.append(locales.pop(c))
    for c, block in locales.items():
        parts.append(block)
    new_body = "{\n" + ",\n".join(parts) + "\n}"
    text = text[:start] + new_body + text[end:]
    I18N.write_text(text, encoding="utf-8")
    print(f"Injected {code} into {I18N} ({len(parts)} locale packs)")


def _parse_locale_json_block(block: str) -> dict:
    """Parse a formatted '"xx": { ... }' block into a dict."""
    m = re.match(r'\s*"(?:en|ja|es|ko|zh|th|pt|fr)":\s*(\{)', block)
    if not m:
        raise SystemExit(f"cannot parse locale block: {block[:40]!r}")
    start = m.start(1)
    depth = 0
    i = start
    while i < len(block):
        if block[i] == "{":
            depth += 1
        elif block[i] == "}":
            depth -= 1
            if depth == 0:
                raw = block[start : i + 1]
                raw = re.sub(r",(\s*[}\]])", r"\1", raw)
                return json.loads(raw)
        i += 1
    raise SystemExit("unclosed locale block")


def inject_locale_merge(code: str, patch: dict, locales_js: list[str] | None = None) -> dict:
    """Deep-merge patch into the existing STRINGS.<code> pack, then write i18n.js.

    Use this for language additions so incomplete JSON sources cannot wipe peers.
    Returns the merged locale dict.
    """
    text = I18N.read_text(encoding="utf-8")
    start, end = find_strings_object(text)
    body = text[start:end]
    locales = split_top_level_locales(body)
    if code not in locales:
        merged = deep_merge({}, patch)
    else:
        existing = _parse_locale_json_block(locales[code])
        merged = deep_merge(existing, patch)
    inject_locale(code, merged, locales_js=locales_js)
    return merged
