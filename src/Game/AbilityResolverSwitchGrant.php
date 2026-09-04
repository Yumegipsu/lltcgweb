<?php
/**
 * Grant hearts/blade/score to members — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchGrant(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $type,
    array &$p,
    string $name
): array {
    switch ($type) {
        // grant_hearts / grant_live_score_if_success → EffectHandlers via EffectRegistry

        case 'grant_bonus_hearts':
            // Until-this-Live hearts on the source Member (Setsuna N-sd2-019, Rina N-pb1-009).
            // Must not fall through the grant_* prefix as a silent no-op (#147).
            $state = applyModifierEffect($state, $pid, $ab, $source);
            if (!empty($ab['hearts'])) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained bonus heart(s) until this Live ends.');
            }
            break;

        case 'grant_hearts_if_slot_blade_hearts':
            $slot = $ab['slot'] ?? 'left';
            $mbr = $p['stage'][$slot] ?? null;
            if ($mbr && ($mbr['group'] ?? '') === ($ab['group'] ?? '')
                && memberBladeHeartCount($mbr) >= intval($ab['min_blade_hearts'] ?? 3)) {
                addBonusHeartsToMember($mbr, $ab['hearts'] ?? [], 1);
                $p['stage'][$slot] = $mbr;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] granted bonus hearts to ' .
                    ($mbr['name_en'] ?? $mbr['name']) . '.');
            }
            break;
        case 'grant_blade_if_slot_colored_hearts':
            $slot = $ab['slot'] ?? 'left';
            $mbr = $p['stage'][$slot] ?? null;
            $color = $ab['heart_color'] ?? 'red';
            if ($mbr && ($mbr['group'] ?? '') === ($ab['group'] ?? '')
                && memberHeartColorCount($mbr, $color) >= intval($ab['min_hearts'] ?? 3)) {
                $amt = intval($ab['blade'] ?? 2);
                $mbr['live_blade_bonus'] = intval($mbr['live_blade_bonus'] ?? 0) + $amt;
                $p['stage'][$slot] = $mbr;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] granted +' . $amt . ' Blade to ' .
                    ($mbr['name_en'] ?? $mbr['name']) . '.');
            }
            break;
        case 'grant_named_members_blade':
            $n = applyNamedMemberBladeBonus($state, $pid, $ab['grants'] ?? []);
            if ($n > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n named Member(s) gained Blade until Live ends.");
            }
            break;

        case 'pick_group_member_grant_hearts':
        case 'pick_member_grant_hearts':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $group = (string)($ab['group'] ?? '');
            $minBlade = intval($ab['min_blade'] ?? 0);
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) {
                    continue;
                }
                if ($group !== '' && ($mbr['group'] ?? '') !== $group) {
                    continue;
                }
                $blade = memberBladeIconCount($mbr) + intval($mbr['live_blade_bonus'] ?? 0);
                if ($blade < $minBlade) {
                    continue;
                }
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) {
                break;
            }
            $hearts = $ab['hearts'] ?? [];
            if (count($candidates) === 1) {
                applyNamedMemberHeartsBlade($state, $pid, $candidates[0]['instance_id'], [
                    'hearts' => $hearts,
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] granted bonus hearts to ' .
                    ($candidates[0]['name_en'] ?? $candidates[0]['name'] ?? 'Member') . '.');
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'pick_member_grant_hearts',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'candidates'  => $candidates,
                'hearts'      => $hearts,
                'prompt'      => $group !== ''
                    ? "Choose 1 $group Member"
                        . ($minBlade > 0 ? " with Blade $minBlade or more" : '')
                        . ' for bonus hearts until Live ends.'
                    : 'Choose 1 Member for bonus hearts until Live ends.',
                'ability'     => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose a Member for bonus hearts.");
            break;
    }
    return $state;
}
