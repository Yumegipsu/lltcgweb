/**
 * CPU prompt resolution entry — specialized handlers remain in index.html for now.
 */
(function (global) {
  'use strict';

  global.cpuResolvePrompt = function cpuResolvePrompt(s, cpu) {
    const pr = s.pending_prompt;
    if (!pr) return;
    const tier = global.cpuDiff();
    const winPressure = global.cpuWinPressure(cpu);
    const read = tier === 'easy' ? null : global.cpuReadOpponent(s, 'p2');
    if (global.cpuResolveStepPrompt(pr, cpu, tier, winPressure, read)) return;
    if (tier !== 'easy' && global.cpuResolvePromptSmart(s, cpu, pr, tier)) return;
    global.cpuResolvePromptBody(s, cpu, pr);
  };
})(window);
