<?php
/**
 * Core ability resolver — orchestrates type switch and set-module delegation.
 */

require_once __DIR__ . '/AbilityResolverSwitch.php';
require_once __DIR__ . '/EffectRegistry.php';
require_once __DIR__ . '/EffectHandlers.php';

function resolveAbilityEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    // Do not refreshEmptyMainDecks here: WR-targeting skills (add_from_wr, etc.) must
    // see Waiting Room cards. Deck refresh happens on draw / mill / explicit empty-deck needs.
    $type = $ab['type'] ?? '';
    if (isMemberCard($source) && spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
        return $state;
    }
    $prevModSource = $state['_mod_source'] ?? null;
    $state['_mod_source'] = $source;
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    // Attribute Wait Energy / Member activations to Nijigasaki effects (Cara Tesoro, etc.).
    $prevNijiAttr = !empty($p['_effect_source_is_niji']);
    $sourceGroup = (string)($source['group'] ?? $ab['group'] ?? '');
    if ($sourceGroup === 'Nijigasaki') {
        $p['_effect_source_is_niji'] = true;
    }

    // EffectRegistry is the sole dispatcher for migrated high-frequency types.
    if ($type !== '' && class_exists(\LLTCG\Game\EffectRegistry::class)
        && \LLTCG\Game\EffectRegistry::hasHandler($type)) {
        $state = \LLTCG\Game\EffectRegistry::dispatch(
            $state,
            $pid,
            $source,
            $ab,
            $ctx,
            $type,
            $p,
            $name
        );
    } else {
        $state = resolveAbilityEffectSwitch($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (nijiIsNijigasakiEffectType($type)) {
        $state = nijiResolveNijigasakiEffect($state, $pid, $source, $ab, $ctx);
        unset($p);
        if (!$prevNijiAttr) {
            unset($state['players'][$pid]['_effect_source_is_niji']);
        }
        if ($prevModSource === null) {
            unset($state['_mod_source']);
        } else {
            $state['_mod_source'] = $prevModSource;
        }
        return $state;
    }

    if (hsIsHasunosoraBp6EffectType($type)) {
        $state = hsResolveHasunosoraEffect($state, $pid, $source, $ab, $ctx);
    } elseif ($type === 'reveal_hand_named_stack_under') {
        // Shared type: Sayaka (names) → Hasunosora PB1; Kotori (group/max_cost) → μ's gap.
        if (!empty($ab['names'])) {
            $state = hsResolveHasunosoraPb1Effect($state, $pid, $source, $ab, $ctx);
        } else {
            $state = plMuseGapResolveEffect($state, $pid, $source, $ab, $ctx);
        }
    } elseif (hsIsHasunosoraPb1EffectType($type)) {
        $state = hsResolveHasunosoraPb1Effect($state, $pid, $source, $ab, $ctx);
    } elseif (hsIsHasunosoraCl1EffectType($type)) {
        $state = hsResolveHasunosoraCl1Effect($state, $pid, $source, $ab, $ctx);
    } elseif (nBp5IsEffectType($type)) {
        $state = nBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (sBp5IsEffectType($type)) {
        $state = sBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (sBp6IsEffectType($type)) {
        $state = sBp6ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (sSd1IsEffectType($type)) {
        $state = sSd1ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (spBp5IsEffectType($type)) {
        $state = spBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (plMuseGapIsEffectType($type)) {
        $state = plMuseGapResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (plSpSd2IsEffectType($type)) {
        $state = plSpSd2ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (prVol9IsEffectType($type)) {
        $state = prVol9ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (bp7IsEffectType($type)) {
        $state = bp7ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (batch99IsEffectType($type)) {
        $state = batch99ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (spBp2IsHandlerType($type)) {
        $state = spBp2ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (function_exists('plMusePb2IsEffectType') && plMusePb2IsEffectType($type)) {
        $state = plMusePb2ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    unset($p);
    if (!$prevNijiAttr) {
        unset($state['players'][$pid]['_effect_source_is_niji']);
    }
    if ($prevModSource === null) {
        unset($state['_mod_source']);
    } else {
        $state['_mod_source'] = $prevModSource;
    }
    return $state;
}
