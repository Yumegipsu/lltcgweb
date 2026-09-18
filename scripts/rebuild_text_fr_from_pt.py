#!/usr/bin/env python3
"""Rebuild text_fr from text_pt (Romance peer) + locked FR brackets."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CARDS = ROOT / "cards.json"

PT_BR_TO_FR = {
    "[Ao Entrar]": "[À l'entrée]",
    "[Ao Sair]": "[À la sortie]",
    "[Início de Live]": "[Début de Live]",
    "[Live Bem-Sucedida]": "[Live réussi]",
    "[Ativado]": "[Activé]",
    "[Sempre]": "[Permanent]",
    "[Automático]": "[Automatique]",
    "[Uma vez por turno]": "[Une fois par tour]",
    "[Duas vezes por turno]": "[Deux fois par tour]",
    "[Centro]": "[Centre]",
    "[Lado Esquerdo]": "[Côté gauche]",
    "[Lado Direito]": "[Côté droit]",
    "[Yell]": "[Yell]",
}

PHRASES = [
    ("Sala de Espera", "Salle d'attente"),
    ("Fase Principal", "Phase principale"),
    ("Fase Live", "Phase Live"),
    ("Passe de Bastão", "Baton Touch"),
    ("corações necessários", "Cœurs requis"),
    ("Corações necessários", "Cœurs requis"),
    ("Corações", "Cœurs"),
    ("Coração", "Cœur"),
    ("sua mão", "votre main"),
    ("sua mão", "votre main"),
    ("sua mão", "votre main"),
    ("da sua mão", "de votre main"),
    ("para a sua mão", "dans votre main"),
    ("adicione", "ajoutez"),
    ("Adicione", "Ajoutez"),
    ("compre", "piochez"),
    ("Compre", "Piochez"),
    ("revele", "révélez"),
    ("Revele", "Révélez"),
    ("embaralhe", "mélangez"),
    ("Embaralhe", "Mélangez"),
    ("escolha", "choisissez"),
    ("Escolha", "Choisissez"),
    ("você pode", "vous pouvez"),
    ("Você pode", "Vous pouvez"),
    ("seu Palco", "votre Scène"),
    ("Palco", "Scène"),
    ("Membro", "Membre"),
    ("Membros", "Membres"),
    ("Energia", "Énergie"),
    ("oponente", "adversaire"),
    ("adversário", "adversaire"),
    ("deck", "deck"),
    ("carta", "carte"),
    ("cartas", "cartes"),
    ("custo", "coût"),
    ("reduzido", "réduit"),
    ("aumenta", "augmente"),
    ("diminui", "diminue"),
    ("colocar", "placer"),
    ("coloque", "placez"),
    ("Coloque", "Placez"),
    ("envie", "envoyez"),
    ("Envie", "Envoyez"),
    ("enviada", "envoyée"),
    ("enviado", "envoyé"),
    ("virada", "retournée"),
    ("virado", "retourné"),
    ("Descanso", "Repos"),
    ("Em pé", "Debout"),
    ("pontuação", "score"),
    ("Pontuação", "Score"),
    ("se fizer isso", "si vous le faites"),
    ("Se fizer isso", "Si vous le faites"),
    ("até", "jusqu'à"),
    ("qualquer", "n'importe quel"),
    ("outra", "autre"),
    ("outro", "autre"),
    ("outras", "autres"),
    ("outros", "autres"),
    ("esta carta", "cette carte"),
    ("Esta carta", "Cette carte"),
    ("este Membro", "ce Membre"),
    ("Este Membro", "Ce Membre"),
    ("na sua mão", "dans votre main"),
    ("do seu deck", "de votre deck"),
    ("do topo", "du dessus"),
    ("topo do seu deck", "dessus de votre deck"),
    ("por turno", "par tour"),
    ("uma vez", "une fois"),
    ("duas vezes", "deux fois"),
    ("e/ou", "et/ou"),
    (" e ", " et "),
    (" ou ", " ou "),
    (" de ", " de "),
    (" para ", " pour "),
    (" com ", " avec "),
    (" sem ", " sans "),
    (" no ", " dans le "),
    (" na ", " dans la "),
    (" ao ", " au "),
    (" à ", " à "),
    ("da sua ", "de votre "),
    ("do seu ", "de votre "),
    ("das suas ", "de vos "),
    ("dos seus ", "de vos "),
    ("combinação", "combinaison"),
    ("Combinação", "Combinaison"),
    ("qualquer combinação", "n'importe quelle combinaison"),
    ("n'importe quel combinaison", "n'importe quelle combinaison"),
    (" até o ", " jusqu'à "),
    ("até o ", "jusqu'à "),
    (" até ", " jusqu'à "),
    (" se ", " si "),
    ("quando ", "lorsque "),
    ("Quando ", "Lorsque "),
    ("enquanto ", "pendant que "),
    ("durante ", "pendant "),
    ("Durante ", "Pendant "),
    ("também ", "aussi "),
    ("Também ", "Aussi "),
    ("apenas ", "seulement "),
    ("Apenas ", "Seulement "),
    ("todos ", "tous "),
    ("todas ", "toutes "),
    ("cada ", "chaque "),
    ("Cada ", "Chaque "),
    ("entre ", "parmi "),
    ("então ", "alors "),
    ("depois ", "ensuite "),
    ("antes ", "avant "),
    ("mais ", "plus "),
    ("menos ", "moins "),
    ("igual ", "égal "),
    ("diferente ", "différent "),
    ("nomeadas ", "nommées "),
    ("nomeados ", "nommés "),
    ("chamadas ", "nommées "),
    ("chamados ", "nommés "),
    ("jogar ", "jouer "),
    ("jogue ", "jouez "),
    ("Jogue ", "Jouez "),
    ("ativar ", "activer "),
    ("ative ", "activez "),
    ("Ative ", "Activez "),
    ("olhe ", "regardez "),
    ("Olhe ", "Regardez "),
    ("verifique ", "vérifiez "),
    ("Verifique ", "Vérifiez "),
    ("descarte ", "défaussez "),
    ("Descarte ", "Défaussez "),
    ("descarte", "défausse"),
    ("mão", "main"),
    ("sua main", "votre main"),
    ("pour chaque other", "pour chaque autre"),
    ("other carte", "autre carte"),
    ("other card", "autre carte"),
    ("in your main", "dans votre main"),
    ("The cost of", "Le coût de"),
    ("This Membre", "Ce Membre"),
    ("this Membre", "ce Membre"),
    ("cannot", "ne peut pas"),
    ("can ", "peut "),
    ("is reduced by", "est réduit de"),
    ("for each", "pour chaque"),
    ("Ask your adversaire", "Demandez à votre adversaire"),
    ("Ask your opponent", "Demandez à votre adversaire"),
    ("If they answer", "S'ils répondent"),
    ("If you", "Si vous"),
    ("from your", "depuis votre"),
    ("to your", "vers votre"),
    ("of your", "de votre"),
    ("in your", "dans votre"),
    ("on your", "sur votre"),
]


def convert_pt_to_fr(text: str) -> str:
    held: list[str] = []

    def hold(m: re.Match[str]) -> str:
        held.append(m.group(0))
        return f"⟦{len(held)-1}⟧"

    work = re.sub(r'"[^"]+"', hold, text or "")
    for a, b in sorted(PT_BR_TO_FR.items(), key=lambda p: len(p[0]), reverse=True):
        work = work.replace(a, b)
    for a, b in sorted(PHRASES, key=lambda p: len(p[0]), reverse=True):
        work = work.replace(a, b)

    def restore(m: re.Match[str]) -> str:
        i = int(m.group(1))
        return held[i] if 0 <= i < len(held) else m.group(0)

    return re.sub(r"⟦(\d+)⟧", restore, work)


def main() -> int:
    data = json.loads(CARDS.read_text(encoding="utf-8"))
    n = 0
    for card in data.get("cards") or []:
        pt = (card.get("text_pt") or "").strip()
        en = (card.get("text") or "").strip()
        if not en and not pt:
            continue
        src = pt or en
        # If using EN, apply EN brackets via translate_text_fr_batch helpers
        if pt:
            fr = convert_pt_to_fr(src)
        else:
            from translate_text_fr_batch import translate_fr_glossary

            fr = translate_fr_glossary(src)
        card["text_fr"] = fr
        n += 1
    CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Rebuilt text_fr for {n} cards")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
