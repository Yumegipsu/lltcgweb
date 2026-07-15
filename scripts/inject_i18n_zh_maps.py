#!/usr/bin/env python3
"""Inject ZH_NAME_MAP and ZH_SONG_MAP into i18n.js from locales/zh_names.json
and locales/zh_songs.json.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
I18N = ROOT / "i18n.js"
ZH_NAMES = ROOT / "locales" / "zh_names.json"
ZH_SONGS = ROOT / "locales" / "zh_songs.json"

names = json.loads(ZH_NAMES.read_text(encoding="utf-8"))
songs = json.loads(ZH_SONGS.read_text(encoding="utf-8"))

name_map = {}
name_map.update(names.get("characters", {}))
name_map.update(names.get("characters_jp", {}))

song_map = {}
for title, entry in songs.get("songs", {}).items():
    if isinstance(entry, dict):
        zh = entry.get("zh")
    else:
        zh = entry
    if zh:
        song_map[title] = zh

name_map_js = json.dumps(name_map, ensure_ascii=False, indent=2, sort_keys=True)
song_map_js = json.dumps(song_map, ensure_ascii=False, indent=2, sort_keys=True)

block = (
    "  var ZH_NAME_MAP = " + name_map_js + ";\n"
    "  var ZH_SONG_MAP = " + song_map_js + ";\n\n"
)

text = I18N.read_text(encoding="utf-8")

text = re.sub(
    r"  var ZH_NAME_MAP = \{.*?\n\};\n  var ZH_SONG_MAP = \{.*?\n\};\n\n",
    "",
    text,
    flags=re.DOTALL,
)

marker = "  function cardLocaleName(card) {"
if marker not in text:
    raise SystemExit("Could not find cardLocaleName() in i18n.js")

text = text.replace(marker, block + marker, 1)

I18N.write_text(text, encoding="utf-8")
print(f"Injected ZH_NAME_MAP ({len(name_map)} entries) and ZH_SONG_MAP ({len(song_map)} entries) into {I18N}")
