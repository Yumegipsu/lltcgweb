<?php
/**
 * Printed hearts as blade + group-hearts live-success negation — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchMemberHeartsLiveSuccess(
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
        case 'member_hearts_as_blade':
            foreach ($p['stage'] as $slot => &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $mbr['hearts_as_blade_color'] = $ab['color'] ?? 'blue';
                    $p['stage'][$slot] = $mbr;
                    break;
                }
            }
            unset($mbr);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] printed hearts treated as ' .
                ucfirst($ab['color'] ?? 'blue') . ' Blade hearts until this Live ends.');
            break;

        case 'replace_member_hearts_color':
            foreach ($p['stage'] as $slot => &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $color = normalizeHeartColor((string)($ab['color'] ?? 'green'));
                    $mbr['replaced_hearts'] = [$color];
                    unset($mbr['hearts_as_blade_color']);
                    $p['stage'][$slot] = $mbr;
                    break;
                }
            }
            unset($mbr);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] printed hearts become ' .
                ucfirst($ab['color'] ?? 'green') . ' until this Live ends.');
            break;

        case 'negate_self_live_success_if_group_hearts':
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor, $state, $pid)
                >= intval($ab['min_hearts'] ?? 6)) {
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        $lc['live_success_negated'] = true;
                        break;
                    }
                }
                unset($lc);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live Success ability negated (Aqours stage hearts).');
            }
            break;

    }
    return $state;
}
