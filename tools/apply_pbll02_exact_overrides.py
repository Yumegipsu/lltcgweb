#!/usr/bin/env python3
"""Apply curated exact overrides + glossary fill for remaining PBLL02 locales."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from translate_text_es_batch import translate_text as tes  # type: ignore
from translate_text_th_batch import apply_glossary as th_g  # type: ignore
from translate_text_th_batch import localize_brackets as th_b  # type: ignore
from translate_text_zh_batch import apply_glossary as zh_g  # type: ignore
from translate_text_zh_batch import localize_brackets as zh_b  # type: ignore

PACK = "プレミアムブースター ラブライブ！DUO"
CARDS = ROOT / "cards.json"

EXACT = {
    "[Always] You gain +1 Blade for each μ's card with a Score icon in your Success Live area.\n[Automatic] [Once per turn] When you Yell, perform 1 additional Yell for each μ's card with a Score icon among cards revealed for that Yell.": {
        "es": "[Siempre] Obtienes +1 Blade por cada carta de μ's con icono de Score en tu área de Live exitoso.\n[Automático] [Una vez por turno] Cuando haces Yell, realiza 1 Yell adicional por cada carta de μ's con icono de Score entre las cartas reveladas por ese Yell.",
        "ko": "[상시] 성공 라이브 영역에 있는 스코어 아이콘이 있는 μ's 카드 1장마다 Blade를 +1 얻습니다.\n[자동] [턴당 1회] Yell할 때, 그 Yell로 공개한 카드 중 스코어 아이콘이 있는 μ's 카드 1장마다 Yell을 1회 추가로 합니다.",
        "zh": "[永续] 你的成功Live区中每有1张带有Score图标的μ's卡，你获得+1 Blade。\n[自动] [每回合1次] 当你Yell时，该次Yell公开的卡中每有1张带有Score图标的μ's卡，额外进行1次Yell。",
        "th": "[ต่อเนื่อง] คุณได้รับ +1 Blade ต่อการ์ด μ's ที่มีไอคอน Score ในพื้นที่ Live สำเร็จ\n[อัตโนมัติ] [เทิร์นละ 1 ครั้ง] เมื่อคุณ Yell ให้ Yell เพิ่มอีก 1 ครั้งต่อการ์ด μ's ที่มีไอคอน Score ในการ์ดที่เปิดใน Yell นั้น",
        "pt": "[Sempre] Você ganha +1 Blade por cada carta μ's com ícone de Score na sua área de Live bem-sucedido.\n[Automático] [Uma vez por turno] Quando você der Yell, realize 1 Yell adicional por cada carta μ's com ícone de Score entre as cartas reveladas nesse Yell.",
        "fr": "[Permanent] Vous gagnez +1 Blade pour chaque carte μ's avec une icône Score dans votre zone de Live réussi.\n[Automatique] [Une fois par tour] Lorsque vous Yell, effectuez 1 Yell supplémentaire pour chaque carte μ's avec une icône Score parmi les cartes révélées pour ce Yell.",
    },
    "[Always] You gain +1 Blade for each lily white card in your Success Live area.": {
        "es": "[Siempre] Obtienes +1 Blade por cada carta lily white en tu área de Live exitoso.",
        "ko": "[상시] 성공 라이브 영역에 있는 lily white 카드 1장마다 Blade를 +1 얻습니다.",
        "zh": "[永续] 你的成功Live区中每有1张lily white卡，你获得+1 Blade。",
        "th": "[ต่อเนื่อง] คุณได้รับ +1 Blade ต่อการ์ด lily white ในพื้นที่ Live สำเร็จ",
        "pt": "[Sempre] Você ganha +1 Blade por cada carta lily white na sua área de Live bem-sucedido.",
        "fr": "[Permanent] Vous gagnez +1 Blade pour chaque carte lily white dans votre zone de Live réussi.",
    },
    "[Always] While you have no cards in your Success Live area, you gain +1 Blade.": {
        "es": "[Siempre] Mientras no tengas cartas en tu área de Live exitoso, obtienes +1 Blade.",
        "ko": "[상시] 성공 라이브 영역에 카드가 없는 한, Blade를 +1 얻습니다.",
        "zh": "[永续] 当你的成功Live区没有卡时，你获得+1 Blade。",
        "th": "[ต่อเนื่อง] ขณะที่คุณไม่มีการ์ดในพื้นที่ Live สำเร็จ คุณได้รับ +1 Blade",
        "pt": "[Sempre] Enquanto você não tiver cartas na sua área de Live bem-sucedido, você ganha +1 Blade.",
        "fr": "[Permanent] Tant que vous n'avez aucune carte dans votre zone de Live réussi, vous gagnez +1 Blade.",
    },
}

ES_PT = [
    ("área de Live exitoso", "área de Live bem-sucedido"),
    ("Sala de espera", "Sala de espera"),
    ("Escenario", "Palco"),
    ("Miembros", "Membros"),
    ("Miembro", "Membro"),
    ("Puedes", "Pode"),
    ("puedes", "pode"),
    ("Roba", "Compra"),
    ("roba", "compra"),
    ("Añade", "Adiciona"),
    ("añade", "adiciona"),
    ("hasta que termine este Live", "até que este Live termine"),
    ("Corazones grises", "Corações cinza"),
    ("puntuación", "pontuação"),
    ("Obtienes", "Você ganha"),
    ("obtienes", "você ganha"),
    ("Activa", "Ativa"),
    ("activa", "ativa"),
    ("Si ", "Se "),
    ("[Al entrar]", "[Ao Entrar]"),
    ("[Inicio de Live]", "[Início de Live]"),
    ("[Éxito de Live]", "[Sucesso de Live]"),
    ("[Activada]", "[Ativado]"),
    ("[Siempre]", "[Sempre]"),
    ("[Una vez por turno]", "[Uma vez por turno]"),
]


def looks(t: str) -> bool:
    return sum(
        1
        for w in (
            " your ", " until ", " from ", " Member", " Waiting Room", "you gain",
            "Among ", " this ", " discard ", " choose ", " opponent ", " put ", " draw ",
        )
        if w in (t or "")
    ) >= 2


def es_to_pt(es: str) -> str:
    out = es
    for a, b in sorted(ES_PT, key=lambda x: -len(x[0])):
        out = out.replace(a, b)
    return out


def main() -> int:
    data = json.loads(CARDS.read_text(encoding="utf-8"))
    exact_n = 0
    for c in data["cards"]:
        if c.get("booster_pack") != PACK:
            continue
        en = (c.get("text") or "").strip()
        if not en or "自分" in en or "&#" in en:
            continue
        if en in EXACT:
            for lang, val in EXACT[en].items():
                c[f"text_{lang}"] = val
            exact_n += 1
            continue
        if looks(c.get("text_zh") or "") or not (c.get("text_zh") or "").strip():
            c["text_zh"] = zh_b(zh_g(en))
        if looks(c.get("text_th") or "") or not (c.get("text_th") or "").strip():
            c["text_th"] = th_b(th_g(en))
        es = c.get("text_es") or ""
        if looks(es) or not es.strip():
            es = tes(en)
            c["text_es"] = es
        if looks(c.get("text_pt") or "") or not (c.get("text_pt") or "").strip():
            c["text_pt"] = es_to_pt(es)

    CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("exact cards", exact_n)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
