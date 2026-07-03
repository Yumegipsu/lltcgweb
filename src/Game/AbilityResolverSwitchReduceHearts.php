<?php
/**
 * Required-heart reduction effects — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchReduceHearts(
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
        case 'reduce_required_hearts_if_blade':
            if (totalStageBlade($p) >= intval($ab['min_blade'] ?? 10)) {
                $reduce = intval($ab['reduce'] ?? 2);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced (Stage Blade 10+).");
            }
            break;
        case 'reduce_hearts_if_success_score':
            $scoreSum = sumSuccessLiveScores($p, $state, $pid);
            if ($scoreSum >= intval($ab['min_score_6'] ?? 6)) {
                $reduce = intval($ab['reduce'] ?? 1);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced.");
                if ($scoreSum >= intval($ab['min_score_9'] ?? 9)) {
                    bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['bonus_score'] ?? 1));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] score +' . intval($ab['bonus_score'] ?? 1) . ' (Success score 9+).');
                }
            }
            break;
        case 'reduce_hearts_per_success_count':
            $n = count($p['success_lives'] ?? []) * intval($ab['per_success'] ?? 1);
            if ($n > 0) {
                $color = $ab['color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $n;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $n;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$n Gray heart(s)" : "$n heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required hearts reduced by $label (Success Live area).");
            }
            break;
        case 'reduce_hearts_if_opp_wait':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (stageHasWaitMember($state, $opp)) {
                $reduce = intval($ab['reduce'] ?? 1);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced (opponent has Wait).");
            }
            break;
        case 'reduce_hearts_if_baton_group':
            $turn = intval($state['turn'] ?? 1);
            $cnt = countBatonEnteredGroupThisTurn($p, $ab['group'] ?? '', $turn);
            if ($cnt >= intval($ab['min_baton'] ?? 2)) {
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'any',
                    intval($ab['reduce'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] required ' . ($ab['color'] ?? 'any') .
                    ' hearts reduced by ' . intval($ab['reduce'] ?? 1) .
                    " ($cnt Baton-entered Members).");
            }
            break;
        case 'reduce_hearts_if_named_cost_pair':
            $baseNames = $ab['base_names'] ?? [];
            $higherNames = $ab['higher_names'] ?? [];
            $baseCost = null;
            $ok = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr || !cardMatchesNames($mbr, $baseNames)) continue;
                $baseCost = intval($mbr['cost'] ?? 0);
                break;
            }
            if ($baseCost !== null) {
                foreach ($p['stage'] as $mbr) {
                    if (!$mbr || !cardMatchesNames($mbr, $higherNames)) continue;
                    if (intval($mbr['cost'] ?? 0) > $baseCost) {
                        $ok = true;
                        break;
                    }
                }
            }
            if ($ok) {
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'any',
                    intval($ab['reduce'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] required ' . ($ab['color'] ?? 'any') .
                    ' hearts reduced by ' . intval($ab['reduce'] ?? 1) . '.');
            }
            break;
        case 'reduce_hearts_per_entered_moved_subunit':
            $n = countEnteredMovedSubunitThisTurn($p, $ab['subunit'] ?? '')
                * intval($ab['per_member'] ?? 1);
            if ($n > 0) {
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $n;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $n;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$n Gray heart(s)" : "$n heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced.");
            }
            break;
        case 'reduce_hearts_per_live_zone_group':
            $other = countOtherLiveZoneGroup(
                $p,
                $ab['group'] ?? '',
                !empty($ab['exclude_self']) ? ($source['instance_id'] ?? '') : ''
            );
            if ($other > 0) {
                $reduce = $other * intval($ab['per_card'] ?? 2);
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'pink',
                    $reduce
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required " . ($ab['color'] ?? 'pink') .
                    " hearts reduced by $reduce.");
            }
            break;
    }
    return $state;
}
