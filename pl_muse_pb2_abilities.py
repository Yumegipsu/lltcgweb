"""μ's Premium Booster DUO (PL!-pb2-*) ability IR + English skill text for import_from_db."""

from __future__ import annotations

_MUSE = "μ's"

# --- English translations (official bracket labels) ---
_PB2_TRANSLATIONS: dict[str, str] = {
    "PL!-pb2-000": (
        "[Always] You may Baton Touch with 2 Members when playing this card.\n"
        "[On Enter] If this card entered via Baton Touch from 2 μ's Members, add 1 μ's Live card "
        "from your Waiting Room to your hand. If those 2 Members' total cost is 15, until this Live ends, "
        "you gain \"[Always] +1 total Live score.\""
    ),
    "PL!-pb2-001": (
        "[Live Start] Among μ's cards in your Success Live area: if any have a Score icon, until this Live ends "
        "you gain \"[Always] +1 total Live score.\"; if any have an ALL Blade icon, until this Live ends you gain "
        "1 Wild heart; if any have a Draw icon, add 1 μ's card from your Waiting Room to your hand."
    ),
    "PL!-pb2-002": (
        "[Always] Members with 4 or fewer printed hearts that enter the Member area opposite this Member "
        "enter in Wait."
    ),
    "PL!-pb2-003": (
        "[Live Start] Negate all [Live Success] abilities of 1 opponent Stage Member until this Live ends. "
        "If you do, until this Live ends you gain 1 Yellow heart."
    ),
    "PL!-pb2-004": (
        "[Always] You gain +1 Blade for each μ's card with a Score icon in your Success Live area.\n"
        "[Automatic] [Once per turn] When you Yell, perform 1 additional Yell for each μ's card with a "
        "Score icon among cards revealed for that Yell."
    ),
    "PL!-pb2-005": (
        "[On Enter] If you have a μ's card with a Score icon in your Success Live area, until this Live ends "
        "you gain \"[Always] μ's Members on your Stage gain +1 Blade.\""
    ),
    "PL!-pb2-006": (
        "[Activated] [Once per turn] Put this Member into Wait and put 1 card from your hand into the Waiting Room: "
        "put 1 opponent Stage Member with 1 or fewer printed hearts into Wait.\n"
        "[Live Start] You may put this Member into Wait and put 1 card from your hand into the Waiting Room: "
        "put 1 opponent Stage Member with 1 or fewer printed hearts into Wait."
    ),
    "PL!-pb2-007": (
        "[Activated] Put this Member from the Stage into the Waiting Room: add 1 μ's Live card from your Waiting Room "
        "to your hand. Then activate 1 Energy for each μ's card in your Success Live area."
    ),
    "PL!-pb2-008": (
        "[On Enter] You may put this Member into Wait: look at the top 4 cards of your deck. You may reveal 1 μ's Live "
        "card among them whose total required hearts is 8 or more and add it to your hand. Put the rest into the Waiting Room."
    ),
    "PL!-pb2-009": (
        "[Automatic] When this Member is put from the Stage into the Waiting Room, if it Baton Touched with a μ's Member "
        "of cost 15 or more, activate 2 Energy."
    ),
    "PL!-pb2-010": (
        "[Live Start] Until this Live ends, you gain +1 Blade for each Member on your Stage that was put from Wait "
        "to Active this turn by one of your Printemps card effects."
    ),
    "PL!-pb2-011": (
        "[Always] You gain +1 Blade for each BiBi Member card stacked under this Member.\n"
        "[Automatic] Whenever an opponent Stage Member is put into Wait by one of your card effects, if this Member "
        "has 2 or fewer cards under it, put 1 BiBi Member card from your Waiting Room under this Member."
    ),
    "PL!-pb2-012": (
        "[Always] When you play this card, you may put 2 Printemps Members with different names on your Stage into Wait. "
        "If you do, this card costs 2 less.\n"
        "[Activated] [Once per turn] Put this Member into Wait: as an additional cost, put 2 cards from your hand into "
        "the Waiting Room OR put 2 Printemps Members into Wait. Add 1 Printemps Live card from your Waiting Room to your hand."
    ),
    "PL!-pb2-013": (
        "[On Enter] Reveal the top 4 cards of your deck. If they are all lily white cards, add 1 lily white Live card "
        "from among them to your hand and put the rest into the Waiting Room."
    ),
    "PL!-pb2-014": (
        "[On Enter] You may reveal 1 lily white Live card from your hand: add 1 card from your Success Live area to "
        "your hand. If you do, put the revealed card into your Success Live area."
    ),
    "PL!-pb2-015": (
        "[Automatic] [Once per turn] When an opponent Stage Member is put into Wait by one of your BiBi card effects, "
        "choose 1: Active 1 BiBi Member on your Stage; OR activate 2 Energy."
    ),
    "PL!-pb2-016": (
        "[Live Start] For each lily white card in your Success Live area, choose 1 (you may choose the same option "
        "more than once): until this Live ends your Center Member gains +1 Blade; OR Active 1 Member on your Stage; "
        "OR draw 1 card and put 1 card from your hand into the Waiting Room."
    ),
    "PL!-pb2-017": (
        "[On Enter] Put 4 Printemps Member cards from your Waiting Room under this Member.\n"
        "[Live Start] You may put up to 3 cards from under this Member into the Waiting Room: for each card put this way, "
        "Active or Wait 1 Printemps Member on your Stage."
    ),
    "PL!-pb2-018": (
        "[On Enter] You may Active up to 3 opponent Stage Members in Wait. If you do, draw 1 card for each Member "
        "Activated this way.\n"
        "[On Enter] / [Live Start] You may put 3 BiBi Member cards with different names from your hand into the Waiting Room: "
        "if every Member on your Stage is BiBi, put 1 opponent Stage Member into Wait."
    ),
    "PL!-pb2-019": (
        "[Live Start] You may put this Member into Wait and put 1 card from your hand into the Waiting Room: until this Live ends, "
        "the μ's Member in your Center area gains +2 Blade.\n"
        "(Blade on Members in Wait does not increase cards revealed for Yell.)"
    ),
    "PL!-pb2-020": (
        "[Always] While the total score of cards in your Success Live area is 9 or more, you gain 1 Pink heart and 1 Yellow heart."
    ),
    "PL!-pb2-021": (
        "[Automatic] [Once per turn] When this Member is put into Wait to pay a cost of one of your card abilities or by "
        "one of your card effects, Active this Member and until this Live ends it gains +1 Blade."
    ),
    "PL!-pb2-022": (
        "[Activated] Put this Member from the Stage into the Waiting Room: add 1 Member card from your Waiting Room to your hand."
    ),
    "PL!-pb2-023": (
        "[Always] While you have no cards in your Success Live area, you gain +1 Blade."
    ),
    "PL!-pb2-024": (
        "[Live Start] If every Member on your Stage is BiBi, put 1 opponent Stage Member of cost 2 or less into Wait."
    ),
    "PL!-pb2-025": (
        "[Always] You gain +1 Blade for each lily white card in your Success Live area."
    ),
    "PL!-pb2-026": (
        "[Activated] [Once per turn] Put this Member into Wait and put 1 card from your hand into the Waiting Room: "
        "look at the top 3 cards of your deck. You may reveal 1 Printemps Member among them and add it to your hand. "
        "Put the rest into the Waiting Room.\n"
        "(Blade on Members in Wait does not increase cards revealed for Yell.)"
    ),
    "PL!-pb2-027": (
        "[Activated] Put this Member from the Stage into the Waiting Room: add 1 Member card from your Waiting Room to your hand."
    ),
    "PL!-pb2-028": (
        "[Live Start] You may put this Member into Wait: until this Live ends, you gain 1 Yellow heart.\n"
        "(Blade on Members in Wait does not increase cards revealed for Yell.)"
    ),
    "PL!-pb2-029": (
        "[On Enter] / [Live Start] If every Member on your Stage is μ's, put 1 opponent Stage Member with 2 or fewer "
        "printed Blade into Wait.\n"
        "(Blade on Members in Wait does not increase cards revealed for Yell.)"
    ),
    "PL!-pb2-030": (
        "[Always] You gain +1 Blade for every 5 total score among cards in your Success Live area."
    ),
    "PL!-pb2-031": (
        "[Live Start] You may put 1 μ's card from your hand into the Waiting Room: until this Live ends, you gain 1 Purple heart."
    ),
    "PL!-pb2-032": (
        "[On Enter] Put 1 card from your hand into the Waiting Room: look at the top 5 cards of your deck. You may reveal "
        "1 μ's Member among them that has no Blade heart and add it to your hand. Put the rest into the Waiting Room."
    ),
    "PL!-pb2-033": (
        "[On Enter] / [Live Start] Put 1 opponent Stage Member with 3 or fewer printed hearts into Wait.\n"
        "(Blade on Members in Wait does not increase cards revealed for Yell.)"
    ),
    "PL!-pb2-035": (
        "[Activated] Put this Member from the Stage into the Waiting Room: add 1 Live card from your Waiting Room to your hand."
    ),
    "PL!-pb2-037": (
        "[Live Success] If every Member card revealed for your Yell is all Printemps, all lily white, or all BiBi, "
        "add 1 Member card from among cards revealed for that Yell to your hand."
    ),
    "PL!-pb2-038": (
        "[Always] While this card is in your Live area or Success Live area and you have exactly 2 μ's Members on your Stage "
        "(and no other Members), +1 total Live score. This effect does not stack."
    ),
    "PL!-pb2-039": (
        "[Live Start] If you have 2 or more μ's cards in your Success Live area, until this Live ends the number of your "
        "cards revealed for Yell increases by 10.\n"
        "[Live Success] This card's score +1 for each μ's Member with a different name among Members on your Stage and "
        "Member cards revealed for your Yell."
    ),
    "PL!-pb2-040": (
        "[Live Start] If 1 or more Members on your Stage were put from Wait to Active this turn by one of your Printemps "
        "card effects, this card's required Wild hearts −3. If 2 or more, −2 more. If 3 or more, −1 more."
    ),
    "PL!-pb2-041": (
        "[Always] While this card is in your Success Live area, when a lily white card effect counts cards in your "
        "Success Live area, count this card as 2.\n"
        "[Live Start] If you have 2 or more lily white cards in your Success Live area, this card's score +1."
    ),
    "PL!-pb2-042": (
        "[Automatic] [Once per turn] When you Yell, if cards revealed for that Yell include Member cards named "
        "\"Nico Yazawa\", \"Maki Nishikino\", and \"Eli Ayase\", and a BiBi Member of cost 11 or more is in your Center area, "
        "put 1 opponent Stage Member with 4 or fewer printed hearts into Wait."
    ),
}

_LEAVE_WR_MEMBER = {
    "trigger": "activated",
    "type": "leave_stage_add_from_wr",
    "filter": "member",
    "count": 1,
}
_LEAVE_WR_LIVE = {
    "trigger": "activated",
    "type": "leave_stage_add_from_wr",
    "filter": "live",
    "count": 1,
}
_LEAVE_WR_LIVE_MUSE = {
    "trigger": "activated",
    "type": "leave_stage_add_from_wr",
    "filter": "live",
    "group": _MUSE,
    "count": 1,
}

_PB2_MAP: dict[str, list[dict]] = {
    "PL!-pb2-000": [
        {"trigger": "continuous", "type": "allows_double_baton"},
        {
            "trigger": "on_enter",
            "type": "if_double_baton_add_wr_live_score_if_cost_sum",
            "group": _MUSE,
            "min_baton": 2,
            "filter": "live",
            "count": 1,
            "cost_sum": 15,
            "score_amount": 1,
        },
    ],
    "PL!-pb2-001": [{
        "trigger": "live_start",
        "type": "success_pile_icon_bonuses",
        "group": _MUSE,
        "score_icon_live_score": 1,
        "all_blade_icon_wild_heart": 1,
        "draw_icon_add_from_wr": {"group": _MUSE, "count": 1},
    }],
    "PL!-pb2-002": [{
        "trigger": "continuous",
        "type": "opp_enter_opposite_wait_max_printed_hearts",
        "max_printed_hearts": 4,
    }],
    "PL!-pb2-003": [{
        "trigger": "live_start",
        "type": "negate_opp_member_live_success_gain_heart",
        "heart": {"color": "yellow", "count": 1},
    }],
    "PL!-pb2-004": [
        {
            "trigger": "continuous",
            "type": "blade_per_success_score_icon_group",
            "group": _MUSE,
            "amount": 1,
        },
        {
            "trigger": "auto",
            "type": "auto_yell_extra_per_score_icon_group",
            "group": _MUSE,
            "once_per_turn": True,
        },
    ],
    "PL!-pb2-005": [{
        "trigger": "on_enter",
        "type": "grant_stage_group_blade_if_success_score_icon",
        "group": _MUSE,
        "blade": 1,
    }],
    "PL!-pb2-006": [
        {
            "trigger": "activated",
            "type": "optional_wait_self_discard_wait_opp_max_printed_hearts",
            "discard": 1,
            "max_printed_hearts": 1,
            "once_per_turn": True,
        },
        {
            "trigger": "live_start",
            "type": "optional_wait_self_discard_wait_opp_max_printed_hearts",
            "discard": 1,
            "max_printed_hearts": 1,
        },
    ],
    "PL!-pb2-007": [{
        "trigger": "activated",
        "type": "leave_stage_add_live_activate_per_success_group",
        "group": _MUSE,
        "filter": "live",
    }],
    "PL!-pb2-008": [{
        "trigger": "on_enter",
        "type": "optional_wait_self_look_reveal",
        "look": 4,
        "group": _MUSE,
        "filter": "live",
        "min_required_hearts_total": 8,
        "pick": 1,
    }],
    "PL!-pb2-009": [{
        "trigger": "auto",
        "type": "auto_on_leave_stage_if_baton_min_cost_energy",
        "group": _MUSE,
        "min_baton_cost": 15,
        "energy": 2,
    }],
    "PL!-pb2-010": [{
        "trigger": "live_start",
        "type": "blade_per_activated_from_wait_by_subunit_effect",
        "subunit": "Printemps",
        "amount": 1,
    }],
    "PL!-pb2-011": [
        {
            "trigger": "continuous",
            "type": "blade_per_stacked_subunit_member",
            "subunit": "BiBi",
            "amount": 1,
        },
        {
            "trigger": "auto",
            "type": "auto_stack_wr_subunit_under_on_opp_wait",
            "subunit": "BiBi",
            "max_under": 2,
        },
    ],
    "PL!-pb2-012": [
        {
            "trigger": "continuous",
            "type": "play_cost_reduce_if_wait_distinct_subunit",
            "subunit": "Printemps",
            "wait_count": 2,
            "reduce": 2,
        },
        {
            "trigger": "activated",
            "type": "activated_wait_printemps_live_from_wr",
            "subunit": "Printemps",
            "once_per_turn": True,
        },
    ],
    "PL!-pb2-013": [{
        "trigger": "on_enter",
        "type": "reveal_top_all_subunit_add_live",
        "look": 4,
        "subunit": "lily white",
    }],
    "PL!-pb2-014": [{
        "trigger": "on_enter",
        "type": "optional_reveal_hand_live_swap_success",
        "subunit": "lily white",
    }],
    "PL!-pb2-015": [{
        "trigger": "auto",
        "type": "auto_on_opp_wait_by_subunit_choose",
        "subunit": "BiBi",
        "once_per_turn": True,
        "choices": [
            {"type": "activate_stage_subunit_member", "subunit": "BiBi", "count": 1},
            {"type": "activate_energy", "count": 2},
        ],
    }],
    "PL!-pb2-016": [{
        "trigger": "live_start",
        "type": "per_success_subunit_choose",
        "subunit": "lily white",
        "choices": [
            {"type": "center_blade_bonus", "amount": 1},
            {"type": "activate_stage_member", "count": 1},
            {"type": "draw_and_discard", "draw": 1, "discard": 1},
        ],
    }],
    "PL!-pb2-017": [
        {
            "trigger": "on_enter",
            "type": "stack_wr_subunit_members_under",
            "subunit": "Printemps",
            "count": 4,
        },
        {
            "trigger": "live_start",
            "type": "optional_unstack_toggle_subunit_members",
            "subunit": "Printemps",
            "max": 3,
        },
    ],
    "PL!-pb2-018": [
        {
            "trigger": "on_enter",
            "type": "optional_activate_opp_wait_draw_each",
            "max": 3,
        },
        {
            "trigger": "on_enter_or_live_start",
            "type": "optional_discard_distinct_subunit_wait_opp",
            "subunit": "BiBi",
            "discard": 3,
            "require_stage_only_subunit": True,
        },
    ],
    "PL!-pb2-019": [{
        "trigger": "live_start",
        "type": "optional_wait_self_discard_center_group_blade",
        "discard": 1,
        "group": _MUSE,
        "blade": 2,
    }],
    "PL!-pb2-020": [{
        "trigger": "continuous",
        "type": "hearts_if_combined_success_score_min",
        "min_score": 9,
        "hearts": [{"color": "pink", "count": 1}, {"color": "yellow", "count": 1}],
    }],
    "PL!-pb2-021": [{
        "trigger": "auto",
        "type": "auto_on_self_wait_by_own_effect_active_blade",
        "blade": 1,
        "once_per_turn": True,
    }],
    "PL!-pb2-022": [_LEAVE_WR_MEMBER],
    "PL!-pb2-023": [{
        "trigger": "continuous",
        "type": "blade_if_no_success_lives",
        "amount": 1,
    }],
    "PL!-pb2-024": [{
        "trigger": "live_start",
        "type": "wait_opponent_stage_max_cost",
        "max_cost": 2,
        "pick_count": 1,
        "require_stage_only_subunit": "BiBi",
    }],
    "PL!-pb2-025": [{
        "trigger": "continuous",
        "type": "blade_per_success_subunit",
        "subunit": "lily white",
        "amount": 1,
    }],
    "PL!-pb2-026": [{
        "trigger": "activated",
        "type": "optional_wait_self_discard_look_reveal",
        "discard": 1,
        "look": 3,
        "subunit": "Printemps",
        "filter": "member",
        "once_per_turn": True,
    }],
    "PL!-pb2-027": [_LEAVE_WR_MEMBER],
    "PL!-pb2-028": [{
        "trigger": "live_start",
        "type": "optional_wait_self",
        "then": {"type": "grant_bonus_hearts", "hearts": [{"color": "yellow", "count": 1}]},
    }],
    "PL!-pb2-029": [{
        "trigger": "on_enter_or_live_start",
        "type": "wait_opp_max_printed_blade_if_stage_only_group",
        "group": _MUSE,
        "max_printed_blade": 2,
        "pick_count": 1,
    }],
    "PL!-pb2-030": [{
        "trigger": "continuous",
        "type": "blade_per_success_score_chunk",
        "chunk": 5,
        "amount": 1,
    }],
    "PL!-pb2-031": [{
        "trigger": "live_start",
        "type": "optional_discard_prompt",
        "discard": 1,
        "group": _MUSE,
        "prompt": "Put 1 μ's card from your hand into the Waiting Room: until this Live ends, gain 1 Purple heart?",
        "then": {"type": "grant_bonus_hearts", "hearts": [{"color": "purple", "count": 1}]},
    }],
    "PL!-pb2-032": [{
        "trigger": "on_enter",
        "type": "mandatory_discard_look_reveal",
        "discard": 1,
        "look": 5,
        "group": _MUSE,
        "filter": "member",
        "no_blade_heart": True,
        "pick": 1,
    }],
    "PL!-pb2-033": [{
        "trigger": "on_enter_or_live_start",
        "type": "wait_opponent_stage_max_printed_hearts",
        "max_printed_hearts": 3,
        "pick_count": 1,
    }],
    "PL!-pb2-035": [_LEAVE_WR_LIVE],
    "PL!-pb2-037": [{
        "trigger": "live_success",
        "type": "live_success_pick_yell_member_if_all_same_subunit",
        "subunits": ["Printemps", "lily white", "BiBi"],
    }],
    "PL!-pb2-038": [{
        "trigger": "continuous",
        "type": "live_score_if_exactly_group_members_on_stage",
        "group": _MUSE,
        "exact_count": 2,
        "amount": 1,
        "while_in": ["live_zone", "success_lives"],
        "no_stack": True,
    }],
    "PL!-pb2-039": [
        {
            "trigger": "live_start",
            "type": "increase_yell_reveal_if_success_group",
            "group": _MUSE,
            "min_success": 2,
            "extra_yell": 10,
        },
        {
            "trigger": "live_success",
            "type": "score_per_distinct_group_name_stage_and_yell",
            "group": _MUSE,
            "amount": 1,
        },
    ],
    "PL!-pb2-040": [{
        "trigger": "live_start",
        "type": "reduce_hearts_per_activated_from_wait_by_subunit",
        "subunit": "Printemps",
        "tiers": [
            {"min": 1, "reduce": 3, "color": "any"},
            {"min": 2, "reduce": 2, "color": "any"},
            {"min": 3, "reduce": 1, "color": "any"},
        ],
    }],
    "PL!-pb2-041": [
        {
            "trigger": "continuous",
            "type": "success_count_as_two_for_subunit_effects",
            "subunit": "lily white",
        },
        {
            "trigger": "live_start",
            "type": "score_if_success_subunit_min",
            "subunit": "lily white",
            "min_count": 2,
            "amount": 1,
        },
    ],
    "PL!-pb2-042": [{
        "trigger": "auto",
        "type": "auto_yell_wait_opp_if_named_members_and_center",
        "names": ["Nico Yazawa", "Maki Nishikino", "Eli Ayase"],
        "center_subunit": "BiBi",
        "center_min_cost": 11,
        "max_printed_hearts": 4,
        "once_per_turn": True,
    }],
}


def muse_pb2_translation(card_no: str) -> str | None:
    for prefix, text in _PB2_TRANSLATIONS.items():
        if card_no == prefix or card_no.startswith(prefix + "-"):
            return text
    return None


def abilities_for_pl_muse_pb2(card_no: str) -> list[dict] | None:
    if not card_no.startswith("PL!-pb2-"):
        return None
    # Energy / vanillas
    if "-E" in card_no.split("PL!-pb2-", 1)[-1][:1] or card_no.startswith("PL!-pb2-E"):
        return []
    for prefix, abilities in _PB2_MAP.items():
        if card_no.startswith(prefix):
            return abilities
    # pb2-034 / pb2-036 vanilla
    if card_no.startswith("PL!-pb2-034") or card_no.startswith("PL!-pb2-036"):
        return []
    return []
