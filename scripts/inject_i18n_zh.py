#!/usr/bin/env python3
"""Inject STRINGS.zh from locales/zh.json into i18n.js."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
I18N = ROOT / "i18n.js"
ZH = ROOT / "locales" / "zh.json"

zh = json.loads(ZH.read_text(encoding="utf-8"))
inner = json.dumps(zh, ensure_ascii=False, indent=2)
inner_lines = inner.split("\n")
formatted = '  "zh": ' + inner_lines[0]
for line in inner_lines[1:]:
    formatted += "\n  " + line

text = I18N.read_text(encoding="utf-8")

# Ensure LOCALES includes zh
if "'zh'" not in text.split("var LOCALES", 1)[1].split(";", 1)[0]:
    text = re.sub(
        r"var LOCALES = \[([^\]]+)\];",
        lambda m: "var LOCALES = [" + m.group(1).rstrip() + ", 'zh'];"
        if "'zh'" not in m.group(1)
        else m.group(0),
        text,
        count=1,
    )
    # Normalize if we doubled
    text = re.sub(
        r"var LOCALES = \[[^\]]*\];",
        "var LOCALES = ['en', 'ja', 'es', 'ko', 'zh'];",
        text,
        count=1,
    )

if re.search(r'\n  "zh": \{', text):
    text2, n = re.subn(
        r'\n  "zh": \{.*?\n  \}\n\};',
        "\n" + formatted + "\n};",
        text,
        count=1,
        flags=re.DOTALL,
    )
    if n != 1:
        raise SystemExit("inject_i18n_zh: failed to replace existing zh block")
    text = text2
else:
    # Insert after ko block
    text2, n = re.subn(
        r'(\n  "ko": \{.*?\n  \})\n\};',
        r"\1,\n" + formatted + "\n};",
        text,
        count=1,
        flags=re.DOTALL,
    )
    if n != 1:
        raise SystemExit("inject_i18n_zh: failed to insert zh after ko")
    text = text2

I18N.write_text(text, encoding="utf-8")
print(f"Injected zh into {I18N}")
