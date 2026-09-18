#!/usr/bin/env python3
"""Fill locales/fr.json missing keys from en_extracted (FR translations for drift keys)."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EN = ROOT / "locales" / "en_extracted.json"
FR = ROOT / "locales" / "fr.json"

# Hand translations for known recent EN-only keys (extend as needed).
EXACT: dict[str, str] = {
    "lobby.inviteFriend": "Inviter un ami",
    "lobby.inviteFriendHint": "Partagez le code de la salle pour qu’un ami rejoigne.",
    "booster.boxFromPacks": "Boîte depuis les packs",
    "chat.roomFriends": "Amis",
    "chat.sendFailed": "Échec de l’envoi du message.",
    "chat.reportFailed": "Échec du signalement.",
    "friendPush.testReceived": "Invitation de test reçue.",
    "spectate.pipUnavailable": "Aperçu image-dans-l’image indisponible.",
    "tournament.err.replayHelperMissing": "Assistant de replay introuvable.",
}


def leaves(node, prefix=""):
    out = {}
    if isinstance(node, dict):
        for k, v in node.items():
            path = f"{prefix}.{k}" if prefix else k
            if isinstance(v, dict):
                out.update(leaves(v, path))
            else:
                out[path] = v
    return out


def set_path(root: dict, dotted: str, value: str) -> None:
    parts = dotted.split(".")
    cur = root
    for p in parts[:-1]:
        cur = cur.setdefault(p, {})
        if not isinstance(cur, dict):
            return
    cur[parts[-1]] = value


def rough_fr(en: str) -> str:
    """Lightweight EN→FR for leftover UI (glossary + keep placeholders)."""
    if en in EXACT.values():
        return en
    # Prefer exact map when caller passes key separately
    reps = [
        ("Waiting Room", "Salle d'attente"),
        ("Main Phase", "Phase principale"),
        ("Live Phase", "Phase Live"),
        ("Baton Touch", "Baton Touch"),
        ("Required Hearts", "Cœurs requis"),
        ("Success", "Réussite"),
        ("Energy", "Énergie"),
        ("Stage", "Scène"),
        ("Member", "Membre"),
        ("Hearts", "Cœurs"),
        ("Heart", "Cœur"),
        ("Cancel", "Annuler"),
        ("Confirm", "Confirmer"),
        ("Close", "Fermer"),
        ("Back", "Retour"),
        ("Save", "Enregistrer"),
        ("Delete", "Supprimer"),
        ("Loading", "Chargement"),
        ("Error", "Erreur"),
        ("Retry", "Réessayer"),
        ("Ranked", "Classé"),
        ("Casual", "Occasionnel"),
        ("Tournament", "Tournoi"),
        ("Winner", "Vainqueur"),
        ("prize", "prix"),
        ("Coins", "Pièces"),
        ("Deck", "Deck"),
        ("Friends", "Amis"),
        ("Spectate", "Spectateur"),
    ]
    out = en
    for a, b in reps:
        out = out.replace(a, b)
    return out


def main() -> int:
    en = json.loads(EN.read_text(encoding="utf-8"))
    fr = json.loads(FR.read_text(encoding="utf-8"))
    en_leaves = leaves(en)
    fr_leaves = leaves(fr)
    added = 0
    for key, en_val in en_leaves.items():
        if key in fr_leaves:
            continue
        if key in EXACT:
            val = EXACT[key]
        else:
            val = rough_fr(str(en_val))
        set_path(fr, key, val)
        added += 1
    # Ensure language.fr
    fr.setdefault("language", {})["fr"] = "Français"
    FR.write_text(json.dumps(fr, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Added {added} missing keys to fr.json")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
