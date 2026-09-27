"""Slice the rank sheet and composite Season 1 title pills.

Sources are the operator art drops. Outputs:
  client/img/ranks/{c,b,a,s}-{green,pink}.png
  client/img/ranks/title-base.png
  client/img/titles/season-2026-10-*.png
"""
from __future__ import annotations

import colorsys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
ASSETS = Path(r"C:\Users\super\.cursor\projects\c-Users-super-OneDrive-Documents-GitHub-lltcgweb\assets")
SHEET = next(ASSETS.glob("*loveca_ranking_icons*.png"))
PILL = next(ASSETS.glob("*star_event_base_target_score*.png"))
RANK_DIR = ROOT / "client" / "img" / "ranks"
TITLE_DIR = ROOT / "client" / "img" / "titles"
FONT = ROOT / "client" / "fonts" / "Nunito-Bold.ttf"

# Sheet order: top row pink S A B C, bottom row green S A B C.
TOP = ["s-pink", "a-pink", "b-pink", "c-pink"]
BOTTOM = ["s-green", "a-green", "b-green", "c-green"]


def knock_out_black(im: Image.Image, cutoff: int = 28) -> Image.Image:
    im = im.convert("RGBA")
    pix = im.load()
    w, h = im.size
    for y in range(h):
        for x in range(w):
            r, g, b, a = pix[x, y]
            if r <= cutoff and g <= cutoff and b <= cutoff:
                pix[x, y] = (0, 0, 0, 0)
    return im


def components(im: Image.Image) -> list[tuple[int, int, int, int]]:
    w, h = im.size
    pix = im.load()
    seen = [[False] * w for _ in range(h)]
    boxes = []
    for y in range(h):
        for x in range(w):
            if seen[y][x] or pix[x, y][3] < 16:
                seen[y][x] = True
                continue
            stack = [(x, y)]
            seen[y][x] = True
            minx = maxx = x
            miny = maxy = y
            count = 0
            while stack:
                cx, cy = stack.pop()
                count += 1
                minx = min(minx, cx)
                maxx = max(maxx, cx)
                miny = min(miny, cy)
                maxy = max(maxy, cy)
                for nx, ny in ((cx - 1, cy), (cx + 1, cy), (cx, cy - 1), (cx, cy + 1)):
                    if nx < 0 or ny < 0 or nx >= w or ny >= h or seen[ny][nx]:
                        continue
                    seen[ny][nx] = True
                    if pix[nx, ny][3] >= 16:
                        stack.append((nx, ny))
            if count > 80:
                boxes.append((minx, miny, maxx + 1, maxy + 1))
    boxes.sort(key=lambda b: (b[1], b[0]))
    return boxes


def retint(im: Image.Image, tone: str, letter: str) -> Image.Image:
    im = im.convert("RGBA")
    pix = im.load()
    shift = 115 / 360 if tone == "green" else 0
    sat_mul = 1.0
    if tone == "pink":
        sat_mul = {"S": 1.22, "A": 1.08, "B": 1.0, "C": 0.9}[letter]
    w, h = im.size
    for y in range(h):
        for x in range(w):
            r, g, b, a = pix[x, y]
            if a < 8:
                continue
            rf, gf, bf = r / 255, g / 255, b / 255
            hue, light, sat = colorsys.rgb_to_hls(rf, gf, bf)
            if sat < 0.12 or light > 0.94:
                continue
            hue = (hue + shift) % 1
            sat = max(0, min(1, sat * sat_mul))
            if letter == "S" and tone == "pink":
                light = max(0, min(1, light * 1.04))
            nr, ng, nb = colorsys.hls_to_rgb(hue, light, sat)
            pix[x, y] = (int(nr * 255), int(ng * 255), int(nb * 255), a)
    return im


def compose_title(base: Image.Image, icon: Image.Image, tone: str, letter: str, text: str) -> Image.Image:
    pill = retint(base, tone, letter)
    scale = 3
    pill = pill.resize((pill.width * scale, pill.height * scale), Image.Resampling.LANCZOS)
    icon_h = int(pill.height * 0.78)
    icon = icon.resize((icon_h, icon_h), Image.Resampling.LANCZOS)
    pad = int(pill.height * 0.12)
    pill.alpha_composite(icon, (pad, (pill.height - icon_h) // 2))
    draw = ImageDraw.Draw(pill)
    size = max(18, int(pill.height * 0.36))
    font = ImageFont.truetype(str(FONT), size)
    fill = (36, 92, 64, 255) if tone == "green" else (110, 42, 78, 255)
    tx = pad + icon_h + int(pill.height * 0.12)
    bbox = draw.textbbox((0, 0), text, font=font)
    th = bbox[3] - bbox[1]
    ty = (pill.height - th) // 2 - bbox[1]
    draw.text((tx, ty), text, font=font, fill=fill)
    return pill


def main() -> None:
    RANK_DIR.mkdir(parents=True, exist_ok=True)
    TITLE_DIR.mkdir(parents=True, exist_ok=True)
    sheet = knock_out_black(Image.open(SHEET))
    boxes = components(sheet)
    if len(boxes) != 8:
        raise SystemExit(f"expected 8 rank icons, found {len(boxes)}")
    mid = sum(b[1] for b in boxes) / len(boxes)
    top = sorted([b for b in boxes if b[1] < mid], key=lambda b: b[0])
    bottom = sorted([b for b in boxes if b[1] >= mid], key=lambda b: b[0])
    icons = {}
    for name, box in zip(TOP, top):
        icons[name] = sheet.crop(box)
        icons[name].save(RANK_DIR / f"{name}.png")
    for name, box in zip(BOTTOM, bottom):
        icons[name] = sheet.crop(box)
        icons[name].save(RANK_DIR / f"{name}.png")
    pill = Image.open(PILL).convert("RGBA")
    pill.save(RANK_DIR / "title-base.png")
    order = ["c-green", "c-pink", "b-green", "b-pink", "a-green", "a-pink", "s-green", "s-pink"]
    for key in order:
        tone = "green" if key.endswith("green") else "pink"
        letter = key[0].upper()
        title = compose_title(pill, icons[key], tone, letter, "Season 1")
        title.save(TITLE_DIR / f"season-2026-10-{key}.png")
    print(f"wrote {len(icons)} icons and {len(order)} titles")


if __name__ == "__main__":
    main()
