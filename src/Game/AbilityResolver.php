<?php
/**
 * Core ability resolver — orchestrates type switch and set-module delegation.
 */

require_once __DIR__ . '/AbilityResolverSwitch.php';

function resolveAbilityEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    // Do not refreshEmptyMainDecks here: WR-targeting skills (add_from_wr, etc.) must
    // see Waiting Room cards. Deck refresh happens on draw / mill / explicit empty-deck needs.
    $type = $ab['type'] ?? '';
    if (isMemberCard($source) && spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    $state = resolveAbilityEffectSwitch($state, $pid, $source, $ab, $ctx, $type, $p, $name);

    if (nijiIsNijigasakiEffectType($type)) {
        return nijiResolveNijigasakiEffect($state, $pid, $source, $ab, $ctx);
    }

    if (hsIsHasunosoraBp6EffectType($type)) {
        return hsResolveHasunosoraEffect($state, $pid, $source, $ab, $ctx);
    }

    if (hsIsHasunosoraPb1EffectType($type)) {
        return hsResolveHasunosoraPb1Effect($state, $pid, $source, $ab, $ctx);
    }

    if (hsIsHasunosoraCl1EffectType($type)) {
        return hsResolveHasunosoraCl1Effect($state, $pid, $source, $ab, $ctx);
    }

    if (nBp5IsEffectType($type)) {
        return nBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (sBp5IsEffectType($type)) {
        return sBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (sBp6IsEffectType($type)) {
        return sBp6ResolveEffect($state, $pid, $source, $ab, $ctx);
    }
    if (sSd1IsEffectType($type)) {
        return sSd1ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (spBp5IsEffectType($type)) {
        return spBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (plMuseGapIsEffectType($type)) {
        return plMuseGapResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (plSpSd2IsEffectType($type)) {
        return plSpSd2ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (batch99IsEffectType($type)) {
        return batch99ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (spBp2IsHandlerType($type)) {
        return spBp2ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    return $state;
}
