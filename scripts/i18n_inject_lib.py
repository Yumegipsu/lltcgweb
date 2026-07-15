#!/usr/bin/env python3
"""Shared helpers to inject a STRINGS.<locale> block into i18n.js without eating peers."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
I18N = ROOT / "i18n.js"

LOCALE_ORDER = ["en", "ja", "es", "ko", "zh"]


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
    for m in re.finditer(r'\n  "(en|ja|es|ko|zh)": \{', inner):
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


def inject_locale(code: str, data: dict, locales_js: list[str] | None = None) -> None:
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
