#!/usr/bin/env python3
import json
import re
from pathlib import Path

PACK = "プレミアムブースター ラブライブ！DUO"
KEEP = re.compile(
    r"\b(?:Blade|Yell|Wait|Live|Member|Energy|Stage|Center|Printemps|BiBi|"
    r"lily white|Baton Touch|Success Live|Waiting Room|Gray|Yellow|Pink|Purple|Hearts?)\b",
    re.I,
)
FUN = re.compile(
    r"\b(?:your|until|from|this|that|into|with|when|may|put|draw|choose|"
    r"opponent|discard|gain|reduce|among|while|every|cards?|add|look|"
    r"reveal|activate|required|score|hand|deck)\b",
    re.I,
)


def residual(t: str) -> bool:
    s = KEEP.sub(" ", t or "")
    s = re.sub(r"\[[^\]]*\]", " ", s)
    s = re.sub(r'"[^"]*"', " ", s)
    s = s.replace("μ's", " ")
    return len(FUN.findall(s)) >= 3


def main() -> None:
    data = json.loads(Path("cards.json").read_text(encoding="utf-8"))
    for f in ["text_es", "text_ko", "text_zh", "text_th", "text_pt", "text_fr"]:
        n = 0
        samp = []
        for c in data["cards"]:
            if c.get("booster_pack") != PACK:
                continue
            if not (c.get("text") or "").strip():
                continue
            t = c.get(f) or ""
            if residual(t) or not t.strip():
                n += 1
                if len(samp) < 3:
                    samp.append(f"{c['card_no']}: {t[:180]}")
        print(f"{f}: {n}")
        for s in samp:
            print(" ", s)


if __name__ == "__main__":
    main()
