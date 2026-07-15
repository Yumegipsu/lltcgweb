#!/usr/bin/env python3
"""Inject STRINGS.es from locales/es.json into i18n.js (safe multi-locale)."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts"))
from i18n_inject_lib import inject_locale  # noqa: E402

es = json.loads((ROOT / "locales" / "es.json").read_text(encoding="utf-8"))
inject_locale("es", es)
