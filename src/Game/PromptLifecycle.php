<?php
/**
 * Prompt lifecycle helpers extracted from effects.php.
 */

/** Whether the phase timer should run while this prompt is pending. */
function promptUsesPhaseTimer(array $prompt): bool {
    return in_array($prompt['responder'] ?? '', ['p1', 'p2'], true);
}

function promptTimerKey(?array $prompt): string {
    if (!$prompt) {
        return '';
    }
    return implode('|', [
        $prompt['type'] ?? '',
        $prompt['responder'] ?? '',
        $prompt['step'] ?? '',
        $prompt['source_id'] ?? '',
        $prompt['prompt'] ?? '',
    ]);
}
