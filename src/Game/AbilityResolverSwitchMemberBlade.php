<?php
/**
 * Apply Member Blade bonus — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchMemberBlade(
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
        case 'member_blade_bonus':
            $applied = 0;
            $state = applyOrPromptMemberBladeBonus(
                $state,
                $pid,
                $ab,
                [
                    'source_name' => $name,
                    'source_id'   => $source['instance_id'] ?? '',
                    'live_start'  => ($ctx['phase'] ?? '') === 'live_start'
                        || ($state['phase'] ?? '') === 'live_start_effects',
                ],
                $applied
            );
            if ($applied === null) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose a Stage Member for +" . intval($ab['amount'] ?? 0) . ' Blade.');
                break;
            }
            if ($applied > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $applied Member(s) gained +" . intval($ab['amount'] ?? 0) . ' Blade until Live ends.');
            }
            break;


        case 'member_blade_bonus_if_success_count':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success_count'] ?? 2)) {
                $n = applyMemberBladeBonus($state, $pid, $ab);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['amount'] ?? 2) . ' Blade until Live ends.');
            }
            break;

        case 'member_blade_bonus_if_other_live_zone':
            $exclude = $ab['exclude_live_name'] ?? '';
            $hasOther = false;
            foreach ($p['live_zone'] ?? [] as $lc) {
                if (!$lc || ($lc['card_type'] ?? '') !== 'ライブ') continue;
                $ln = $lc['name_en'] ?? $lc['name'] ?? '';
                if ($exclude !== '' && str_contains($ln, $exclude)) continue;
                if (($lc['group'] ?? '') === ($ab['group'] ?? 'Sunshine')) {
                    $hasOther = true;
                    break;
                }
            }
            if ($hasOther) {
                $n = applyMemberBladeBonus($state, $pid, $ab);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] $n Member(s) gained +" . intval($ab['amount'] ?? 1) . ' Blade until Live ends.');
            }
            break;

    }
    return $state;
}
