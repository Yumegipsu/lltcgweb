<?php
/**
 * Liella! (Superstar) pb2 / DUO effect type registry.
 * Handlers live in effects.php (shared with other sets); this file satisfies audit_prefix.
 * Included by effects.php.
 */

function spBp2EffectTypes(): array {
    return [
        'activated_discard_trigger_on_enter',
        'activated_swap_area_member',
        'activate_energy_up_to_if_distinct_subunit',
        'allows_double_baton',
        'auto_area_move_energy_wait',
        'auto_yell_mill_extra_yell',
        'auto_yell_no_blade_heart',
        'blade_per_hand_cards',
        'choose_heart_modifier',
        'continuous_hearts_in_slot',
        'draw_and_discard',
        'energy_wait_from_deck',
        'formation_rotate_all',
        'grant_bonus_hearts',
        'hearts_if_center_highest_cost',
        'hearts_if_min_energy',
        'if_baton_wr_group_to_hand',
        'if_double_baton_group_bonus',
        'leave_stage_add_from_wr',
        'live_success_pick_yell_card',
        'look_reveal_filter',
        'on_enter_side_area',
        'optional_discard_prompt',
        'optional_formation_change_group',
        'optional_pay_energy',
        'optional_swap_area_on_enter',
        'optional_wr_to_deck_top',
        'pay_energy_add_from_wr',
        'pick_wr_distinct_lives_opp_choice',
        'reduce_hearts_per_entered_moved_subunit',
        'reduce_yell_reveal_count',
        'score_if_fewer_success_lives',
        'score_if_hand_more_than_opp',
        'score_if_min_energy',
        'score_if_stage_member_hearts',
        'wait_opponent_stage_max_cost',
    ];
}

function spBp2IsEffectType(string $type): bool {
    return in_array($type, spBp2EffectTypes(), true);
}
