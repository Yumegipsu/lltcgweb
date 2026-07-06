/**
 * Interactive beginner tutorial — live CPU match with guided goals.
 * Loaded before inline game script; hooks via window.TutorialInteractive.
 */
(function (global) {
  'use strict';

  const G = () => global.G;

  function steps() {
    return G()?.tutorialData?.steps || [];
  }

  function step() {
    return steps()[G()?.tutorialStep ?? 0];
  }

  function isLive() {
    return !!(G()?.isTutorial && G()?.tutorialLive);
  }

  function cfg() {
    return G()?.tutorialData?.config || {};
  }

  function findHandCardNo(s, myId, cardNo) {
    return (s?.players?.[myId]?.hand || []).find(c => c.card_no === cardNo);
  }

  function findStageCardNo(s, myId, slot, cardNo) {
    const m = s?.players?.[myId]?.stage?.[slot];
    return m && m.card_no === cardNo ? m : null;
  }

  function liveZoneHasCardNo(s, myId, cardNo) {
    return (s?.players?.[myId]?.live_zone || []).some(c => c.card_no === cardNo);
  }

  function bothCoinReady(s) {
    const flip = s?.coin_flip;
    return !!(flip?.ready?.p1 && flip?.ready?.p2);
  }

  function goalMet(goal, s, myId, prev) {
    if (!goal || !s) return false;
    const me = s.players?.[myId];
    switch (goal.type) {
      case 'ack_coin_flip':
        if (G()?.tutorialLive) {
          const animDone = !!G()?._coinFlipAnimComplete;
          const acked = !!(s.coin_flip?.ready?.[myId]);
          const dwellMs = 900;
          const dwellOk = !G()._coinFlipAnimCompleteAt
            || (Date.now() - G()._coinFlipAnimCompleteAt >= dwellMs);
          return animDone && acked && bothCoinReady(s) && dwellOk;
        }
        return !!(s.coin_flip?.ready?.[myId]) || s.phase !== 'coin_flip';
      case 'choose_first_player':
        if (G()?.tutorialLive) return !!G()?._tutChooseFirstDone;
        return s.phase === 'setup' && s.first_player != null;
      case 'mulligan':
        if (G()?.tutorialLive) return !!G()?._tutMulliganDone;
        return !!me?.ready_mulligan;
      case 'play_member': {
        const slot = goal.slot || 'center';
        const onStage = findStageCardNo(s, myId, slot, goal.card_no);
        return !!onStage;
      }
      case 'end_main':
        return s.phase === 'live_set' || s.phase === 'performance' || s.phase === 'live_judge'
          || (s.phase === 'main_second' && myId === 'p1');
      case 'set_live':
        return liveZoneHasCardNo(s, myId, goal.card_no);
      case 'end_live_set':
        return !!s.live_ready?.[myId] || s.phase === 'performance' || s.phase === 'live_judge';
      case 'live_judge_reached':
        return s.phase === 'live_judge' || s.phase === 'main_first' || s.phase === 'main_second';
      default:
        return false;
    }
  }

  function allows(action) {
    if (!isLive()) return false;
    const st = step();
    if (!st) return false;
    if (st.kind === 'info' || st.kind === 'watch') return false;
    const g = st.goal;
    if (!g) return true;
    switch (action) {
      case 'hand':
      case 'play_member':
        return g.type === 'play_member' || g.type === 'set_live' || g.type === 'end_live_set';
      case 'end_main':
        return g.type === 'end_main';
      case 'end_live_set':
      case 'set_live_cards':
      case 'confirm_live_set':
        return g.type === 'set_live' || g.type === 'end_live_set';
      case 'mulligan':
        return g.type === 'mulligan';
      case 'ack_coin_flip':
        return g.type === 'ack_coin_flip';
      case 'choose_first_player':
        return g.type === 'choose_first_player';
      default:
        return false;
    }
  }

  function tutBlocks(action) {
    if (!G()?.isTutorial) return false;
    if (!G().tutorialLive) return true;
    return !allows(action);
  }

  function handCardAllowed(card) {
    if (!isLive()) return true;
    const st = step();
    const g = st?.goal;
    if (!g) return st?.kind !== 'info';
    if (g.type === 'play_member' && g.card_no) {
      return card?.card_no === g.card_no;
    }
    if ((g.type === 'set_live' || g.type === 'end_live_set') && st.select_hand) {
      return card?.card_no === st.select_hand;
    }
    return true;
  }

  function syncStepUi() {
    const st = step();
    const nextBtn = global.el?.('btn-tut-next');
    const backBtn = global.el?.('btn-tut-back');
    if (!nextBtn) return;
    const isInfo = !st || st.kind === 'info';
    const isWatch = st?.kind === 'watch';
    nextBtn.hidden = !isInfo;
    nextBtn.disabled = !!G()?.tutorialAdvancing;
    if (backBtn) backBtn.disabled = (G()?.tutorialStep ?? 0) <= 0 || !!G()?.tutorialAdvancing;
    document.body.classList.toggle('tutorial-live-action', isLive() && !isInfo && !isWatch);
    const phaseBtn = global.el?.('btn-phase-primary');
    if (phaseBtn) {
      const g = st?.goal?.type;
      const lock = isLive() && !isInfo && g !== 'end_main' && g !== 'end_live_set';
      phaseBtn.classList.toggle('tut-locked', lock);
    }
  }

  function renderCurrentStep() {
    const st = step();
    if (!st || typeof global.renderTutorialStep !== 'function') return;
    global.renderTutorialStep(G().tutorialStep, { textOnly: false });
    syncStepUi();
  }

  async function sleep(ms) {
    return new Promise(r => setTimeout(r, ms));
  }

  async function tutorialCpuAct(type, data) {
    const r = await fetch(`${global.API}?action=action`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        room_id: G().roomId,
        token: G().cpuToken,
        type,
        data,
      }),
    });
    const d = await r.json();
    if (d.error) throw new Error(d.error);
    if (typeof global.pullLatestState === 'function') await global.pullLatestState();
  }

  function resolveCardId(s, pid, cardNo) {
    const hand = s?.players?.[pid]?.hand || [];
    const c = hand.find(x => x.card_no === cardNo);
    return c?.instance_id || null;
  }

  async function runCpuScript(actions) {
    if (!actions?.length || !G().cpuToken) return;
    G().tutorialHoldCpu = true;
    try {
      for (const act of actions) {
        await sleep(400);
        const s = G().gameState;
        const cpuId = G().cpuPlayerId || 'p2';
        let data = act.data ? { ...act.data } : {};
        const type = act.type;
        if (type === 'play_member' && act.card_no) {
          const cid = resolveCardId(s, cpuId, act.card_no);
          if (!cid) continue;
          data = { card_id: cid, slot: act.slot || 'center' };
        } else if (type === 'set_live_cards' && act.card_no) {
          const cid = resolveCardId(s, cpuId, act.card_no);
          if (!cid) continue;
          data = { card_ids: [cid] };
        } else if (type === 'mulligan') {
          data = { card_ids: act.card_ids || [] };
        }
        await tutorialCpuAct(type, data);
        await sleep(350);
      }
    } finally {
      G().tutorialHoldCpu = false;
    }
  }

  async function advanceStep(dir) {
    if (G()?.tutorialAdvancing) return;
    const list = steps();
    const nextIdx = (G().tutorialStep ?? 0) + dir;
    if (nextIdx < 0 || nextIdx >= list.length) {
      if (dir > 0) exitTutorial();
      return;
    }
    G().tutorialAdvancing = true;
    try {
      const prevStep = list[G().tutorialStep];
      if (dir > 0 && prevStep?.cpu_after?.length) {
        await runCpuScript(prevStep.cpu_after);
      }
      G().tutorialStep = nextIdx;
      renderCurrentStep();
      checkGoalNow();
    } finally {
      G().tutorialAdvancing = false;
      syncStepUi();
    }
  }

  async function onGoalMaybeMet(s, prev) {
    if (!isLive() || G().tutorialAdvancing || G()._tutGoalPending) return;
    const st = step();
    if (!st || st.kind === 'info') return;
    const myId = G().playerId || 'p1';
    if (!goalMet(st.goal, s, myId, prev)) return;
    G()._tutGoalPending = true;
    try {
      await sleep(st.kind === 'watch' ? 1200 : 500);
      if (!goalMet(st.goal, G().gameState, myId, prev)) return;
      await advanceStep(1);
    } finally {
      G()._tutGoalPending = false;
    }
  }

  function onStateApplied(s, prev) {
    if (!isLive()) return;
    syncStepUi();
    if (step()?.id === 'choose_first' && s?.phase === 'coin_flip') {
      if (typeof global.syncCoinFlipChoiceUi === 'function') {
        global.syncCoinFlipChoiceUi(s, G().playerId || 'p1');
      }
      if (typeof global.repositionTutorialBubbleForLiveModal === 'function') {
        requestAnimationFrame(() => global.repositionTutorialBubbleForLiveModal());
      }
    }
    void onGoalMaybeMet(s, prev);
  }

  function checkGoalNow() {
    if (!isLive() || G().tutorialAdvancing) return;
    void onGoalMaybeMet(G().gameState, G().gameState);
  }

  function deckPayload(orderKey, deckKey) {
    const c = cfg();
    const payload = {
      deck: c[deckKey] || deckKey,
      shuffle: false,
      phase_timer_enabled: false,
    };
    if (c[orderKey]) payload[orderKey.replace('_order', '_order')] = c[orderKey];
    const mainKey = orderKey;
    const nrgKey = orderKey.replace('main_order', 'energy_order');
    if (c[mainKey]) payload.main_order = c[mainKey];
    if (c[nrgKey]) payload.energy_order = c[nrgKey];
    return payload;
  }

  async function boot() {
    if (G()._tutorialBooting) return;
    try {
      if (typeof global.closeApiErrorPopup === 'function') global.closeApiErrorPopup();
      if (typeof global.dismissAllGameplayOverlays === 'function') global.dismissAllGameplayOverlays();
      if (typeof global.resetMatchTransientState === 'function') global.resetMatchTransientState();
      G()._tutorialBooting = true;
      const bootEpoch = G()._gameSessionEpoch;
      const g = G();
      if (typeof global.loadTutorialJa === 'function') await global.loadTutorialJa();
      const r = await fetch('./tutorial_guide.json?v=5', { cache: 'no-store' });
      if (!r.ok) throw new Error('Could not load tutorial guide (HTTP ' + r.status + ')');
      const data = await r.json();
      if (!data?.steps?.length) throw new Error('Tutorial guide has no steps');

      G().tutorialData = data;
      G().tutorialLabels = typeof global.tutorialInitialLabels === 'function'
        ? global.tutorialInitialLabels(data.initial_labels)
        : { p1: 'You', p2: 'Player2' };
      G().isTutorial = true;
      G().tutorialLive = true;
      G().tutorialStep = 0;
      G().tutorialAdvancing = false;
      G().tutorialHoldCpu = false;
      G()._tutChooseFirstDone = false;
      G()._tutMulliganDone = false;
      G()._tutGoalPending = false;
      G().isCPU = true;
      G().cpuDifficulty = 'easy';
      G().playerId = 'p1';
      G().polling = false;

      document.body.classList.add('tutorial-mode', 'tutorial-live-mode');

      const p1Payload = {
        name: 'You',
        ...deckPayload('p1_main_order', 'p1_deck'),
      };
      const r1 = await global.apiPost('create_room', p1Payload);
      G().roomId = r1.room_id;
      G().token = r1.player_token;
      if (typeof global.captureSyncMeta === 'function') global.captureSyncMeta(r1);

      const p2Payload = {
        room_id: G().roomId,
        name: 'Player2',
        deck: 'cpu',
        cpu_difficulty: 'easy',
        cpu_group_hint: cfg().p2_deck || 'muse',
        shuffle: false,
        main_order: cfg().p2_main_order,
        energy_order: cfg().p2_energy_order,
        phase_timer_enabled: false,
      };
      if (cfg().coin_flip_winner === 'p1' || cfg().coin_flip_winner === 'p2') {
        p2Payload.coin_flip_winner = cfg().coin_flip_winner;
      }
      const r2 = await global.apiPost('join_room', p2Payload);
      G().cpuToken = r2.player_token;
      G().cpuPlayerId = 'p2';
      if (typeof global.captureSyncMeta === 'function') global.captureSyncMeta(r2);

      if (bootEpoch !== G()._gameSessionEpoch) return;

      global.showScr('game');
      global.el('overlay-tutorial')?.classList.add('open');

      if (!G()._tutResizeBound) {
        G()._tutResizeBound = true;
        global.addEventListener('resize', () => {
          if (!G().isTutorial) return;
          const hl = step()?.highlights || [];
          if (typeof global.renderTutorialSpotlight === 'function') global.renderTutorialSpotlight(hl);
          if (typeof global.positionTutorialBubble === 'function') global.positionTutorialBubble(hl);
        });
      }

      if (typeof global.startPoll === 'function') global.startPoll();
      renderCurrentStep();
    } catch (e) {
      G().isTutorial = false;
      G().tutorialLive = false;
      document.body.classList.remove('tutorial-mode', 'tutorial-live-mode');
      if (typeof global.toast === 'function') global.toast('Tutorial error: ' + e.message, 6000);
    } finally {
      if (G()) G()._tutorialBooting = false;
    }
  }

  function exitTutorial() {
    if (typeof global.exitTutorial === 'function' && !G().tutorialLive) {
      global.exitTutorial();
      return;
    }
    G().tutorialLive = false;
    document.body.classList.remove('tutorial-live-mode');
    if (typeof global.stopPoll === 'function') global.stopPoll();
    if (typeof global.exitTutorial === 'function') global.exitTutorial();
    else if (typeof global.resetLobby === 'function') global.resetLobby();
  }

  function tutorialNav(dir) {
    if (!isLive()) {
      if (typeof global.tutorialNav === 'function') return global.tutorialNav(dir);
      return;
    }
    const st = step();
    if (st?.kind === 'info') void advanceStep(dir);
  }

  global.TutorialInteractive = {
    boot,
    allows,
    tutBlocks,
    handCardAllowed,
    onStateApplied,
    checkGoalNow,
    tutorialNav,
    exitTutorial,
    isLive,
    syncStepUi,
  };
  global.tutBlocks = tutBlocks;
})(typeof window !== 'undefined' ? window : globalThis);
