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
    const read = tier === 'easy' ? null : global.cpuReadOpponent(s, typeof global.cpuOpponentId === 'function' ? global.cpuOpponentId() : 'p2');
    if (resolveYellDeckTopPrompt(pr, cpu, tier, read)) return;
    const policyYesNo = pr.type === 'optional_live_start'
      || pr.type === 'optional_discard_prompt'
      || pr.type === 'optional_pay_energy_on_enter'
      || pr.type === 'optional_pay_energy_if_baton';
    if (policyYesNo && typeof global.cpuPolicyPromptChoice === 'function' && pr.choices && (pr.choices.includes('yes') || pr.choices.includes('no'))) {
      const choice = global.cpuPolicyPromptChoice(pr.type, tier, {
        behind: (read?.successCount ?? 0) > (cpu.success_lives || []).length,
        opp2: (read?.successCount ?? 0) >= 2,
        needLive: winPressure >= 0.45,
      });
      if (choice === 'yes' || choice === 'no') {
        if (choice === 'yes' && typeof global.cpuBuildOptionalYesPayload === 'function') {
          const data = global.cpuBuildOptionalYesPayload(pr, cpu, tier, winPressure);
          if (data) { global.cpuAct('resolve_prompt', data); return; }
        }
        if (choice === 'no' && pr.choices.includes('no')) {
          global.cpuAct('resolve_prompt', { choice: 'no' });
          return;
        }
      }
    }
    // BP07 prompts are generic shapes; resolve them before the step/smart heuristics
    // so a bp7 card pick is never answered with a bare yes/no.
    if (typeof global.cpuResolveBp7Prompt === 'function'
      && global.cpuResolveBp7Prompt(s, cpu, pr, tier, winPressure, read)) return;
    if (global.cpuResolveStepPrompt(pr, cpu, tier, winPressure, read)) return;
    if (tier !== 'easy' && global.cpuResolvePromptSmart(s, cpu, pr, tier)) return;
    global.cpuResolvePromptBody(s, cpu, pr);
  };
})(window);
