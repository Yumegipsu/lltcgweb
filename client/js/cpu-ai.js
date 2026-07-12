/**
 * CPU prompt resolution entry — specialized handlers remain in index.html for now.
 */
(function (global) {
  'use strict';

  /** Kaho / Hasunosora Live Success: never let this optional pick softlock the CPU. */
  function resolveYellDeckTopPrompt(pr, cpu, tier, read) {
    if (pr.type !== 'live_success_pick_yell_deck_top') return false;
    try {
      if (tier === 'easy' && Math.random() < 0.45) {
        global.cpuAct('resolve_prompt', { choice: 'skip' });
        return true;
      }
      const hand = cpu.hand || [];
      const cands = pr.candidates || [];
      let id = null;
      if (typeof global.cpuPickBestYellDeckTop === 'function') {
        try {
          id = global.cpuPickBestYellDeckTop(cands, cpu, hand, tier, read);
        } catch (e) { /* fall through */ }
      }
      if (!id) id = cands.find((c) => c && c.instance_id)?.instance_id || null;
      if (id) {
        global.cpuAct('resolve_prompt', { card_id: id });
        return true;
      }
    } catch (e) { /* skip */ }
    global.cpuAct('resolve_prompt', { choice: 'skip' });
    return true;
  }

  global.cpuResolvePrompt = function cpuResolvePrompt(s, cpu) {
    const pr = s.pending_prompt;
    if (!pr) return;
    const tier = global.cpuDiff();
    const winPressure = global.cpuWinPressure(cpu);
    const read = tier === 'easy' ? null : global.cpuReadOpponent(s, 'p2');
    if (resolveYellDeckTopPrompt(pr, cpu, tier, read)) return;
    if (global.cpuResolveStepPrompt(pr, cpu, tier, winPressure, read)) return;
    if (tier !== 'easy' && global.cpuResolvePromptSmart(s, cpu, pr, tier)) return;
    global.cpuResolvePromptBody(s, cpu, pr);
  };
})(window);
