#!/usr/bin/env python3
"""Translate news, stamps, and tutorial dialogue to French (glossary-assisted)."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

GLOSSARY = [
    ("Waiting Room", "Salle d'attente"),
    ("Main Phase", "Phase principale"),
    ("Live Phase", "Phase Live"),
    ("Baton Touch", "Baton Touch"),
    ("Required Hearts", "Cœurs requis"),
    ("Sticker Exchange", "Échange de stickers"),
    ("Deck Builder", "Constructeur de deck"),
    ("Ranked", "Classé"),
    ("Unranked", "Non classé"),
    ("Tournament", "Tournoi"),
    ("Energy", "Énergie"),
    ("Stage", "Scène"),
    ("Member", "Membre"),
    ("Hearts", "Cœurs"),
    ("Heart", "Cœur"),
    ("Mulligan", "Mulligan"),
    ("Blade", "Blade"),
    ("Success", "Réussite"),
    ("Performance", "Performance"),
]


def translate(en: str) -> str:
    if not en:
        return en
    out = en
    for a, b in sorted(GLOSSARY, key=lambda p: len(p[0]), reverse=True):
        out = out.replace(a, b)
    # Light phrasing for common UI
    reps = [
        (r"\bYou can\b", "Vous pouvez"),
        (r"\bTap\b", "Appuyez sur"),
        (r"\bClick\b", "Cliquez"),
        (r"\bSelect\b", "Sélectionnez"),
        (r"\bChoose\b", "Choisissez"),
        (r"\bDraw\b", "Piochez"),
        (r"\bPlay\b", "Jouez"),
        (r"\bfixed\b", "corrigé"),
        (r"\badded\b", "ajouté"),
        (r"\bupdated\b", "mis à jour"),
        (r"\bNew\b", "Nouveau"),
    ]
    for pat, rep in reps:
        out = re.sub(pat, rep, out)
    return out


def news() -> None:
    path = ROOT / "news.json"
    data = json.loads(path.read_text(encoding="utf-8"))

    def walk(obj):
        n = 0
        if isinstance(obj, list):
            for i in obj:
                n += walk(i)
        elif isinstance(obj, dict):
            for field in ("title", "body"):
                block = obj.get(field)
                if isinstance(block, dict) and block.get("en"):
                    if not (block.get("fr") or "").strip():
                        block["fr"] = translate(block["en"])
                        n += 1
            for v in obj.values():
                n += walk(v)
        return n

    n = walk(data)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"news.fr fields: {n}")


def stamps() -> None:
    path = ROOT / "stamps_i18n.json"
    if not path.is_file():
        print("no stamps")
        return
    data = json.loads(path.read_text(encoding="utf-8"))
    n = 0
    if isinstance(data, dict):
        for _id, block in data.items():
            if not isinstance(block, dict):
                continue
            en = block.get("en") or ""
            if en and not (block.get("fr") or "").strip():
                block["fr"] = translate(en)
                n += 1
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"stamps fr: {n}")


def tutorial() -> None:
    en_path = ROOT / "tutorial.json"
    out_path = ROOT / "tutorial_fr.json"
    src: dict[str, str] = {}
    if en_path.is_file():
        en = json.loads(en_path.read_text(encoding="utf-8"))
        if isinstance(en, list):
            for step in en:
                if isinstance(step, dict) and step.get("id") and step.get("dialogue"):
                    src[step["id"]] = step["dialogue"]
                    if step.get("dialogue_portrait"):
                        src[step["id"] + "_portrait"] = step["dialogue_portrait"]
        elif isinstance(en, dict):
            src = {k: v for k, v in en.items() if isinstance(v, str)}
    if not src and (ROOT / "tutorial_pt.json").is_file():
        src = json.loads((ROOT / "tutorial_pt.json").read_text(encoding="utf-8"))
    fr = {k: translate(v) if isinstance(v, str) else v for k, v in src.items()}
    out_path.write_text(json.dumps(fr, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"tutorial_fr.json keys: {len(fr)}")


def main() -> int:
    news()
    stamps()
    tutorial()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
