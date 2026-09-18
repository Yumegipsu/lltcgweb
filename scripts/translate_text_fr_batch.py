#!/usr/bin/env python3
"""
Populate cards.json text_fr for all rules-bearing cards (French).

Adapted from translate_text_es_batch.py. Localizes skill brackets to FR,
translates bodies via glossary + exact maps built from ES English keys.
Keeps Blade, Mulligan, Live, Yell, Baton Touch, and quoted character/song
names in English.

Usage:
  python scripts/translate_text_fr_batch.py --all
  python scripts/translate_text_fr_batch.py --batch 5a
  python scripts/translate_text_fr_batch.py --all --dry-run
  python scripts/translate_text_fr_batch.py --all --force
  python scripts/translate_text_fr_batch.py --repair-leaks
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CARDS_JSON = ROOT / "cards.json"
LOCALES_DIR = ROOT / "locales"

sys.path.insert(0, str(ROOT / "scripts"))
from translate_text_es_batch import (  # noqa: E402
    BATCH_MATCHERS,
    EXACT_TRANSLATIONS as ES_EXACT_KEYS,
    card_batch_id,
    export_batch_texts,
)

# English skill-type brackets → FR (match locales/fr.json skillKw.*.title).
BRACKET_EN_TO_FR: dict[str, str] = {
    "[On Enter]": "[À l'entrée]",
    "[On Leave]": "[À la sortie]",
    "[Live Start]": "[Début de Live]",
    "[Live Success]": "[Live réussi]",
    "[Activated]": "[Activé]",
    "[Always]": "[Permanent]",
    "[Continuous]": "[Permanent]",
    "[Automatic]": "[Automatique]",
    "[Auto]": "[Automatique]",
    "[Once per Turn]": "[Une fois par tour]",
    "[Once per turn]": "[Une fois par tour]",
    "[Twice per Turn]": "[Deux fois par tour]",
    "[Twice per turn]": "[Deux fois par tour]",
    "[Center]": "[Centre]",
    "[Yell]": "[Yell]",
    "[Left Side]": "[Côté gauche]",
    "[Right Side]": "[Côté droit]",
    "[On Play]": "[Permanent]",
}

EN_SKILL_BRACKET_RE = re.compile(
    r"\[(?:On Enter|On Leave|Live Start|Live Success|Activated|Always|Continuous|"
    r"Once per [Tt]urn|Twice per [Tt]urn|Automatic|Auto|Center|Yell|On Play|Left Side|Right Side)\]"
)

_BRACKET_PROTECT_RE = re.compile(
    r"\[(?:On Enter|Live Start|Activated|Always|Automatic|Once per [Tt]urn|Twice per [Tt]urn|"
    r"Center|Yell|On Leave|Live Success|Left Side|Right Side|Continuous|Auto|On Play)\]"
)

# Longest-first glossary (EN → FR). Keys mirror translate_text_es_batch.GLOSSARY.
GLOSSARY: list[tuple[str, str]] = [
    ("Success Live area", "zone de Live réussi"),
    ("Live Card Zone", "Zone de cartes Live"),
    ("Live zone", "zone de Live"),
    ("Left Side area", "zone Côté gauche"),
    ("Right Side area", "zone Côté droit"),
    ("Left Side", "Côté gauche"),
    ("Right Side", "Côté droit"),
    ("this card's score", "le score de cette carte"),
    ("this card's Required Hearts", "les Cœurs requis de cette carte"),
    ("Required Hearts become", "les Cœurs requis deviennent"),
    ("Required Hearts", "Cœurs requis"),
    ("total Live Score", "score Live total"),
    ("Live total score", "score Live total"),
    ("Live Score", "score Live"),
    ("All Hearts", "tous les Cœurs"),
    ("2 All Hearts", "2 de tous les Cœurs"),
    ("revealed by Yell", "révélée par Yell"),
    ("revealed for Yell", "révélée pour Yell"),
    ("combined cost", "coût combiné"),
    ("discarded Member", "Membre défaussé"),
    ("lower cost than", "coût inférieur à"),
    ("entered your Stage this turn", "est entré sur votre Scène ce tour"),
    ("differently named", "aux noms distincts"),
    ("Cannot attempt a Live", "Impossible de tenter un Live"),
    ("While in Center", "Tant qu'il est en Centre"),
    ("distinct heart color", "couleur de cœur distincte"),
    ("into the area this Member was in", "dans la zone où se trouvait ce Membre"),
    ("cost 15 or less", "coût 15 ou moins"),
    ("cost 4 or less", "coût 4 ou moins"),
    ("cost 2 or less", "coût 2 ou moins"),
    ("score +", "score +"),
    ("your opponent's", "de votre adversaire"),
    ("opponent's", "de l'adversaire"),
    ("from your Stage", "de votre Scène"),
    ("from your Waiting Room", "de votre Salle d'attente"),
    ("into your Waiting Room", "dans votre Salle d'attente"),
    ("on top of your deck", "au-dessus de votre deck"),
    ("on the bottom of your deck", "en dessous de votre deck"),
    ("top of your deck", "dessus de votre deck"),
    ("top 5 cards of your deck", "5 cartes du dessus de votre deck"),
    ("top 3 cards of your deck", "3 cartes du dessus de votre deck"),
    ("top 2 cards of your deck", "2 cartes du dessus de votre deck"),
    ("card from your Energy deck", "carte de votre deck d'Énergie"),
    ("If your total Live Score", "Si votre score Live total"),
    ("If your", "Si votre"),
    ("card from your", "carte de votre"),
    ("any number of", "n'importe quel nombre de"),
    ("any combination of", "n'importe quelle combinaison de"),
    ("cards named", "cartes nommées"),
    ("and/or", "et/ou"),
    ("this card may be included", "cette carte peut être incluse"),
    (
        "(You may include this card when discarding for this effect.)",
        "(Vous pouvez inclure cette carte en défaussant pour cet effet.)",
    ),
    (
        "You may put 1 card from your hand into the Waiting Room",
        "Vous pouvez placer 1 carte de votre main dans la Salle d'attente",
    ),
    (
        "add 1 Nijigasaki Live card from your Waiting Room to your hand",
        "ajoutez 1 carte Live Nijigasaki de votre Salle d'attente à votre main",
    ),
    (
        "You may pay 1 Energy: choose a heart color. Until this Live ends, you gain 1 heart of that color.",
        "Vous pouvez payer 1 Énergie : choisissez une couleur de cœur. Jusqu'à la fin de ce Live, vous gagnez 1 cœur de cette couleur.",
    ),
    (
        "Until this Live ends, you gain 1 heart of that color.",
        "Jusqu'à la fin de ce Live, vous gagnez 1 cœur de cette couleur.",
    ),
    ("you gain 1 heart of that color", "vous gagnez 1 cœur de cette couleur"),
    (
        "put 1 card from your hand into the Waiting Room",
        "placez 1 carte de votre main dans la Salle d'attente",
    ),
    (
        "Draw 2 cards and put 1 card from your hand into the Waiting Room",
        "Piochez 2 cartes et placez 1 carte de votre main dans la Salle d'attente",
    ),
    (
        "Draw 1 card and put 1 card from your hand into the Waiting Room",
        "Piochez 1 carte et placez 1 carte de votre main dans la Salle d'attente",
    ),
    (
        "If every area on your Stage has a Hasunosora Member and all of their names are different",
        "Si chaque zone de votre Scène a un Membre Hasunosora et que tous leurs noms sont différents",
    ),
    (
        "If you have 3 or more cards in your Live Card Zone including 1 or more Nijigasaki Live cards",
        "Si vous avez 3 cartes ou plus dans votre Zone de cartes Live, dont 1 carte Live Nijigasaki ou plus",
    ),
    ("If you have", "Si vous avez"),
    ("for each Live card in your Live zone", "pour chaque carte Live dans votre zone de Live"),
    ("for each", "pour chaque"),
    (
        "You may put up to 3 cards from your hand into the Waiting Room",
        "Vous pouvez placer jusqu'à 3 cartes de votre main dans la Salle d'attente",
    ),
    (
        "draw a number of cards equal to the number you discarded this way",
        "piochez un nombre de cartes égal au nombre que vous avez défaussé de cette façon",
    ),
    ("if you have another Member on your Stage", "si vous avez un autre Membre sur votre Scène"),
    ("gain 1 of that heart", "gagnez 1 de ce cœur"),
    (
        "Put the top 3 cards of your deck into the Waiting Room. If all of them are Member cards, draw 1 card.",
        "Placez les 3 cartes du dessus de votre deck dans la Salle d'attente. Si ce sont toutes des cartes de Membre, piochez 1 carte.",
    ),
    (
        "look at the top 5 cards of your deck. You may reveal 1 Mira-Cra Park! card and add it to your hand. Put the rest into the Waiting Room.",
        "regardez les 5 cartes du dessus de votre deck. Vous pouvez révéler 1 carte Mira-Cra Park! et l'ajouter à votre main. Placez le reste dans la Salle d'attente.",
    ),
    (
        "look at the top 5 cards of your deck. You may reveal 1 Live card and add it to your hand. Put the rest into the Waiting Room.",
        "regardez les 5 cartes du dessus de votre deck. Vous pouvez révéler 1 carte Live et l'ajouter à votre main. Placez le reste dans la Salle d'attente.",
    ),
    (
        "look at the top 5 cards of your deck. You may reveal up to 1 Liella! card and add it to your hand. Put the rest into the Waiting Room.",
        "regardez les 5 cartes du dessus de votre deck. Vous pouvez révéler jusqu'à 1 carte Liella! et l'ajouter à votre main. Placez le reste dans la Salle d'attente.",
    ),
    (
        "look at the top 5 cards of your deck. You may reveal 1 Liella! card and add it to your hand. Put the rest into the Waiting Room.",
        "regardez les 5 cartes du dessus de votre deck. Vous pouvez révéler 1 carte Liella! et l'ajouter à votre main. Placez le reste dans la Salle d'attente.",
    ),
    (
        "If 10 or more Hasunosora Member cards were revealed by Yell",
        "Si 10 cartes de Membre Hasunosora ou plus ont été révélées par Yell",
    ),
    (
        "If your total Live Score is higher than your opponent's and you have a Hasunosora Member on your Stage",
        "Si votre score Live total est supérieur à celui de votre adversaire et que vous avez un Membre Hasunosora sur votre Scène",
    ),
    (
        "If your Live total score is higher than your opponent's",
        "Si votre score Live total est supérieur à celui de votre adversaire",
    ),
    ("is higher than your opponent's", "est supérieur à celui de votre adversaire"),
    (
        "If you have another Nijigasaki Member on your Stage",
        "Si vous avez un autre Membre Nijigasaki sur votre Scène",
    ),
    ("with lower cost than the discarded Member", "avec un coût inférieur au Membre défaussé"),
    (
        "put the top 2 cards of your deck into the Waiting Room. Then add 1 Member card from your Waiting Room to your hand.",
        "placez les 2 cartes du dessus de votre deck dans la Salle d'attente. Puis ajoutez 1 carte de Membre de votre Salle d'attente à votre main.",
    ),
    ("If you have 11 or more Energy", "Si vous avez 11 Énergie ou plus"),
    ("If you have 12 or more Energy", "Si vous avez 12 Énergie ou plus"),
    ('If you have "Mei Yoneme" on your Stage', 'Si vous avez "Mei Yoneme" sur votre Scène'),
    ("and add it to your hand", "et l'ajouter à votre main"),
    ("Put the rest into the Waiting Room.", "Placez le reste dans la Salle d'attente."),
    ("Put the rest into the Waiting Room", "Placez le reste dans la Salle d'attente"),
    (
        "Put this Member from your Stage into the Waiting Room",
        "Placez ce Membre de votre Scène dans la Salle d'attente",
    ),
    ("this Member", "ce Membre"),
    ("This Member", "Ce Membre"),
    ("that Member", "ce Membre"),
    ("a Member", "un Membre"),
    ("in your hand", "dans votre main"),
    ("in their hand", "dans leur main"),
    ("from their hand", "de leur main"),
    ("1 card", "1 carte"),
    ("2 cards", "2 cartes"),
    ("3 cards", "3 cartes"),
    ("card discarded", "carte défaussée"),
    ("card ", "carte "),
    (" cards", " cartes"),
    ("Your Live total score is increased by 3.", "Votre score Live total est augmenté de 3."),
    ("Your Live total score is increased by", "Votre score Live total est augmenté de"),
    (
        "if a Nijigasaki Member entered your Stage this turn",
        "si un Membre Nijigasaki est entré sur votre Scène ce tour",
    ),
    (
        "draw 1 card, then put 1 card from your hand into the Waiting Room",
        "piochez 1 carte, puis placez 1 carte de votre main dans la Salle d'attente",
    ),
    (", then put ", ", puis placez "),
    (", then draw ", ", puis piochez "),
    (
        "Whenever another Cerise Bouquet Member enters your Stage",
        "Chaque fois qu'un autre Membre Cerise Bouquet entre sur votre Scène",
    ),
    (
        "Whenever a Hasunosora Member enters your Stage",
        "Chaque fois qu'un Membre Hasunosora entre sur votre Scène",
    ),
    (
        "Whenever 1 or more cards are put from your hand into the Waiting Room",
        "Chaque fois qu'1 carte ou plus est placée de votre main dans la Salle d'attente",
    ),
    (
        "When this Member or another Member enters your Stage via Baton Touch",
        "Lorsque ce Membre ou un autre Membre entre sur votre Scène via Baton Touch",
    ),
    (
        "Put any number of Mira-Cra Park! Member cards from your hand into the Waiting Room, then draw that many +1.",
        "Placez n'importe quel nombre de cartes de Membre Mira-Cra Park! de votre main dans la Salle d'attente, puis piochez ce nombre +1.",
    ),
    (
        "If this Member has 8 or more Blades, draw 2 cards and put 1 card from your hand into the Waiting Room.",
        "Si ce Membre a 8 Blade ou plus, piochez 2 cartes et placez 1 carte de votre main dans la Salle d'attente.",
    ),
    ("you gain 1 green ♡ and +1 Blade", "vous gagnez 1 ♡ vert et +1 Blade"),
    ("if this Member entered the Left Side area", "si ce Membre est entré dans la zone Côté gauche"),
    ("if their combined cost is", "si leur coût combiné est"),
    (
        "Draw 2 cards and put 2 cards from your hand into the Waiting Room",
        "Piochez 2 cartes et placez 2 cartes de votre main dans la Salle d'attente",
    ),
    ("bladeless Member cards", "cartes de Membre sans Blade"),
    ("activate this Member", "activez ce Membre"),
    ("If this Member moved this turn", "Si ce Membre s'est déplacé ce tour"),
    (
        "This ability applies only while this Member is in the Left Side area.",
        "Cette capacité s'applique uniquement tant que ce Membre est dans la zone Côté gauche.",
    ),
    (
        "This ability applies only while this Member is in the Right Side area.",
        "Cette capacité s'applique uniquement tant que ce Membre est dans la zone Côté droit.",
    ),
    (
        "This ability triggers only if this Member entered the Left Side or Right Side area.",
        "Cette capacité ne se déclenche que si ce Membre est entré dans la zone Côté gauche ou Côté droit.",
    ),
    ("Put this Member into Wait", "Placez ce Membre en Wait"),
    ("Gain 1 Red heart.", "Gagnez 1 cœur rouge."),
    ("Gain 1 Blue heart.", "Gagnez 1 cœur bleu."),
    ("Gain 3 Red hearts.", "Gagnez 3 cœurs rouges."),
    ("Gain 3 Yellow hearts.", "Gagnez 3 cœurs jaunes."),
    ("Gain 3 Blue hearts.", "Gagnez 3 cœurs bleus."),
    ("1 card from your hand", "1 carte de votre main"),
    ("from your hand into the Waiting Room", "de votre main dans la Salle d'attente"),
    ("You may pay 2 Energy", "Vous pouvez payer 2 Énergie"),
    ("You may pay 1 Energy", "Vous pouvez payer 1 Énergie"),
    ("You may put 1 card from your hand", "Vous pouvez placer 1 carte de votre main"),
    ("You may put any combination of", "Vous pouvez placer n'importe quelle combinaison de"),
    ("You may put up to", "Vous pouvez placer jusqu'à"),
    ("You may put", "Vous pouvez placer"),
    ("you may put", "vous pouvez placer"),
    ("You may draw", "Vous pouvez piocher"),
    ("you may draw", "vous pouvez piocher"),
    ("You may pay", "Vous pouvez payer"),
    ("you may pay", "vous pouvez payer"),
    ("You may reveal", "Vous pouvez révéler"),
    ("you may reveal", "vous pouvez révéler"),
    ("You may add", "Vous pouvez ajouter"),
    ("you may add", "vous pouvez ajouter"),
    ("You may look at", "Vous pouvez regarder"),
    ("you may look at", "vous pouvez regarder"),
    ("You may position-change", "Vous pouvez changer la position de"),
    ("You may formation-change", "Vous pouvez changer la formation de"),
    ("Until this Live ends", "Jusqu'à la fin de ce Live"),
    ("choose a heart color.", "choisissez une couleur de cœur."),
    ("choose a heart color —", "choisissez une couleur de cœur —"),
    ("choose a heart color", "choisissez une couleur de cœur"),
    ("choose a color", "choisissez une couleur"),
    ("You may", "Vous pouvez"),
    ("you may", "vous pouvez"),
    ("you gain", "vous gagnez"),
    ("You gain", "Vous gagnez"),
    ("heart color", "couleur de cœur"),
    ("activate 1 Energy", "activez 1 Énergie"),
    ("Activate 1 Energy", "Activez 1 Énergie"),
    ("activate 2 Energy", "activez 2 Énergie"),
    ("Activate 2 Energy", "Activez 2 Énergie"),
    ("draw 1 more card", "piochez 1 carte de plus"),
    ("draw 2 cards", "piochez 2 cartes"),
    ("draw 1 card", "piochez 1 carte"),
    ("Draw 1 card", "Piochez 1 carte"),
    ("Draw 2 cards", "Piochez 2 cartes"),
    ("Draw 3 cards", "Piochez 3 cartes"),
    ("Nijigasaki Live card", "carte Live Nijigasaki"),
    ("Nijigasaki Member", "Membre Nijigasaki"),
    ("Nijigasaki card", "carte Nijigasaki"),
    ("Nijigasaki cards", "cartes Nijigasaki"),
    ("Hasunosora Live card", "carte Live Hasunosora"),
    ("Hasunosora Member card", "carte de Membre Hasunosora"),
    ("Hasunosora Member", "Membre Hasunosora"),
    ("Hasunosora card", "carte Hasunosora"),
    ("Hasunosora cards", "cartes Hasunosora"),
    ("Liella! card", "carte Liella!"),
    ("Liella! Member", "Membre Liella!"),
    ("Liella! Members", "Membres Liella!"),
    ("Mira-Cra Park! card", "carte Mira-Cra Park!"),
    ("Aqours Live card", "carte Live Aqours"),
    ("Aqours Member", "Membre Aqours"),
    ("Aqours card", "carte Aqours"),
    ("μ's Live card", "carte Live μ's"),
    ("μ's Member", "Membre μ's"),
    ("μ's card", "carte μ's"),
    ("Live card", "carte Live"),
    ("Live cards", "cartes Live"),
    ("Member card", "carte de Membre"),
    ("Member cards", "cartes de Membre"),
    ("Members", "Membres"),
    ("Member", "Membre"),
    ("Success Live cards", "cartes de Live réussi"),
    ("required Gray Hearts", "Cœurs gris requis"),
    ("required hearts", "cœurs requis"),
    ("Waiting Room", "Salle d'attente"),
    ("Energy deck", "deck d'Énergie"),
    ("Energy Zone", "Zone d'Énergie"),
    ("Center area", "zone Centre"),
    ("Main Phase", "Phase principale"),
    ("Live Phase", "Phase Live"),
    ("Stage area", "zone de Scène"),
    ("Stage", "Scène"),
    ("Performance", "Performance"),
    ("Energy", "Énergie"),
    ("Hearts", "Cœurs"),
    ("Heart", "Cœur"),
    ("deck", "deck"),
    ("Blade", "Blade"),
    ("Yell", "Yell"),
    ("Baton Touch", "Baton Touch"),
    ("Mulligan", "Mulligan"),
    ("position-change", "changement de position"),
    ("formation-change", "changement de formation"),
    # Conjugations before stems (avoid draws→piochezs, gains→gagnezs, puts→placezs).
    ("draws", "pioche"),
    ("Draw", "Piochez"),
    ("draw", "piochez"),
    ("adds", "ajoute"),
    ("Add", "Ajoutez"),
    ("add", "ajoutez"),
    ("puts", "place"),
    ("Put", "Placez"),
    ("put", "placez"),
    # Do not map bare "place"/"places" — that mutates French "placer" → placezr/placerr.
    ("Look at", "Regardez"),
    ("look at", "regardez"),
    ("reveals", "révèle"),
    ("Reveal", "Révélez"),
    ("reveal", "révélez"),
    ("activates", "active"),
    ("Activate", "Activez"),
    ("activate", "activez"),
    ("pays", "paye"),
    ("Pay", "Payez"),
    ("pay", "payez"),
    ("gains", "gagne"),
    ("Gain", "Gagnez"),
    ("gain", "gagnez"),
    ("chooses", "choisit"),
    ("Choose", "Choisissez"),
    ("choose", "choisissez"),
    ("sends", "envoie"),
    ("Send", "Envoyez"),
    ("send", "envoyez"),
    ("shuffles", "mélange"),
    ("Shuffle", "Mélangez"),
    ("shuffle", "mélangez"),
    ("discarding for this effect.", "en défaussant pour cet effet."),
    ("discarding for this effect", "en défaussant pour cet effet"),
    ("when discarding", "en défaussant"),
    ("discarding", "en défaussant"),
    ("discarded this way", "défaussé de cette façon"),
    ("discarded", "défaussé"),
    ("Discard", "Défaussez"),
    ("discard", "défaussez"),
    ("include this card from your hand", "inclure cette carte de votre main"),
    ("include this card", "inclure cette carte"),
    ("include", "inclure"),
    ("this card gains", "cette carte gagne"),
    ("this card", "cette carte"),
    ("cannot be sent", "ne peut pas être envoyé"),
    ("is reduced by", "est réduit de"),
    ("The cost of", "Le coût de"),
    ("other card", "autre carte"),
    ("each player", "chaque joueur"),
    ("Ask your opponent", "Demandez à votre adversaire"),
    ("If they answer", "S'ils répondent"),
    ("Otherwise:", "Sinon :"),
    ("every Member", "chaque Membre"),
    ("on both players' Stages", "sur les Scènes des deux joueurs"),
    ("both players'", "des deux joueurs"),
    ("until this Live ends", "jusqu'à la fin de ce Live"),
    ("from your hand", "de votre main"),
    ("into the Waiting Room", "dans la Salle d'attente"),
    ("to the Waiting Room", "dans la Salle d'attente"),
    ("from your Waiting Room", "de votre Salle d'attente"),
    ("from your deck", "de votre deck"),
    ("of your deck", "de votre deck"),
    ("your deck", "votre deck"),
    ("to your hand", "à votre main"),
    ("your hand", "votre main"),
    ("on your Stage", "sur votre Scène"),
    ("your Stage", "votre Scène"),
    ("from the Stage", "de la Scène"),
    ("the Stage", "la Scène"),
    ("into Wait", "en Wait"),
    ("in Wait", "en Wait"),
    ("this turn", "ce tour"),
    ("Once per turn", "Une fois par tour"),
    ("Once per Turn", "Une fois par tour"),
    ("Rest", "Repos"),
    ("Stand", "Debout"),
    ("hand", "main"),
    ("Success", "Réussite"),
    ("in any order", "dans n'importe quel ordre"),
    ("or less", "ou moins"),
    ("or more", "ou plus"),
    ("up to", "jusqu'à"),
    ("including", "y compris"),
    ("Then", "Puis"),
    ("then", "puis"),
]


def localize_brackets_to_fr(text: str) -> str:
    out = text
    for en, fr in sorted(BRACKET_EN_TO_FR.items(), key=lambda pair: len(pair[0]), reverse=True):
        out = out.replace(en, fr)
    return out


def text_fr_has_english_skill_brackets(text_fr: str) -> bool:
    return bool(EN_SKILL_BRACKET_RE.search(text_fr or ""))


def protect_brackets(text: str) -> tuple[str, list[str]]:
    tokens: list[str] = []

    def repl(match: re.Match[str]) -> str:
        tokens.append(match.group(0))
        return f"\x00BR{len(tokens) - 1}\x00"

    return _BRACKET_PROTECT_RE.sub(repl, text), tokens


def restore_brackets(text: str, tokens: list[str]) -> str:
    out = text
    for i, tok in enumerate(tokens):
        out = out.replace(f"\x00BR{i}\x00", tok)
    return out.replace("[Once per Turn]", "[Once per turn]")


_QUOTE_RE = re.compile(r'"([^"]*)"')


def protect_quotes(text: str) -> tuple[str, list[str]]:
    tokens: list[str] = []

    def repl(match: re.Match[str]) -> str:
        tokens.append(match.group(0))
        return f"\x00QT{len(tokens) - 1}\x00"

    return _QUOTE_RE.sub(repl, text), tokens


def restore_quotes(text: str, tokens: list[str]) -> str:
    out = text
    for i, tok in enumerate(tokens):
        out = out.replace(f"\x00QT{i}\x00", tok)
    return out


def translate_fr_glossary(text: str) -> str:
    """Apply French glossary to English rules text (brackets + quotes protected)."""
    protected, bracket_tokens = protect_brackets(text)
    protected, quote_tokens = protect_quotes(protected)
    for en, fr in sorted(GLOSSARY, key=lambda pair: len(pair[0]), reverse=True):
        protected = protected.replace(en, fr)
    protected = restore_quotes(protected, quote_tokens)
    return restore_brackets(protected, bracket_tokens)


TEXT_FR_LEAK_RE = re.compile(
    r"\b(?:You may|you may|from your|to your|Until this|choose a|Waiting Room|Required Hearts|"
    r"On Enter|Live Start|Activated|Once per turn|Draw \d|Pay \d|this Member|and put|and add)\b",
    re.I,
)


def text_fr_has_english_leaks(text_fr: str) -> bool:
    return bool(TEXT_FR_LEAK_RE.search(text_fr or "")) or text_fr_has_english_skill_brackets(text_fr)


def load_fr_exact_overrides() -> dict[str, str]:
    exact: dict[str, str] = {}
    path = LOCALES_DIR / "batch_fr_exact.json"
    if path.is_file():
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            exact.update({str(k): str(v) for k, v in data.items()})
    for path in sorted(LOCALES_DIR.glob("batch_*_fr_exact.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            exact.update({str(k): str(v) for k, v in data.items()})
    return exact


_ALL_FR_EXACT: dict[str, str] | None = None


def all_fr_exact() -> dict[str, str]:
    """JSON overrides + glossary render of ES exact English keys."""
    global _ALL_FR_EXACT
    if _ALL_FR_EXACT is None:
        built = load_fr_exact_overrides()
        for en_key in ES_EXACT_KEYS:
            if en_key not in built:
                built[en_key] = translate_fr_glossary(en_key)
        _ALL_FR_EXACT = built
    return _ALL_FR_EXACT


def cleanup_spacing(text: str) -> str:
    out = re.sub(r"[ \t]{2,}", " ", text)
    out = re.sub(r" +([.,:;!?)])", r"\1", out)
    out = re.sub(r"([(]) +", r"\1", out)
    out = re.sub(r"\n[ \t]+", "\n", out)
    # Fix common imperative-after-pouvez leaks from short-token glossary.
    out = out.replace("Vous pouvez placez", "Vous pouvez placer")
    out = out.replace("vous pouvez placez", "vous pouvez placer")
    out = out.replace("Vous pouvez placezr", "Vous pouvez placer")
    out = out.replace("vous pouvez placezr", "vous pouvez placer")
    out = out.replace("Vous pouvez placerr", "Vous pouvez placer")
    out = out.replace("vous pouvez placerr", "vous pouvez placer")
    out = out.replace("Vous pouvez piochez", "Vous pouvez piocher")
    out = out.replace("vous pouvez piochez", "vous pouvez piocher")
    out = out.replace("Vous pouvez révélez", "Vous pouvez révéler")
    out = out.replace("vous pouvez révélez", "vous pouvez révéler")
    out = out.replace("Vous pouvez ajoutez", "Vous pouvez ajouter")
    out = out.replace("vous pouvez ajoutez", "vous pouvez ajouter")
    out = out.replace("Vous pouvez activez", "Vous pouvez activer")
    out = out.replace("vous pouvez activez", "vous pouvez activer")
    out = out.replace("Vous pouvez payez", "Vous pouvez payer")
    out = out.replace("vous pouvez payez", "vous pouvez payer")
    out = out.replace("Vous pouvez regardez", "Vous pouvez regarder")
    out = out.replace("vous pouvez regardez", "vous pouvez regarder")
    out = out.replace("when en défaussant", "en défaussant")
    out = out.replace("défaussezing", "en défaussant")
    out = out.replace("défaussezed", "défaussé")
    out = out.replace(
        '"[Permanent] Your Live total score is increased by 3."',
        '"[Permanent] Votre score Live total est augmenté de 3."',
    )
    out = out.replace(
        "Your Live total score is increased by 3.",
        "Votre score Live total est augmenté de 3.",
    )
    out = out.replace(
        "Your Live total score is increased by",
        "Votre score Live total est augmenté de",
    )
    return out.strip()


def translate_text(text: str, batch_id: str | None = None) -> str:
    key = text.strip()
    if not key:
        return ""
    raw: str | None = None
    if batch_id:
        path = LOCALES_DIR / f"batch_{batch_id}_fr_exact.json"
        if path.is_file():
            data = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(data, dict) and key in data:
                raw = str(data[key])
    if raw is None:
        exact = all_fr_exact()
        if key in exact:
            raw = exact[key]
    if raw is None:
        raw = translate_fr_glossary(key)
    return cleanup_spacing(localize_brackets_to_fr(raw))


def main() -> int:
    ap = argparse.ArgumentParser(description="Populate text_fr for card rule text.")
    ap.add_argument("--cards-json", type=Path, default=CARDS_JSON)
    ap.add_argument("--batch", default="5a", choices=sorted(BATCH_MATCHERS.keys()))
    ap.add_argument(
        "--all",
        action="store_true",
        help="Set text_fr for every card with nonempty English text",
    )
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--force", action="store_true", help="Overwrite existing text_fr")
    ap.add_argument(
        "--repair-leaks",
        action="store_true",
        help="Re-translate cards whose text_fr still has English leaks",
    )
    ap.add_argument(
        "--rebuild-all-fr",
        action="store_true",
        help="Alias of --all --force",
    )
    ap.add_argument("--export-texts", action="store_true")
    args = ap.parse_args()

    if args.rebuild_all_fr:
        args.all = True
        args.force = True

    matcher = BATCH_MATCHERS.get(args.batch)
    if not args.repair_leaks and not args.all and not matcher:
        print(f"Unsupported batch: {args.batch}", file=sys.stderr)
        return 1

    if not args.cards_json.is_file():
        print(f"Missing {args.cards_json}", file=sys.stderr)
        return 1

    data = json.loads(args.cards_json.read_text(encoding="utf-8"))
    cards = data.get("cards") or []

    if args.export_texts:
        export_batch_texts(cards, matcher, args.batch)
        return 0

    updated = skipped_has_fr = repaired = 0
    missing: list[tuple[str, str]] = []

    def should_update(card: dict) -> bool:
        src = (card.get("text") or "").strip()
        if not src:
            return False
        # --all: set text_fr for every card with nonempty English text
        if args.all:
            return True
        existing = (card.get("text_fr") or "").strip()
        if args.repair_leaks:
            return not existing or text_fr_has_english_leaks(existing)
        if existing and not args.force:
            return False
        return True

    targets = cards if (args.repair_leaks or args.all) else [c for c in cards if matcher(c)]

    for card in targets:
        if not args.repair_leaks and not args.all and not matcher(card):
            continue
        if not should_update(card):
            if (card.get("text_fr") or "").strip():
                skipped_has_fr += 1
            continue
        src = (card.get("text") or "").strip()
        batch_for_card = (
            args.batch if not (args.repair_leaks or args.all) else (card_batch_id(card) or args.batch)
        )
        fr = translate_text(src, batch_for_card)
        if not fr.strip():
            missing.append((card.get("card_no", ""), src[:80]))
            continue
        if fr == src and not re.search(r"[\u3040-\u30ff\u4e00-\u9fff]", src):
            missing.append((card.get("card_no", ""), src[:80]))
            continue
        if (card.get("text_fr") or "").strip():
            repaired += 1
        card["text_fr"] = fr
        updated += 1

    label = "All cards text_fr" if args.all else (
        "Repair leaks" if args.repair_leaks else f"Batch {args.batch}"
    )
    print(f"{label}: updated {updated} cards, rebuilt {repaired}, skipped {skipped_has_fr}")
    if missing:
        print(f"WARNING: {len(missing)} cards still untranslated:")
        for no, preview in missing[:10]:
            print(f"  {no}: {preview!r}...")

    has_text = sum(1 for c in cards if (c.get("text") or "").strip())
    has_fr = sum(1 for c in cards if (c.get("text_fr") or "").strip())
    print(f"Coverage: text_fr={has_fr} / English text={has_text}")

    if args.dry_run:
        print("Dry run — cards.json not written.")
        return 0 if not missing else 1

    args.cards_json.write_text(
        json.dumps(data, ensure_ascii=False, indent=4) + "\n",
        encoding="utf-8",
    )
    print(f"Wrote {args.cards_json}")
    return 0 if not missing else 1


if __name__ == "__main__":
    raise SystemExit(main())
