#!/usr/bin/env python3
"""Inject locales/fr.json into i18n.js STRINGS.fr."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from i18n_inject_lib import LOCALE_ORDER, inject_locale  # noqa: E402

FR_JSON = ROOT / "locales" / "fr.json"


def main() -> int:
    if not FR_JSON.is_file():
        print(f"Missing {FR_JSON}", file=sys.stderr)
        return 1
    data = json.loads(FR_JSON.read_text(encoding="utf-8"))
    order = LOCALE_ORDER[:]
    if "fr" not in order:
        order.append("fr")
    inject_locale("fr", data, locales_js=order)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
