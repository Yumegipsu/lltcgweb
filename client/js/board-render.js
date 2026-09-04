/** Board paint — extracted from index.html (overhaul Part 2A) */

/**
 * Stage Member cost for Live-temp UI (override / +bonus).
 * Defined here so board badges work even when cpu-loop.js is not loaded yet.
 */
function stageMemberLiveCostInfo(member) {
  if (!member) return { printed: 0, effective: 0, delta: 0 };
  const printed = Number(member.cost || 0);
  if (member.live_cost_override != null && member.live_cost_override !== '') {
    const effective = Number(member.live_cost_override);
    return { printed, effective, delta: effective - printed };
  }
  const bonus = Number(member.live_cost_bonus || 0);
  const effective = printed + bonus;
  return { printed, effective, delta: bonus };
}

function renderGame(s, opts = {}) {
  if(!s?.players) return;
  if (isReplayViewing()) opts = { ...opts, skipPrompt: true };
  maybePlayPhaseSfx(s);
  const myId=G.playerId, oppId=myId==='p1'?'p2':'p1';
  // Same myId as board seats — never let sleeves resolve from a different POV.
  if (window.LLTCG_SLEEVES?.applyMatchSleeves) {
    window.LLTCG_SLEEVES.applyMatchSleeves(s, { myId });
  }
  if (window.LLTCG_PLAYMATS?.applyMatchPlaymats) {
    window.LLTCG_PLAYMATS.applyMatchPlaymats(s, { myId });
  }
  const me=s.players[myId], opp=s.players[oppId];
  if(!me||!opp) return;
  syncPrepHandsChrome(s);

  // Names — spectators never see "You" for the bottom seat (perspective player).
  const tutNames = G.isTutorial && G.tutorialLabels;
  const youFallback = (typeof t === 'function' ? t('game.you') : null) || 'You';
  const oppFallback = (typeof t === 'function' ? t('game.opponent') : null) || 'Opponent';
  const mySeatName = tutNames
    ? G.tutorialLabels.p1
    : (me.name || (G.isSpectator ? 'Player' : youFallback));
  const oppSeatName = tutNames
    ? G.tutorialLabels.p2
    : (opp.name || oppFallback);
  el('my-name').textContent = mySeatName;
  el('opp-name').textContent = oppSeatName;
  const handSuffix = ' · hand';
  if (el('opp-hand-label')) el('opp-hand-label').textContent = oppSeatName + handSuffix;
  if (el('my-hand-label')) {
    el('my-hand-label').textContent = G.isSpectator
      ? mySeatName + handSuffix
      : t('game.yourHand');
  }
  if (el('opp-mat-label')) el('opp-mat-label').textContent = oppSeatName;
  if (el('my-mat-label')) el('my-mat-label').textContent = mySeatName;
  if (el('sb-my-lbl')) el('sb-my-lbl').textContent = mySeatName;
  if (el('sb-opp-lbl')) {
    el('sb-opp-lbl').textContent = tutNames
      ? G.tutorialLabels.p2
      : (opp.name || ((typeof t === 'function' ? t('game.opp') : null) || 'Opp'));
  }
  // Counters
  const ae=(me.energy_zone||[]).filter(energyChipActive).length;
  const te=(me.energy_zone||[]).length;
  el('my-en').textContent  = `${ae}/${te}`;
  el('my-dn').textContent  = zoneCardCount(me, 'main_deck');
  el('my-wn').textContent  = zoneCardCount(me, 'waiting_room');
  if(el('my-dn-side')) el('my-dn-side').textContent = zoneCardCount(me, 'main_deck');
  if(el('my-wn-side')) el('my-wn-side').textContent = zoneCardCount(me, 'waiting_room');
  if(el('my-nrg-deck-n')) el('my-nrg-deck-n').textContent = zoneCardCount(me, 'energy_deck');
  const oppAe=(opp.energy_zone||[]).filter(energyChipActive).length;
  const oppTe=(opp.energy_zone||[]).length;
  if(el('opp-en')) el('opp-en').textContent = `${oppAe}/${oppTe}`;
  el('opp-hn').textContent = opp.hand_count??(opp.hand||[]).length;
  el('opp-dn').textContent = zoneCardCount(opp, 'main_deck');
  el('opp-wn').textContent = zoneCardCount(opp, 'waiting_room');
  if(el('opp-dn-side')) el('opp-dn-side').textContent = zoneCardCount(opp, 'main_deck');
  if(el('opp-wn-side')) el('opp-wn-side').textContent = zoneCardCount(opp, 'waiting_room');
  if(el('opp-nrg-deck-n')) el('opp-nrg-deck-n').textContent = zoneCardCount(opp, 'energy_deck');
  el('hand-n').textContent = handsHiddenOnMat(s) ? '0' : (me.hand||[]).length;

  renderDeckPileOpacity('my-deck-pile', zoneCardCount(me, 'main_deck'));
  const myDeckPile = el('my-deck-pile');
  if (myDeckPile) {
    const deckHidden = isActiveGameplay(s);
    myDeckPile.classList.toggle('deck-hidden', deckHidden);
    myDeckPile.title = deckHidden ? 'Main Deck (face down)' : 'Main Deck';
  }
  renderWaitingRoomPile('my-wait-pile', me.waiting_room, s, myId);
  renderDeckPileOpacity('my-wait-pile', zoneCardCount(me, 'waiting_room'));
  renderDeckPileOpacity('my-nrg-deck-pile', zoneCardCount(me, 'energy_deck'));
  renderDeckPileOpacity('opp-deck-pile', zoneCardCount(opp, 'main_deck'));
  renderWaitingRoomPile('opp-wait-pile', opp.waiting_room, s, myId);
  renderDeckPileOpacity('opp-wait-pile', zoneCardCount(opp, 'waiting_room'));
  renderDeckPileOpacity('opp-nrg-deck-pile', zoneCardCount(opp, 'energy_deck'));
  if (typeof tcgPortraitSyncDeckDrawers === 'function') {
    try { tcgPortraitSyncDeckDrawers(s, myId); } catch (_) { /* ignore */ }
  }

  // Sidebar
  el('sb-info').innerHTML = formatSidebarInfoHtml(s);
  renderStageBoardPanel(s, myId);

  // Phase bar
  renderPhaseBar(s,me,opp,myId,oppId);
  updateSpectatorCountUI(s);
  if (typeof updateSpectateStreamDelayUI === 'function') updateSpectateStreamDelayUI(s);
  updatePhaseTimerUI(s);

  // Stages (per-slot on playmat)
  renderStageSlots('my',  me.stage,  true,  s, myId);
  renderStageSlots('opp', opp.stage, false, s, myId);
  layoutDeckPiles();
  layoutStageSlots('my');
  layoutStageSlots('opp');
  requestAnimationFrame(() => {
    layoutDeckPiles();
    layoutStageSlots('my');
    layoutStageSlots('opp');
  });

  // Energy
  renderEnergy('my-energy',  me.energy_zone||[]);
  renderEnergy('opp-energy', opp.energy_zone||[]);

  // Pips (filter mid-flight Success arrivals — same contract as WR pending)
  renderSuccessLives('my-pips',  successLivesForDisplay(me.success_lives||[]));
  renderSuccessLives('opp-pips', successLivesForDisplay(opp.success_lives||[]));
  if (typeof tcgPortraitSyncWinCounts === 'function') {
    try { tcgPortraitSyncWinCounts(s, myId); } catch (_) { /* ignore */ }
  }

  // Live zones (3 slots each)
  renderLiveSlots('my',  liveZoneForRender(s, myId),  true, myId);
  renderLiveSlots('opp', liveZoneForRender(s, oppId), false, oppId);

  updateLiveJudgeOverlay(s, me, opp, myId);

  // Hand + opponent hidden hand fan (replay/tutorial show faces)
  const hideHandsOnMat = handsHiddenOnMat(s);
  if (hideHandsOnMat) {
    clearMatHands();
    if (el('hand-n')) el('hand-n').textContent = '0';
  } else if (!opts.skipHand) {
    const visibleHand = handCardsForDisplay(me.hand || [], myId);
    if (typeof spectatorHandsHidden === 'function' && spectatorHandsHidden()) {
      renderHandFaceDownTracked(visibleHand, s, myId);
    } else {
      renderHand(visibleHand, s, myId);
    }
  } else if (el('hand-n')) {
    el('hand-n').textContent = handCardsForDisplay(me.hand || [], myId).length;
  }
  if (G.selCard) {
    const sel = findHand(G.selCard, s, myId);
    if (sel) refreshHandPreviewPanel(sel, s, myId);
  }
  if (hideHandsOnMat) {
    const zone = el('opp-hand-zone');
    if (zone) zone.innerHTML = '';
  } else if (!opts.skipOppHand) {
    const showOppFaces = (G.isTutorial || (typeof isReplayViewing === 'function' && isReplayViewing()))
      && opp.hand?.length;
    if (showOppFaces) renderOpponentHandVisible(opp.hand, s, oppId);
    else if (G.isSpectator && opp.hand?.length) {
      if (typeof spectatorHandsHidden === 'function' && spectatorHandsHidden()) {
        renderOpponentHandTracked(opp.hand, s, oppId);
      } else {
        renderOpponentHandVisible(opp.hand, s, oppId);
      }
    } else if (opponentHandUsesTrackedSlots(s, myId) && opp.hand?.length) {
      renderOpponentHandTracked(opp.hand, s, oppId);
    } else renderOpponentHand(opponentHandDisplayCount(s, oppId));
  }

  if (!opts.skipLog && !tcgMobileViewportActive()) {
    renderLog(s.log || [], G._prevLogLen || 0);
    G._prevLogLen = (s.log || []).length;
  }

  if (!opts.skipMobileMat) {
    if (typeof tcgPortraitPlayActive === 'function' && tcgPortraitPlayActive()) {
      /* portrait layout: no mat pan */
    } else {
      syncMobileMatLayout(s);
    }
  }
  if (typeof tcgPortraitOnRender === 'function') {
    try { tcgPortraitOnRender(s, myId); } catch (_) { /* ignore */ }
  }

  if (G.isSpectator) bindSpectatorInspectOnRenderedCards(s);

  // Conditional overlays
  if (isReplayViewing()) {
    const step = s?.replay?.step ?? G.replayStep ?? 0;
    const coinStep = typeof replayShouldShowCoinOverlay === 'function'
      ? replayShouldShowCoinOverlay(step, s)
      : (s.phase === 'coin_flip');
    const mullStep = typeof replayShouldShowMullOverlay === 'function'
      ? replayShouldShowMullOverlay(step, s)
      : (s.phase === 'setup' && !coinStep);
    document.body.classList.toggle('tcg-replay-coin', coinStep);
    document.body.classList.toggle('tcg-replay-setup', mullStep);
    // Drive coin/mull from the recorded step, not a leftover coin_flip snapshot.
    if (coinStep) maybeShowCoinFlip(s, myId);
    else if (typeof resetCoinFlipPresentation === 'function') resetCoinFlipPresentation();
    else {
      el('overlay-coin')?.classList.remove('open');
      if (typeof closeM === 'function') closeM('overlay-coin');
    }
    if (mullStep) {
      if (me.ready_mulligan && replayActionTypeAtStep(step) !== 'mulligan') {
        G.mulliganPending = false;
        el('overlay-mull')?.classList.remove('open');
      } else {
        const mullOpen = el('overlay-mull')?.classList.contains('open');
        if (!mullOpen) openMull(me.hand || [], { readonly: true, force: true });
        else el('overlay-mull')?.classList.add('tut-mull-readonly');
      }
    } else {
      G.mulliganPending = false;
      el('overlay-mull')?.classList.remove('open');
      if (typeof closeM === 'function') closeM('overlay-mull');
    }
  } else if (!G.isTutorial || G.tutorialLive) {
    const tutStepId = G.tutorialLive ? tutorialLiveStepId() : '';
    const showCoin = !G.tutorialLive || tutStepId === 'coin';
    if (showCoin) maybeShowCoinFlip(s, myId);
    else el('overlay-coin')?.classList.remove('open');
  if (s.phase === 'setup') {
      const showMull = !G.tutorialLive || tutStepId === 'mulligan';
    if (me.ready_mulligan) {
      G.mulliganPending = false;
      el('overlay-mull').classList.remove('open');
    } else if (G.mulliganPending) {
      el('overlay-mull').classList.remove('open');
      } else if (showMull) {
      openMull(me.hand || [], G.isSpectator
        ? { readonly: true }
        : tutorialMulliganOpenOpts());
      } else {
        el('overlay-mull').classList.remove('open');
    }
  } else {
    G.mulliganPending = false;
    el('overlay-mull').classList.remove('open');
  }
  } else {
    maybeShowTutorialFlowScreens(s, myId);
  }
  if (s.phase === 'live_set') {
    if (G.selCard) clearPlaySelection();
  } else {
    G.liveSel = [];
    G._liveSetLockPid = null;
    G._liveSetHealEndSent = false;
    setLiveSelMultiPreviewVisible(false);
    if (typeof tcgPortraitSyncLiveSelSheet === 'function') tcgPortraitSyncLiveSelSheet(s, myId);
    el('overlay-live')?.classList.remove('open');
  }

  const deferPromptForPresentation = !isReplayViewing() && shouldDeferPromptForLivePresentation(s, myId);
  if ((!G.isTutorial || G.tutorialLive) && !isReplayViewing() && !opts.skipPrompt && !deferPromptForPresentation) renderPrompt(s, myId);
  else if (G.isTutorial && !G.tutorialLive) showTutorialPromptIfNeeded(G.tutorialData?.steps?.[G.tutorialStep], s, myId);

  syncAntiSoftlockButton(s, myId);

  updateOpponentSkillWaitBanner(s, myId);

  syncHandSelectionClasses(s, myId);
  updateLiveSetButton(s, myId);
  if (G.selCard || G.drag || G.hoverCardId) syncMyStagePlayHints(s, myId);
  else updatePlayTargetChrome(s, myId);
  window.TCG_STAMPS?.syncGameUi?.(s);
  if (typeof llUpgradeBoardComponents === 'function' && !window.__llBoardZonesUpgraded) {
    llUpgradeBoardComponents(document);
    window.__llBoardZonesUpgraded = true;
  }
  if (typeof llBoardViewModel === 'function' && typeof llApplyBoardViewModel === 'function') {
    llApplyBoardViewModel(llBoardViewModel(s, myId));
  }
}

function isRedactedLiveZoneCard(c) {
  return !!c?.instance_id && !c.revealed && c.card_no === '?';
}

function isLiveStorageRevealCard(c) {
  return isLiveTypeCard(c) || c?.card_type === 'ライブ' || isMemberCard(c) || isRedactedLiveZoneCard(c);
}

/** Merge live storage from prev + next so missed poll updates still include opponent placements. */
function augmentPerfSpectaclePrev(prev, next) {
  const base = deepCloneState(prev);
  if (!base?.players || !next?.players) return base;
  const myId = next.my_id || G.playerId || base.my_id || 'p1';
  // New LIVE placement only: next's live_zone is authoritative (#95).
  // Do not wipe a deferred Performance baseline when the turn advanced after
  // judge / game end — failed Lives have already left live_zone.
  const freshLiveRound = isLiveSetPhase(next.phase) && (
    intvalTurn(next.turn) > intvalTurn(prev.turn)
    || !isLiveSetPhase(prev.phase)
    || isMainOrActivePhase(prev.phase)
  );
  for (const pid of ['p1', 'p2']) {
    const prevZone = base.players[pid]?.live_zone || [];
    const nextZone = next.players[pid]?.live_zone || [];
    if (freshLiveRound) {
      base.players[pid] = base.players[pid] || { ...next.players[pid] };
      base.players[pid].live_zone = clampLiveZoneCards(
        nextZone.map(c => (c ? { ...c } : c)).filter(Boolean)
      );
      continue;
    }
    const byId = new Map(prevZone.filter(c => c?.instance_id).map(c => [c.instance_id, { ...c }]));
    nextZone.forEach(c => {
      if (!c?.instance_id) return;
      const existing = byId.get(c.instance_id);
      if (existing) {
        const redacted = existing.card_no === '?' && !isLiveTypeCard(existing);
        if (c.revealed || (redacted && c.card_no && c.card_no !== '?')) {
          const merged = {
            ...existing,
            ...c,
            revealed: pid === myId ? (existing.revealed || c.revealed) : existing.revealed,
          };
          // Batched poll: keep opponent face-down until reveal animation runs.
          if (pid !== myId && !existing.revealed && c.revealed) {
            merged.revealed = false;
          }
          byId.set(c.instance_id, merged);
        }
        return;
      }
      if (pid !== myId) {
        byId.set(c.instance_id, {
          ...c,
          revealed: false,
          card_no: c.card_no && c.card_no !== '?' ? c.card_no : '?',
        });
      } else {
        byId.set(c.instance_id, { ...c, revealed: false });
      }
    });
    base.players[pid] = base.players[pid] || { ...next.players[pid] };
    base.players[pid].live_zone = clampLiveZoneCards([...byId.values()]);
  }
  return base;
}

function intvalTurn(t) {
  const n = parseInt(t, 10);
  return Number.isFinite(n) ? n : 0;
}

function perfMergedLiveZone(perfPrev, next, pid) {
  const a = perfPrev?.players?.[pid]?.live_zone || [];
  const b = next?.players?.[pid]?.live_zone || [];
  const byId = new Map();
  a.forEach(c => { if (c?.instance_id) byId.set(c.instance_id, c); });
  b.forEach(c => { if (c?.instance_id && !byId.has(c.instance_id)) byId.set(c.instance_id, c); });
  return [...byId.values()];
}

function isPerfSpectacleLiveSlotCard(c, next, pid) {
  if (isLiveTypeCard(c)) return true;
  if (!isRedactedLiveZoneCard(c)) return false;
  const meta = perfFindRevealedLiveMeta(next, pid, c.instance_id);
  return isLiveTypeCard(meta);
}

function collectLiveRevealFlips(prev, next, myId = G.playerId) {
  const keys = new Set();
  if (!prev || !next) return keys;
  const hideBoth = typeof spectatorHandsHidden === 'function' && spectatorHandsHidden();
  for (const pid of ['p1', 'p2']) {
    // Players only flip the opponent; hidden-hand spectators flip both seats.
    if (pid === myId && !hideBoth) continue;
    const before = prev.players?.[pid]?.live_zone || [];
    const after = next.players?.[pid]?.live_zone || [];
    const beforeById = Object.fromEntries(before.map(c => [c.instance_id, c]));
    after.forEach(c => {
      if (!isLiveStorageRevealCard(c)) return;
      const old = beforeById[c.instance_id];
      if (c.revealed && old && !old.revealed) keys.add(`${pid}:${c.instance_id}`);
    });
    before.forEach(c => {
      if (!isLiveStorageRevealCard(c) || c.revealed) return;
      const full = findCardInState(next, c.instance_id, pid);
      if (full?.revealed) keys.add(`${pid}:${c.instance_id}`);
    });
  }
  return keys;
}

/** True when opponent live-storage flip CSS should run for this transition. */
function shouldScheduleLiveStorageFlips(prev, next, myId = G.playerId) {
  if (!prev || !next) return false;
  if (G._liveRoundPlaybackActive && G._liveRevealFlips?.size) return true;
  if (liveSetPlacementInProgress(next)) return false;
  const flipKeys = collectLiveRevealFlips(prev, next, myId);
  if (!flipKeys.size) return false;
  const showTurn = inferLiveShowTurn(prev, next);
  if (showTurn != null && liveStorageRevealDoneForTurn(showTurn)) {
    if (!shouldResetLiveStorageRevealDone(prev, next, showTurn, myId)) return false;
    if (liveStorageRevealDomComplete(flipKeys, myId)) return false;
  }
  if (isMainOrActivePhase(prev.phase) && isMainOrActivePhase(next.phase)) {
    if (shouldIgnoreStaleLivePerfSignals(prev, next)) return false;
    const oppId = myId === 'p1' ? 'p2' : 'p1';
    let allRevealed = true;
    for (const key of flipKeys) {
      if (!String(key).startsWith(`${oppId}:`)) continue;
      const iid = String(key).slice(oppId.length + 1);
      const card = findCardInState(next, iid, oppId);
      if (!card?.revealed) { allRevealed = false; break; }
    }
    if (allRevealed) return false;
  }
  if (isMainOrActivePhase(next.phase) && !isLeavingLiveSetPhase(prev, next)
      && !isLiveSpectaclePipelinePhase(prev.phase)
      && detectPendingLiveSpectacleTurn(prev, next) == null) {
    return false;
  }
  return shouldRunLiveRevealSequence(prev, next);
}

function resolveLiveRevealFlipKeys(prev, next, myId = G.playerId) {
  return shouldScheduleLiveStorageFlips(prev, next, myId)
    ? collectLiveRevealFlips(prev, next, myId)
    : new Set();
}

/** Drop stale flip keys outside the active reveal sequence (defense in depth). */
function liveStorageFlipKeysForRender(s, rawKeys, myId = G.playerId) {
  if (!rawKeys?.size) return rawKeys || new Set();
  if (typeof shouldSuppressLiveStorageFlipsNow === 'function' && shouldSuppressLiveStorageFlipsNow(s)) {
    return new Set();
  }
  // Only keep keys while the reveal sequence is actively running.
  if (!G._liveStorageRevealRunning && !G._liveRoundPlaybackActive) return new Set();
  const hideBoth = typeof spectatorHandsHidden === 'function' && spectatorHandsHidden();
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  const filtered = new Set();
  for (const key of rawKeys) {
    const keyStr = String(key);
    let pid = null;
    if (keyStr.startsWith('p1:')) pid = 'p1';
    else if (keyStr.startsWith('p2:')) pid = 'p2';
    if (!pid) continue;
    if (!hideBoth && pid !== oppId) continue;
    const iid = keyStr.slice(pid.length + 1);
    const card = findCardInState(s, iid, pid);
    // Normal play drops keys once the server marks revealed. Hidden-hand spectators
    // keep keys while the DOM is still face-down so the flip can finish.
    if (card?.revealed && !hideBoth) continue;
    if (card?.revealed && hideBoth) {
      const prefix = pid === myId ? 'my' : 'opp';
      let stillFaceDown = false;
      for (let i = 0; i < 3; i++) {
        const cardEl = el(`${prefix}-live-${i}`)?.querySelector('.lcard.live-card');
        if (cardEl?.dataset?.iid !== iid) continue;
        stillFaceDown = cardEl.classList.contains('live-storage-facedown')
          || (cardEl.classList.contains('live-storage-flip') && !cardEl.classList.contains('revealed'));
        break;
      }
      if (!stillFaceDown && !G._liveFlipScheduled?.has(key)) continue;
    }
    filtered.add(key);
  }
  return filtered;
}

function liveStorageHadFaceDownOppBluff(prev, myId = G.playerId) {
  if (!prev?.players) return false;
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  return (prev.players[oppId]?.live_zone || []).some(c => !c.revealed && isLiveStorageRevealCard(c));
}

function liveStorageHadFaceDownLive(prev, myId = G.playerId) {
  return liveStorageHadFaceDownOppBluff(prev, myId);
}

function shouldRunLiveRevealSequence(prev, next) {
  if (!prev || !next || G.isTutorial) return false;
  if (liveSetPlacementInProgress(next)) return false;
  if (isEmptyLiveSkipTransition(prev, next)) return false;
  const flipKeys = collectLiveRevealFlips(prev, next);
  const oppFaceDown = liveStorageHadFaceDownOppBluff(prev);
  const prevHasLiveCards = liveRoundHasLiveCardsForRound(prev);
  const nextHasLiveCards = liveRoundHasLiveCardsForRound(next);
  const prevHasStorage = liveStorageHasCards(prev);

  if (!prevHasLiveCards && !nextHasLiveCards) {
    if (!prevHasStorage) return false;
    if (isLiveSetPhase(prev.phase) && !isLiveSetPhase(next.phase)) return true;
    if (oppFaceDown && !liveStorageHasCards(next) && next.phase !== 'live_set') return true;
    if (!oppFaceDown && flipKeys.size === 0) return false;
  } else if (!oppFaceDown && flipKeys.size === 0) {
    if (isLiveSetPhase(prev.phase) && !isLiveSetPhase(next.phase) && prevHasLiveCards) return true;
    return false;
  }

  if (isLiveSetPhase(prev.phase) && !isLiveSetPhase(next.phase) && prevHasLiveCards) return true;
  if (isLiveSetPhase(prev.phase) && !isLiveSetPhase(next.phase) && prevHasStorage) return true;
  if (flipKeys.size > 0) return true;
  if (next.phase !== 'live_set' && prevHasLiveCards && !nextHasLiveCards) return true;
  return false;
}

function shouldRunTutorialLiveReveal(lockBoard, toState, forward, prevStep) {
  if (!forward || !lockBoard || !toState) return false;
  if (prevStep?.spectacle) return false;
  if (!liveStorageHasCards(lockBoard) || !liveStorageHadFaceDownOppBluff(lockBoard)) return false;
  return collectLiveRevealFlips(lockBoard, toState).size > 0;
}

function mergeLiveCardFromFinal(prevCard, final, pid) {
  const full = findCardInState(final, prevCard.instance_id, pid);
  if (full) return { ...full, revealed: true };
  return { ...prevCard, revealed: true };
}

/** Best board for empty-round WR playback when prev.live_zone may already be cleared. */
function liveStorageBoardForPlayback(prev) {
  if (prev && liveStorageHasCards(prev)) return prev;
  const baseline = G._liveSetStorageBaseline;
  if (baseline && liveStorageHasCards(baseline)) return baseline;
  return null;
}

/** Prev for empty-round playback when G.gameState was already committed to WR (log+0 / queued poll). */
function effectiveEmptyLiveRoundPrev(prev, next) {
  if (!prev || !next) return prev;
  if (!shouldPresentEmptyLiveRound(prev, next) && !emptyLiveRoundPresentationPending(prev, next)) return prev;
  const baseline = G._liveSetStorageBaseline;
  if (baseline && liveStorageHasCards(baseline)) {
    let merged = baseline;
    if (prev && liveStorageHasCards(prev)) merged = augmentPerfSpectaclePrev(baseline, prev);
    if (!liveStorageHasCards(prev) || prev.seq === next.seq) return merged;
  }
  const board = liveStorageBoardForPlayback(prev);
  if (board && board !== prev && liveStorageHasCards(board)) return board;
  return prev;
}

function logHasSimultaneousLiveStorageReveal(s, showTurn) {
  if (!s?.log || showTurn == null) return false;
  let inTurn = 1;
  for (const e of s.log) {
    const t = parseTurnMarker(e.msg);
    if (t != null) inTurn = t;
    if (inTurn === showTurn && e.msg === 'Both players reveal Live storage simultaneously.') return true;
  }
  return false;
}

/** Server already resolved reveal — client missed face-down snapshot (batched poll / late join). */
function liveStorageRevealBypassOk(prev, next, showTurn, myId = G.playerId) {
  if (!next || showTurn == null) return false;
  if (!liveRoundHadLivesPlayed(prev, next, showTurn)) return false;
  const held = G._livePostRevealBoard;
  if (held?.players && liveStorageHadFaceDownOppBluff(held, myId)) return false;
  if (liveStorageHasCards(next) && liveStorageHadFaceDownOppBluff(next, myId)) return false;
  if (logHasSimultaneousLiveStorageReveal(next, showTurn)) return true;

  const perfPrev = buildPerfSpectaclePrev(prev, next);
  if (!perfPrev || (!liveRoundHasLiveCards(perfPrev) && !liveStorageHasCards(perfPrev))) return false;
  if (!logHasLivePerformanceForShowTurn(prev, next, showTurn)
      && !newLogHasLivePerformance(prev, next)) {
    return false;
  }

  const revealFrom = buildLiveRevealPlayback(prev, next, myId);
  if (revealFrom && liveStorageHasCards(revealFrom) && liveStorageHadFaceDownOppBluff(revealFrom, myId)) {
    return false;
  }
  return !liveStorageHasCards(next) || !liveStorageHasCards(prev)
    || !(revealFrom && liveStorageHasCards(revealFrom));
}

/** Prev for live-round spectacle when polls batch reveal + performance into one update. */
function effectiveLiveRoundPrev(prev, next) {
  if (!prev || !next) return prev;
  if (typeof settledMainBlocksLiveSpectacle === 'function' && settledMainBlocksLiveSpectacle(prev, next)) {
    return prev;
  }
  if (shouldPresentEmptyLiveRound(prev, next) || emptyLiveRoundPresentationPending(prev, next)) {
    return effectiveEmptyLiveRoundPrev(prev, next);
  }
  const showTurn = inferLiveShowTurn(prev, next);
  const hadLives = liveRoundHadLivesPlayed(prev, next, showTurn)
    || roundHasLivePerformanceSignals(prev, next);
  if (!hadLives) return prev;

  const baseline = G._liveSetStorageBaseline;
  if (baseline && liveStorageHasCards(baseline)) {
    let merged = augmentPerfSpectaclePrev(baseline, next);
    if (prev && liveStorageHasCards(prev)) merged = augmentPerfSpectaclePrev(merged, prev);
    if (!liveStorageHasCards(prev) && !liveRoundHasLiveCards(prev)) return merged;
    if (prev.seq === next.seq && !liveRoundHasLiveCards(prev) && liveRoundHasLiveCards(merged)) return merged;
  }

  const defer = G._deferPerfSpectaclePrev;
  if (defer && (liveStorageHasCards(defer) || liveRoundHasLiveCards(defer))) {
    return augmentPerfSpectaclePrev(defer, next);
  }

  const synth = synthesizePerfPrevFromNext(prev, next, showTurn);
  if (synth && !liveStorageHasCards(prev) && !liveRoundHasLiveCards(prev)) return synth;

  const perfPrev = buildPerfSpectaclePrev(prev, next);
  if (perfPrev && perfPrev !== prev
      && (liveStorageHasCards(perfPrev) || liveRoundHasLiveCards(perfPrev))
      && !liveStorageHasCards(prev) && !liveRoundHasLiveCards(prev)) {
    return perfPrev;
  }
  return prev;
}

/** Max players with live storage across prev, next, and cached live_set boards. */
function liveStoragePlacementSides(prev, next) {
  let max = 0;
  for (const board of [prev, next, G._liveSetStorageBaseline, G._deferPerfSpectaclePrev]) {
    if (!board?.players) continue;
    let sides = 0;
    for (const pid of ['p1', 'p2']) {
      if ((board.players[pid]?.live_zone?.length || 0) > 0) sides++;
    }
    max = Math.max(max, sides);
  }
  return max;
}

/** Only one side placed in LIVE storage — no simultaneous flip reveal (solo human vs CPU 0). */
function isSoloPlayerEmptyLiveRound(prev, next) {
  if (!isMemberOnlyLiveStorageRound(prev, next)) return false;
  return liveStoragePlacementSides(prev, next) === 1;
}

function fullLogHasEmptyLiveSkip(s) {
  return (s?.log || []).some(e => e.msg === 'No Lives played this turn.');
}

function fullLogHasEmptyLiveSkipForTurn(s, showTurn) {
  if (!s?.log || showTurn == null) return false;
  let inTurn = 1;
  for (const e of s.log) {
    const t = parseTurnMarker(e.msg);
    if (t != null) inTurn = t;
    if (e.msg === 'No Lives played this turn.' && inTurn === showTurn) return true;
  }
  return false;
}

function collectLiveBluffDiscards(revealState, final) {
  const board = liveStorageBoardForPlayback(revealState) || revealState;
  const moves = [];
  for (const pid of ['p1', 'p2']) {
    const prevLive = board.players?.[pid]?.live_zone || [];
    prevLive.forEach((c, index) => {
      const iid = c.instance_id;
      const stillInLive = (final.players?.[pid]?.live_zone || [])
        .some(x => x.instance_id === iid);
      if (stillInLive) return;
      const inWr = (final.players?.[pid]?.waiting_room || [])
        .some(x => x.instance_id === iid);
      if (!inWr) return;
      const card = findCardInState(final, iid, pid) || { ...c, revealed: true };
      // Live cards that fail hearts resolve after Performance — not during reveal bluff WR.
      if (isLiveTypeCard(card) || isLiveTypeCard(c)) return;
      moves.push({
        iid,
        card: { ...card, revealed: true },
        from: { zone: 'live', pid, index: liveZoneSlot(c, index), card },
        to: { zone: 'waiting_room', pid, card },
      });
    });
  }
  return moves;
}

/** Live cards dumped from storage when cannot_live (Rurino bp2-014 etc.) before Performance. */
function collectCannotLiveWrDiscards(revealState, final) {
  const board = liveStorageBoardForPlayback(revealState) || revealState;
  const moves = [];
  for (const pid of ['p1', 'p2']) {
    const pname = final?.players?.[pid]?.name || pid;
    const dumped = (final?.log || []).some(e =>
      (e?.msg || '').includes(`${pname} cannot attempt a Live; Live cards in storage went to the Waiting Room.`));
    if (!dumped) continue;
    const prevLive = board.players?.[pid]?.live_zone || [];
    prevLive.forEach((c, index) => {
      if (!c || !isLiveTypeCard(c)) return;
      const iid = c.instance_id;
      if (!iid) return;
      const stillInLive = (final.players?.[pid]?.live_zone || [])
        .some(x => x.instance_id === iid);
      if (stillInLive) return;
      const inWr = (final.players?.[pid]?.waiting_room || [])
        .some(x => x.instance_id === iid);
      if (!inWr) return;
      const card = findCardInState(final, iid, pid) || { ...c, revealed: true };
      moves.push({
        iid,
        card: { ...card, revealed: true },
        from: { zone: 'live', pid, index: liveZoneSlot(c, index), card },
        to: { zone: 'waiting_room', pid, card },
      });
    });
  }
  return moves;
}

function collectLiveStorageWrDiscards(revealState, final) {
  return [
    ...collectLiveBluffDiscards(revealState, final),
    ...collectCannotLiveWrDiscards(revealState, final),
  ];
}

/** Animate live-storage cards flying to the Waiting Room (rotation for member bluffs). */
async function playLiveStorageWrDiscards(fromState, final, myId, opts = {}) {
  const moves = collectLiveStorageWrDiscards(fromState, final);
  if (!moves.length) return false;
  const playback = deepCloneState(fromState);
  G.gameState = playback;
  if (!opts.skipInitialRender) {
    renderGame(playback, { skipLog: true });
  } else {
    syncLiveStorageSlotsFromState(playback, myId);
  }
  if (opts.initialDelayMs) await sleep(opts.initialDelayMs);
  layoutLiveSlots('my');
  layoutLiveSlots('opp');
  await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
  const prevRects = collectCardRects();
  const handBefore = collectHandSlotRects();
  captureFlightArtClones(moves, myId, playback);
  prepareWrPileAnimPending(playback, final, moves);
  // Hide sources for mid-flight mat paints (parity with playPostLiveRevealOutcomes).
  G._animHideIids = typeof animHideIidsForMoves === 'function'
    ? animHideIidsForMoves(playback, moves)
    : new Set(moves.map(m => m.iid).filter(Boolean));
  G._liveWrDiscardInProgress = true;
  try {
    for (let i = 0; i < moves.length; i++) {
      const move = moves[i];
      await executeCardMoveAnimation(move, {
        myId,
        stateBefore: playback,
        stateAfter: final,
        prevRects,
        handBefore,
        handAfter: handBefore,
        delayMs: i * LIVE_BLUFF_WR_STAGGER_MS,
        renderState: null,
        hideSource: true,
        keepHidden: true,
      });
      const pid = move.from.pid;
      const card = findCardInState(final, move.iid, pid) || move.card;
      playback.players[pid].live_zone = (playback.players[pid].live_zone || [])
        .filter(c => c.instance_id !== move.iid);
      if (card) {
        const wr = playback.players[pid].waiting_room || [];
        if (!wr.some(c => c.instance_id === move.iid)) {
          playback.players[pid].waiting_room = [...wr, card];
        }
      }
      G.gameState = playback;
      const pileId = pid === myId ? 'my-wait-pile' : 'opp-wait-pile';
      renderWaitingRoomPile(pileId, playback.players[pid].waiting_room, playback, myId);
      layoutLiveSlots(pid === myId ? 'my' : 'opp');
    }
    await sleep(240);
    syncLiveStorageSlotsFromState(playback, myId);
    G.gameState = playback;
  } finally {
    G._liveWrDiscardInProgress = false;
    clearWrPileAnimPending(final, myId);
    if (G._animHideIids) {
      for (const iid of [...G._animHideIids]) {
        if (!(typeof liveStorageDepartureLatched === 'function'
            ? liveStorageDepartureLatched(iid)
            : G._liveStorageDepartedIids?.has(String(iid)))) {
          if (typeof clearAnimHideIid === 'function') clearAnimHideIid(iid);
          else G._animHideIids.delete(iid);
        }
      }
    }
    G._animHideIids = null;
  }
  return true;
}

function wasLiveRevealBluffMove(prev, move) {
  if (!prev || move.from?.zone !== 'live' || move.to?.zone !== 'waiting_room') return false;
  const pid = move.from.pid || move.to.pid;
  const prevCard = (prev.players?.[pid]?.live_zone || [])
    .find(c => c.instance_id === move.iid);
  if (!prevCard || prevCard.revealed) return false;
  return !isLiveCard(move.card || prevCard);
}

async function runLiveStorageRevealSequence(prev, final, myId, opts = {}) {
  const showTurn = inferLiveShowTurn(prev, final);
  const revealGen = G._liveFlipGen || 0;
  const directorToken = opts.directorToken
    || ((typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active)
      ? LiveRoundDirector.token : 0);
  const revealCancelled = () => {
    if (revealGen !== (G._liveFlipGen || 0)) return true;
    if (directorToken && typeof LiveRoundDirector !== 'undefined'
        && !LiveRoundDirector.check(directorToken)) return true;
    return typeof isPresentationSuperseded === 'function' && isPresentationSuperseded();
  };
  if (liveStorageRevealDoneForTurn(showTurn)) {
    TCG_DEBUG.log('live', 'reveal sequence skip (already done for turn)', { showTurn });
    return true;
  }
  if (typeof isMainOrActivePhase === 'function' && isMainOrActivePhase(final?.phase)
      && showTurn != null
      && ((typeof liveSpectacleDoneForTurn === 'function' && liveSpectacleDoneForTurn(showTurn))
          || (typeof liveShowPerformancePresentedForTurn === 'function' && liveShowPerformancePresentedForTurn(showTurn)))
      && !G._liveStorageOutcomePending) {
    TCG_DEBUG.log('live', 'reveal sequence skip (Main after sealed show)', { showTurn });
    return true;
  }
  if (!liveRoundHasLiveCards(prev) && !liveRoundHasLiveCards(final) && !liveStorageHasCards(prev)) return false;
  const playback = deepCloneState(prev);
  const flipKeys = new Set();
  const preload = [];

  for (const pid of ['p1', 'p2']) {
    const zone = playback.players?.[pid]?.live_zone || [];
    const hideBoth = typeof spectatorHandsHidden === 'function' && spectatorHandsHidden();
    playback.players[pid].live_zone = zone.map(c => {
      // Owners see their own storage face-up; hidden-hand spectators flip both seats.
      if ((!hideBoth && pid === myId) || c.revealed || !isLiveStorageRevealCard(c)) return { ...c };
      const merged = mergeLiveCardFromFinal(c, final, pid);
      flipKeys.add(`${pid}:${merged.instance_id}`);
      preload.push(ensureCardImageLoaded(enrichCard({ ...merged, revealed: true })));
      return { ...merged, revealed: false };
    });
  }

  if (!flipKeys.size && !liveStorageHasCards(playback)) return false;
  const oppFaceDownPending = liveStorageHadFaceDownOppBluff(playback, myId);
  if (!flipKeys.size && oppFaceDownPending) {
    TCG_DEBUG.warn('live', 'reveal sequence: opponent face-down but no flip keys', TCG_DEBUG.trans(prev, final));
    return false;
  }
  if (flipKeys.size && liveStorageRevealDomComplete(flipKeys, myId)) {
    markLiveStorageRevealDone(showTurn);
    TCG_DEBUG.log('live', 'reveal sequence skip (DOM already face-up)', { showTurn, flipKeys: flipKeys.size });
    return true;
  }

  // Gate flip CSS: only this sequence may arm/start live-storage flips.
  G._liveStorageRevealRunning = true;
  try {
    if (revealCancelled()) return false;
    if (!opts.skipIntroBanner && liveStorageHasCards(playback) && liveRoundHasLiveCards(playback)
        && !isLiveSetPhase(final?.phase)) {
      queuePerformancePhaseBanner(inferLiveShowTurn(prev, final), prev, final);
    }

    if (final?.phase && !isLiveSetPhase(final.phase)) {
      playback.phase = final.phase;
    }

    G._liveFlipScheduled = new Set();
    G._liveStorageRevealAnimCount = 0;
    G._liveRevealFlips = flipKeys;
    G.gameState = playback;
    renderGame(playback, { skipLog: true });
    flushLiveStorageFlipScheduling(myId);
    await Promise.all(preload);
    if (revealCancelled()) return false;
    await waitForBannersIdle();
    if (revealCancelled()) return false;
    flushLiveStorageFlipScheduling(myId);

    const flipWait = LIVE_STORAGE_FLIP_MS
      + Math.max(0, flipKeys.size - 1) * LIVE_STORAGE_FLIP_STAGGER_MS;
    if (flipKeys.size && !(G._activeCardFlips || G._liveStorageRevealAnimCount)) {
      flushLiveStorageFlipScheduling(myId);
    }
    await sleep(flipWait);
    if (revealCancelled()) return false;
    await waitForCardFlipsIdle();
    if (revealCancelled()) return false;
    if (flipKeys.size && !G._liveStorageRevealAnimCount && !liveStorageRevealFlipsActive(flipKeys, myId)) {
      TCG_DEBUG.warn('live', 'reveal sequence: flip not started — retry scheduling', { flipKeys: flipKeys.size });
      flushLiveStorageFlipScheduling(myId);
      await sleep(flipWait);
      if (revealCancelled()) return false;
      await waitForCardFlipsIdle();
      if (revealCancelled()) return false;
    }
    await sleep(80);
    if (revealCancelled()) return false;

    for (const pid of ['p1', 'p2']) {
      const zone = playback.players?.[pid]?.live_zone || [];
      playback.players[pid].live_zone = zone.map(c => {
        const full = findCardInState(final, c.instance_id, pid);
        return full ? { ...c, ...full } : c;
      });
    }
    G.gameState = playback;

    if (opts.deferWrDiscards) {
      G._livePostRevealBoard = deepCloneState(playback);
      G.gameState = playback;
    } else {
      await playLiveStorageWrDiscards(playback, final, myId, {
        initialDelayMs: LIVE_BLUFF_WR_DELAY_MS,
        skipInitialRender: true,
      });
    }

    G._liveRevealFlips = new Set();
    G._liveFlipScheduled = new Set();
    sweepStaleLiveStorageFlipDom(playback, myId);
    let revealAnimRan = flipKeys.size === 0 || (G._liveStorageRevealAnimCount || 0) > 0;
    if (!revealAnimRan && flipKeys.size && liveStorageRevealDomComplete(flipKeys, myId)) {
      revealAnimRan = true;
      TCG_DEBUG.log('live', 'reveal sequence: DOM already face-up', { flipKeys: flipKeys.size, showTurn });
    }
    if (revealAnimRan && (flipKeys.size || !oppFaceDownPending)) {
      markLiveStorageRevealDone(showTurn);
    }
    return revealAnimRan && (flipKeys.size > 0 || !oppFaceDownPending);
  } finally {
    if (revealGen === (G._liveFlipGen || 0)) {
      G._liveStorageRevealRunning = false;
    }
  }
}

// LIVE Performance spectacle: client/js/spectacle.js (loaded after this script closes)
// --- Phase bar and LIVE Phase UI (live_set) -------------------------------------
// renderPhaseBar: phase badge, instructions, action buttons. updateLiveSetButton /
// confirmLiveSetFromHand: face-down placement and end LIVE Phase below the log.

function renderPhaseBar(s,me,opp,myId,oppId) {
  const ph=s.phase;
  el('pbadge').textContent = ph === 'live_set' ? livePhaseTitleForState(s, myId) : hPhase(ph);
  const msgE=el('pmsg'), btnsE=el('pbtns');
  btnsE.innerHTML='';
  if (G.isSpectator) {
    msgE.textContent = t('phaseMsg.spectating', {
      p1: me.name || defaultPlayerName(1),
      p2: opp.name || defaultPlayerName(2),
    });
    return;
  }
  if (isReplayViewing()) {
    msgE.textContent = t('replay.phaseBarHint', { step: G.replayStep, total: G.replayTotal });
    updatePhaseActionButton(s, myId);
    return;
  }
  const isMe=s.active_player===myId;
  const ae=(me.energy_zone||[]).filter(energyChipActive).length;

  const addBtn=(lbl,cls,fn,dis=false)=>{
    const b=document.createElement('button');
    b.className=cls; b.textContent=lbl; b.disabled=dis; b.onclick=fn;
    btnsE.appendChild(b); return b;
  };

  if(ph==='setup'){
    if(me.ready_mulligan && !opp.ready_mulligan){
      const n = me.mulligan_redrawn;
      if (n == null) {
        msgE.textContent = t('phaseMsg.setupWaitMulligan');
      } else if (Number(n) > 0) {
        msgE.textContent = t('phaseMsg.setupWaitMulliganYou', { n: Number(n) });
      } else {
        msgE.textContent = t('phaseMsg.setupWaitMulliganYouKept');
      }
    } else if (G.isSpectator && (me.ready_mulligan || opp.ready_mulligan)) {
      const parts = [];
      for (const pl of [me, opp]) {
        if (!pl?.ready_mulligan) continue;
        const n = pl.mulligan_redrawn;
        const name = pl.name || '?';
        if (n == null) parts.push(name);
        else if (Number(n) > 0) parts.push(t('phaseMsg.setupMulliganPlayerN', { name, n: Number(n) }));
        else parts.push(t('phaseMsg.setupMulliganPlayerKept', { name }));
      }
      msgE.textContent = parts.length
        ? `${parts.join(' · ')} — ${t('phaseMsg.setupWaitMulligan')}`
        : t('phaseMsg.setupWaitMulligan');
    } else {
      msgE.textContent = t('phaseMsg.setupMulligan');
    }
  }
  else if(ph==='coin_flip'){msgE.textContent = t('phaseMsg.coinFlip');}
  else if(ph==='main_first'||ph==='main_second'){
    if(isMe){
      msgE.innerHTML = t('phaseMsg.mainYour', { energy: '__ENERGY__' }).replace('__ENERGY__', energyCostHtml(ae));
    } else { msgE.textContent = phaseBarOppMainText(opp.name); }
  }
  else if(ph==='live_set'){
    const ready = s.live_ready || {};
    const myReady = !!ready[myId];
    const oppReady = !!ready[oppId];
    const activeLivePid = s.active_player;
    const activeLiveName = s.players?.[activeLivePid]?.name || (activeLivePid === myId ? me.name : opp.name) || 'Opponent';
    const liveTitle = livePhaseTitleForState(s, myId);
    const stored = (me.live_zone||[]).length;
    const sel = G.liveSel.length;
    if(isMe && !myReady){
      if (sel) {
        msgE.textContent = livePhaseMessage(liveTitle, t(sel > 1 ? 'phaseMsg.liveRaisedPlural' : 'phaseMsg.liveRaised', { count: sel }));
      } else if (stored) {
        const slots = liveSlotsLeft(s, myId);
        msgE.textContent = livePhaseMessage(liveTitle, t('phaseMsg.liveStored', { stored, slots }));
      } else {
        const slots = liveSlotsLeft(s, myId);
        msgE.textContent = livePhaseMessage(liveTitle, t('phaseMsg.livePlace', { slots }));
      }
    } else if (!isMe) {
      msgE.textContent = myReady
        ? `${liveTitle} — you locked in; waiting for ${activeLiveName}.`
        : `${liveTitle} — waiting for ${activeLiveName} to finish LIVE selection.`;
    } else {
      msgE.textContent = livePhaseMessage(liveTitle, oppReady
        ? t('phaseMsg.liveBothLocked')
        : t('phaseMsg.liveYouLocked'));
    }
  }
  else if(ph==='live_start_effects'){
    msgE.textContent = t('phaseMsg.liveStartEffects');
  }
  else if(ph==='live_success_effects'){
    msgE.textContent = t('phaseMsg.liveSuccessEffects');
  }
  else if(ph==='live_performance_first'||ph==='live_performance_second')
    {msgE.textContent = t('phaseMsg.performance');}
  else if(ph==='live_judge'){
    const meta = s.pending_prompt_meta || null;
    if (G.isSpectator && meta?.responder) {
      const who = s.players?.[meta.responder]?.name || meta.responder;
      msgE.textContent = meta.type === 'pick_judge_success_live'
        ? t('spectate.waitingJudgePick', { name: who })
        : t('spectate.waitingJudgeResolve', { name: who });
    } else {
      msgE.textContent = t('phaseMsg.liveJudge');
    }
  }
  else {msgE.textContent='';}
  updatePhaseActionButton(s, myId);
}

function skillPromptUiState(s) {
  const gs = G.gameState;
  let best = gs || s || null;
  if (gs && s) best = (gs.seq ?? 0) >= (s.seq ?? 0) ? gs : s;
  const def = G._deferredPromptState;
  if (!def?.pending_prompt) {
    return scrubAlreadyResolvedPromptState(best);
  }
  const bestSeq = best?.seq ?? 0;
  const defSeq = def.seq ?? 0;
  const myId = G.playerId;
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  const queued = G._pendingStateQueue;
  const queuedCleared = queued?.length
    && !queued[queued.length - 1]?.pending_prompt
    && (queued[queued.length - 1].seq ?? 0) >= defSeq;
  const queuedOppResolved = queued?.length
    && def.pending_prompt?.responder === oppId
    && (queued[queued.length - 1].seq ?? 0) >= defSeq
    && queued[queued.length - 1].pending_prompt?.responder !== oppId;
  if ((!best?.pending_prompt && bestSeq >= defSeq) || queuedCleared || queuedOppResolved) {
    clearDeferredPromptState({ skipBannerRefresh: true });
    return scrubAlreadyResolvedPromptState(best);
  }
  if (best?.pending_prompt) return scrubAlreadyResolvedPromptState(best);
  const presentationActive = G.animating || G._perfSpectacleActive || G._liveRoundPlaybackActive;
  if (presentationActive && def.pending_prompt?.responder === oppId) {
    return scrubAlreadyResolvedPromptState(def);
  }
  if (presentationActive && def.pending_prompt) {
    return scrubAlreadyResolvedPromptState(def);
  }
  clearDeferredPromptState({ skipBannerRefresh: true });
  return scrubAlreadyResolvedPromptState(best);
}

/** Issue #146: answered skills must not keep End Main stuck on "Resolve skill first". */
function scrubAlreadyResolvedPromptState(s) {
  if (!s?.pending_prompt) return s;
  if (typeof isPromptAlreadyResolved === 'function' && isPromptAlreadyResolved(s)) {
    if (s === G.gameState) {
      const cleared = { ...s };
      delete cleared.pending_prompt;
      G.gameState = cleared;
      return cleared;
    }
    const cleared = { ...s };
    delete cleared.pending_prompt;
    return cleared;
  }
  return s;
}

function hasOpenSkillPrompt(s) {
  const ui = skillPromptUiState(s);
  if (!ui?.pending_prompt) return false;
  if (typeof isPromptAlreadyResolved === 'function' && isPromptAlreadyResolved(ui)) return false;
  return true;
}

function clearDeferredPromptState(opts = {}) {
  if (!G._deferredPromptState) return;
  G._deferredPromptState = null;
  if (!opts.skipBannerRefresh && G.playerId && G.gameState) {
    updateOpponentSkillWaitBanner(G.gameState, G.playerId);
  }
}

/** Show a pending On Enter / skill prompt as soon as the triggering play animation lands. */
function surfaceDeferredSkillPrompt(playback, final, myId) {
  if (!final?.pending_prompt || !playback) return;
  const pr = final.pending_prompt;
  clearDeferredPromptState();
  if (pr.responder !== myId) {
    G._deferredPromptState = final;
    updateOpponentSkillWaitBanner(final, myId);
    updatePhaseActionButton(final, myId);
    return;
  }
  if (isLiveSuccessDiscardPrompt(final)) {
    clearLiveSuccessHandDeferral(final);
    G.gameState = final;
  } else if (wrZonePickPromptTypes().includes(pr.type)
      || pr.type === 'pick_yell_member' || isYellLivePickPrompt(pr)) {
    stashPerfYellRevealCache(final);
    G.gameState = mergePerfYellRevealState(final, perfYellRevealInline(final));
  } else {
    playback.pending_prompt = final.pending_prompt;
    G.gameState = playback;
  }
  renderPrompt(G.gameState, myId);
  updateOpponentSkillWaitBanner(G.gameState, myId);
  updatePhaseActionButton(G.gameState, myId);
}

/** Draw-then-hand-pick prompts: animate draws before opening the picker. */
function isDrawThenHandPickPrompt(pr) {
  if (!pr) return false;
  if (pr.type === 'effect_discard_hand' && !pr.then) return true;
  if (pr.type === 'sbp5_draw_deck_bottom') return true;
  if (pr.type === 'sbp6_discard_after_draw') return true;
  if (pr.type === 'mandatory_discard_after_draw') return true;
  return false;
}

/** Whether a skill prompt may open mid-animation (before tail draw flights finish). */
function shouldSurfacePromptDuringAnim(finalState, toZone, myId) {
  if (!finalState?.pending_prompt) return false;
  const pr = finalState.pending_prompt;
  const ph = finalState?.phase;
  if (G._perfSpectacleActive || G.animating) return false;
  if (pr.responder === myId && (ph === 'live_start_effects' || ph === 'live_success_effects')) {
    if (ph === 'live_success_effects' && isDrawThenHandPickPrompt(pr)) return false;
    return true;
  }
  if (pr.responder === myId && isDrawThenHandPickPrompt(pr)) {
    return false;
  }
  return !!(G._deferredPromptState || toZone === 'stage');
}

function shouldShowOpponentSkillWait(s, myId) {
  if (!s || G.isTutorial || G._perfSpectacleActive) return false;
  const pr = skillPromptUiState(s)?.pending_prompt;
  if (!pr || !isActiveGameplay(s)) return false;
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  if (pr.responder !== oppId) return false;
  const ph = s.phase;
  if (ph === 'live_start_effects' || ph === 'live_success_effects') return true;
  if (ph === 'main_first' || ph === 'main_second' || ph === 'live_set') return true;
  return true;
}

function updateOpponentSkillWaitBanner(s, myId) {
  const root = el('opp-skill-wait');
  const sub = el('opp-skill-wait-sub');
  const titleEl = el('opp-skill-wait-title');
  const leaveBtn = el('btn-opp-skill-wait-leave');
  if (!root) return;
  if (G.isTutorial || G._perfSpectacleActive) {
    root.hidden = true;
    root.classList.remove('show');
    if (sub) sub.textContent = '';
    if (leaveBtn) leaveBtn.hidden = true;
    return;
  }
  const auth = G.gameState && (!s || (G.gameState.seq ?? 0) >= (s.seq ?? 0)) ? G.gameState : (s || G.gameState);
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  const pr = skillPromptUiState(auth)?.pending_prompt;
  const show = shouldShowOpponentSkillWait(auth, myId)
    && pr?.responder === oppId;
  if (!show) {
    root.hidden = true;
    root.classList.remove('show');
    if (sub) sub.textContent = '';
    if (leaveBtn) leaveBtn.hidden = true;
    clearOpponentSkillWaitLogKey();
    G._sfxOppSkillWait = false;
    clearPvPWatchdog();
    return;
  }
  if (!G._sfxOppSkillWait) {
    G._sfxOppSkillWait = true;
    sfxPlayCard('skill_tick');
  }
  const oppName = auth?.players?.[oppId]?.name || 'Opponent';
  const src = pr?.source_name || '';
  root.hidden = false;
  root.classList.add('show');
  if (titleEl) setSplashTitle(titleEl, t('game.opponentSkillWait', { name: oppName }));
  if (sub) sub.textContent = src ? `${oppName} — ${src}` : '';
  if (leaveBtn) {
    leaveBtn.hidden = !G.isCPU;
    leaveBtn.textContent = t('game.cpuWaitLeave', 'Leave match');
  }
  appendOpponentSkillWaitLog(auth, myId, pr);
  if (!G.isCPU && !G.isSpectator) armPvPWatchdog(auth);
}

// Stage — one mat slot per stage position
function renderStageSlots(prefix, stage, isMe, s, myId) {
  const isOpp = prefix === 'opp';
  const flipKeys = G._stageRevealFlips || new Set();
  const oppPid = isOpp ? (myId === 'p1' ? 'p2' : 'p1') : null;
  ['left','center','right'].forEach(slot => {
    const wrap = el(`${prefix}-stage-${slot}`);
    if(!wrap) return;
    const mbr = stage?.[slot];

    if (isOpp && mbr?.instance_id) {
      const existing = wrap.querySelector(`.mslot[data-iid="${CSS.escape(mbr.instance_id)}"]`);
      // Spectators (and a stuck 3D flip) must not keep the sleeve back on a face-up member.
      const flipStuck = existing?.classList.contains('stage-flip')
        && (G.isSpectator || existing.classList.contains('revealed'));
      if (existing?.classList.contains('stage-flip') && !flipStuck) {
        existing.classList.toggle('card-arriving', !!(G._animHideIids?.has(mbr.instance_id)));
        applyMemberWaitVisual(existing, mbr, { animate: true });
        return;
      }
    }

    wrap.innerHTML = '';
    const outer = document.createElement('div');
    outer.className = 'stage-slot' + (isOpp ? ' opp' : '');
    const d = document.createElement('div');
    d.className = 'mslot';
    d.dataset.slot = slot;

    if(mbr){
      d.dataset.iid = mbr.instance_id;
      const flipKey = oppPid ? `${oppPid}:${mbr.instance_id}` : '';
      const doFlip = isOpp && !G.isSpectator && flipKeys.has(flipKey);
      if (doFlip) {
        flipKeys.delete(flipKey);
        d.classList.add('stage-flip', 'occupied');
        const inner = document.createElement('div');
        inner.className = 'live-flip-inner';
        const backFace = document.createElement('div');
        backFace.className = 'live-flip-face live-flip-back';
        appendFlipBackFill(backFace);
        const frontFace = document.createElement('div');
        frontFace.className = 'live-flip-face live-flip-front';
        appendCardFaceFill(frontFace, mbr);
        inner.appendChild(backFace);
        inner.appendChild(frontFace);
        d.appendChild(inner);
        layoutStageSlots(prefix);
        ensureCardImageLoaded(mbr).finally(() => {
          requestAnimationFrame(() => {
            layoutStageSlots(prefix);
            requestAnimationFrame(() => scheduleStageFlipReveal(d, flipKey, flipKeys));
          });
        });
        d.onclick = G.isSpectator ? null : boardCardShowClickHandler(mbr, s, myId);
      } else if(mbr.image || mbr.card_no){
        const img = document.createElement('img');
        img.alt = mbr.name_en||mbr.name;
        if (!applyCardImageToImg(img, mbr, () => img.replaceWith(mkNoImg(mbr)), {
          thumbWidth: (typeof CARD_THUMB_BOARD === 'number' ? CARD_THUMB_BOARD : 256),
        })) {
          d.appendChild(mkNoImg(mbr));
        } else d.appendChild(img);
        d.classList.add('occupied');
        d.onclick = G.isSpectator ? null : boardCardShowClickHandler(mbr, s, myId);
        applyCardFoilFx(d, mbr);
      } else {
        d.appendChild(mkNoImg(mbr));
        d.classList.add('occupied');
        d.onclick = G.isSpectator ? null : boardCardShowClickHandler(mbr, s, myId);
        applyCardFoilFx(d, mbr);
      }
      const printedHearts = (typeof memberPrintedHeartGroups === 'function')
        ? memberPrintedHeartGroups(mbr)
        : (mbr.hearts || []);
      if (printedHearts.length) {
        const hr = document.createElement('div');
        hr.className = 'stage-hearts';
        appendHeartIcons(hr, printedHearts, false, true);
        d.appendChild(hr);
      }
      applyMemberWaitVisual(d, mbr, { animate: true });
      if (!doFlip) d.classList.add('occupied');
      d.classList.toggle('card-arriving', !!(G._animHideIids?.has(mbr.instance_id)));
      const ownerPid = isMe ? myId : oppPid;
      appendMemberStackedEnergyBadge(d, mbr, s?.players?.[ownerPid]);
      appendMemberStackedMembersBadge(d, mbr);
      if (isMe) bindMyStageCardInspect(d, mbr, s, myId);
      if (!G.isSpectator && typeof bindBoardCardLongPressInspect === 'function') {
        bindBoardCardLongPressInspect(d, mbr, s, myId);
      }
    }
    outer.appendChild(d);
    if (mbr) {
      const stageOwnerPid = isMe ? myId : oppPid;
      const bladeBonus = memberModifierBladeBonus(mbr, s, stageOwnerPid, slot);
      const printedBlade = mbr.printed_blade_override != null
        ? Number(mbr.printed_blade_override)
        : Number(mbr.blade || 0);
      if (printedBlade || bladeBonus) {
        const badge = mkFieldBadge('blade', 'icon_blade.png', printedBlade, 'Blade');
        if (bladeBonus) {
          const bonusEl = document.createElement('span');
          bonusEl.className = 'badge-val-bonus';
          bonusEl.textContent = bladeBonus > 0 ? '+' + bladeBonus : String(bladeBonus);
          badge.appendChild(bonusEl);
          badge.classList.add('score-boosted');
          badge.title = bladeBonus > 0
            ? `Printed ${printedBlade} + ${bladeBonus} from modifiers`
            : `Printed ${printedBlade} ${bladeBonus} from modifiers`;
        }
        d.appendChild(badge);
      }
      const heartBonus = (typeof memberModifierHeartGroups === 'function')
        ? memberModifierHeartGroups(mbr, s, stageOwnerPid, slot)
        : [];
      if (heartBonus.length && typeof appendHeartStatCounts === 'function') {
        const heartBadge = document.createElement('div');
        heartBadge.className = 'field-badge field-hearts';
        heartBadge.title = 'Hearts gained until Live ends';
        appendHeartStatCounts(heartBadge, mbr.hearts || [], {
          lg: false,
          field: true,
          bonusHearts: heartBonus,
        });
        d.appendChild(heartBadge);
      }
      if (typeof stageMemberLiveCostInfo === 'function') {
        const costInfo = stageMemberLiveCostInfo(mbr);
        if (costInfo.delta) {
          const costBadge = mkFieldBadge('cost', 'icon_energy.png', costInfo.printed, 'Cost');
          const bonusEl = document.createElement('span');
          bonusEl.className = 'badge-val-bonus';
          bonusEl.textContent = (costInfo.delta > 0 ? '+' : '') + costInfo.delta;
          costBadge.appendChild(bonusEl);
          costBadge.classList.add(costInfo.delta > 0 ? 'cost-boosted' : 'cost-reduced');
          costBadge.title = `Printed ${costInfo.printed} → ${costInfo.effective} until Live ends`;
          d.appendChild(costBadge);
        }
      }
    }
    wrap.appendChild(outer);
  });
  if (isMe) syncMyStagePlayHints(s, myId);
  layoutStageSlots(prefix);
  requestAnimationFrame(() => layoutStageSlots(prefix));
}

function zoneCardCount(player, zoneKey) {
  const arr = player?.[zoneKey];
  const countKey = `${zoneKey}_count`;
  if (Array.isArray(arr) && arr.length > 0) return arr.length;
  if (player?.[countKey] != null) return Number(player[countKey]) || 0;
  if (Array.isArray(arr)) return arr.length;
  return 0;
}

function layoutWaitingRoomFaceSizes(pileId, cards) {
  const pile = el(pileId);
  const stack = pile?.querySelector('.deck-stack');
  if (!stack) return;
  const zw = pile.clientWidth;
  const zh = pile.clientHeight;
  if (zw < 4 || zh < 4) return;
  const wr = cards || [];
  stack.querySelectorAll('.wr-face-card').forEach(faceEl => {
    const idx = parseInt(faceEl.dataset.wrIdx ?? '', 10);
    const card = wr[idx];
    const fit = fitStageMemberCardSize(zw, zh);
    faceEl.style.width = fit.cw + 'px';
    faceEl.style.height = fit.ch + 'px';
  });
}

function relayoutWaitingRoomFaceSizes(pileId) {
  const pile = el(pileId);
  const stack = pile?.querySelector('.deck-stack .wr-face-stack');
  if (!stack) return;
  const zw = el(pileId)?.clientWidth ?? 0;
  const zh = el(pileId)?.clientHeight ?? 0;
  if (zw < 4 || zh < 4) return;
  stack.querySelectorAll('.wr-face-card').forEach(faceEl => {
    const fit = fitStageMemberCardSize(zw, zh);
    faceEl.style.width = fit.cw + 'px';
    faceEl.style.height = fit.ch + 'px';
  });
}

function waitingRoomCardsForDisplay(cards) {
  const wr = cards || [];
  const pending = G._wrPilePendingIids;
  if (!pending?.size) return wr;
  return wr.filter(c => c?.instance_id && !pending.has(c.instance_id));
}

/** Hide Success-pile chips until their Live→Success flight hands off (anti-ghost). */
function successLivesForDisplay(cards) {
  const list = cards || [];
  const pending = G._successPilePendingIids;
  if (!pending?.size) return list;
  return list.filter(c => c?.instance_id && !pending.has(c.instance_id));
}

function noteWrPilePendingMove(iid) {
  if (!iid) return;
  if (!G._wrPilePendingIids) G._wrPilePendingIids = new Set();
  G._wrPilePendingIids.add(iid);
}

function noteSuccessPilePendingMove(iid) {
  if (!iid) return;
  if (!G._successPilePendingIids) G._successPilePendingIids = new Set();
  G._successPilePendingIids.add(iid);
}

/**
 * Latch destination hides for Live-storage exits.
 * WR and Success pending are independent so Member→WR flights do not clear
 * Success pending latched at outcomes hold.
 */
function prepareWrPileAnimPending(fromState, toState, moves) {
  const list = moves || (fromState && toState ? diffCardMoves(fromState, toState) : []);
  const wrMoves = list.filter(m => m.to?.zone === 'waiting_room');
  const successMoves = list.filter(m =>
    m.to?.zone === 'success' && m.from?.zone === 'live');
  if (wrMoves.length) {
    const next = new Set(wrMoves.map(m => m.iid).filter(Boolean));
    if (G._wrPilePendingIids?.size) {
      G._wrPilePendingIids.forEach(id => next.add(id));
    }
    G._wrPilePendingIids = next;
    wrMoves.forEach(m => {
      if (m.card) ensureCardImageLoaded(m.card);
    });
  }
  if (successMoves.length) {
    const next = new Set(successMoves.map(m => m.iid).filter(Boolean));
    if (G._successPilePendingIids?.size) {
      G._successPilePendingIids.forEach(id => next.add(id));
    }
    G._successPilePendingIids = next;
  }
}

/** Latch WR + Success pending from held storage → post-outcome board (before paint). */
function prepareLiveStorageExitDestPending(fromState, toState) {
  if (!fromState || !toState || typeof diffCardMoves !== 'function') return;
  prepareWrPileAnimPending(fromState, toState, diffCardMoves(fromState, toState));
}

/** Repaint WR pile faces from current state (no pending filter). */
function repaintWaitingRoomPilesFromState(state, myId) {
  if (!state?.players) return;
  const viewer = myId || G.playerId || state.my_id || null;
  for (const pid of ['p1', 'p2']) {
    const pileId = pid === viewer ? 'my-wait-pile' : 'opp-wait-pile';
    renderWaitingRoomPile(pileId, state.players[pid]?.waiting_room, state, viewer);
  }
}

/**
 * Clear WR destination-hide latches. Always repaint when anything was pending —
 * callers often clear after flights without another renderGame, which left the
 * pile face stuck on the filtered (empty/stale) paint from prepareWrPileAnimPending
 * (Baton Touch / Live bluffs → WR looked like cards vanished).
 */
function clearWrPileAnimPending(state, myId) {
  const hadPending = !!(G._wrPilePendingIids && G._wrPilePendingIids.size);
  G._wrPilePendingIids = null;
  if (!hadPending) return;
  const board = state || G.gameState;
  if (board) repaintWaitingRoomPilesFromState(board, myId || G.playerId || board.my_id);
}

/** Repaint Success chips from current state (no pending filter). */
function repaintSuccessLivesFromState(state, myId) {
  if (!state?.players || typeof renderSuccessLives !== 'function') return;
  const viewer = myId || G.playerId || state.my_id || null;
  for (const pid of ['p1', 'p2']) {
    const pileId = pid === viewer ? 'my-pips' : 'opp-pips';
    const lives = state.players[pid]?.success_lives || [];
    renderSuccessLives(pileId, lives);
  }
  if (typeof tcgPortraitSyncWinCounts === 'function') {
    try { tcgPortraitSyncWinCounts(state, viewer); } catch (_) { /* ignore */ }
  }
}

function clearSuccessPileAnimPending(state, myId) {
  const hadPending = !!(G._successPilePendingIids && G._successPilePendingIids.size);
  G._successPilePendingIids = null;
  if (!hadPending) return;
  const board = state || G.gameState;
  if (board) repaintSuccessLivesFromState(board, myId || G.playerId || board.my_id);
}

function clearLiveStorageExitDestPending(state, myId) {
  clearWrPileAnimPending(state, myId);
  clearSuccessPileAnimPending(state, myId);
}

function wrCardInWaitingRoom(state, iid) {
  if (!state?.players || !iid) return null;
  for (const pid of ['p1', 'p2']) {
    const card = (state.players[pid]?.waiting_room || []).find(c => c?.instance_id === iid);
    if (card) return { pid, card };
  }
  return null;
}

function wrPendingFlightStillActive(iid) {
  if (!iid) return false;
  if (G._animHideIids?.has(iid)) return true;
  if (typeof document !== 'undefined' && typeof CSS !== 'undefined' && CSS.escape) {
    const node = document.querySelector(`[data-iid="${CSS.escape(iid)}"]`);
    if (node?.classList.contains('card-arriving')) return true;
  }
  return false;
}

/**
 * Drop stale WR destination-hide latches: card already in server WR with no
 * active flight ghost. Repaints affected piles when anything was released.
 */
function reconcileWrPilePending(state, myId) {
  if (G.animating || G._liveWrDiscardInProgress || G._liveRoundPlaybackActive
      || G._perfSpectacleActive || G._liveSpectacleGateRunning) {
    return false;
  }
  const pending = G._wrPilePendingIids;
  if (!pending?.size || !state?.players) return false;
  let released = false;
  for (const iid of [...pending]) {
    if (wrCardInWaitingRoom(state, iid) && !wrPendingFlightStillActive(iid)) {
      pending.delete(iid);
      released = true;
    }
  }
  if (!released) return false;
  if (!pending.size) G._wrPilePendingIids = null;
  const board = state || G.gameState;
  if (board) repaintWaitingRoomPilesFromState(board, myId || G.playerId || board.my_id);
  return true;
}

/** WR cards added in this transition without a matching flight (e.g. look-pick rest → WR). */
function wrCardsAddedWithoutAnimMoves(prev, next, moves) {
  if (!prev || !next) return [];
  const animWr = new Set((moves || []).filter(m => m.to?.zone === 'waiting_room').map(m => m.iid));
  const out = [];
  for (const pid of ['p1', 'p2']) {
    const prevIds = new Set((prev.players?.[pid]?.waiting_room || []).map(c => c.instance_id).filter(Boolean));
    for (const c of (next.players?.[pid]?.waiting_room || [])) {
      const id = c?.instance_id;
      if (id && !prevIds.has(id) && !animWr.has(id)) out.push({ pid, iid: id });
    }
  }
  return out;
}

/** Force WR pile faces to match server order (clears stale pending hides from skill resolution). */
async function refreshWaitingRoomPiles(state, myId, opts = {}) {
  if (opts.clearPending) {
    // Null latches only — this function repaints below (avoid double paint).
    G._wrPilePendingIids = null;
    G._successPilePendingIids = null;
  } else (opts.releaseIids || []).forEach(id => {
    G._wrPilePendingIids?.delete(id);
    G._successPilePendingIids?.delete(id);
  });
  for (const pid of ['p1', 'p2']) {
    const pileId = pid === myId ? 'my-wait-pile' : 'opp-wait-pile';
    const wr = state?.players?.[pid]?.waiting_room;
    const preferSrcByIdx = await preloadWrPileDisplayCards(wr);
    renderWaitingRoomPile(pileId, wr, state, myId, preferSrcByIdx);
  }
}

/** Preload face art for the top two WR pile cards (public zone — always face-up). */
async function preloadWrPileDisplayCards(cards) {
  const wr = waitingRoomCardsForDisplay(cards);
  if (!wr.length) return {};
  const topIdx = wr.length - 1;
  const underIdx = wr.length > 1 ? wr.length - 2 : -1;
  const preferSrcByIdx = {};
  const jobs = [];
  for (const idx of [underIdx, topIdx]) {
    if (idx < 0) continue;
    jobs.push((async () => {
      const url = await preloadCardFaceImage(wr[idx]);
      if (url) preferSrcByIdx[idx] = url;
    })());
  }
  await Promise.all(jobs);
  return preferSrcByIdx;
}

async function wrPileRevealPendingMove(pid, iid, state, myId) {
  if (!iid) return;
  G._wrPilePendingIids?.delete(iid);
  const pileId = pid === myId ? 'my-wait-pile' : 'opp-wait-pile';
  const wr = state?.players?.[pid]?.waiting_room;
  const preferSrcByIdx = await preloadWrPileDisplayCards(wr);
  renderWaitingRoomPile(pileId, wr, state, myId, preferSrcByIdx);
}

/** Reveal a Success-pile chip under the fading Live→Success flight ghost. */
function successPileRevealPendingMove(pid, iid, state, myId) {
  if (!iid) return;
  G._successPilePendingIids?.delete(iid);
  const pileId = pid === myId ? 'my-pips' : 'opp-pips';
  const lives = state?.players?.[pid]?.success_lives || [];
  renderSuccessLives(pileId, successLivesForDisplay(lives));
  if (typeof tcgPortraitSyncWinCounts === 'function') {
    try { tcgPortraitSyncWinCounts(state || G.gameState, myId || G.playerId); } catch (_) { /* ignore */ }
  }
}

/** Left-panel card info when hovering face-up Waiting Room pile art (yours or opponent). */
function bindWaitingRoomFaceHover(node, card, s, viewerId) {
  if (!node || !card || G.isTutorial || node.dataset.wrHoverBound) return;
  if (typeof tcgMobileViewportActive === 'function' && tcgMobileViewportActive()) return;
  node.dataset.wrHoverBound = '1';
  const enriched = enrichCard(card);
  node.addEventListener('pointerenter', () => {
    if (G.drag || G.animating) return;
    if (G.isSpectator) showSpectatorCardPreview(enriched, s || G.gameState);
    else showHoverCardPreview(enriched, s || G.gameState, viewerId || G.playerId);
  });
  node.addEventListener('pointerleave', () => {
    if (G.isSpectator) clearSpectatorCardPreview();
    else if (!pinnedHandPreviewIid()) clearHoverCardPreview();
  });
}

/** Left-panel card info when hovering Success Live Storage (yours or opponent). */
function bindSuccessLiveHover(node, card, s, viewerId) {
  if (!node || !card || G.isTutorial || node.dataset.sliveHoverBound) return;
  if (typeof tcgMobileViewportActive === 'function' && tcgMobileViewportActive()) return;
  node.dataset.sliveHoverBound = '1';
  const enriched = enrichCard(card);
  node.addEventListener('pointerenter', () => {
    if (G.drag || G.animating) return;
    if (G.isSpectator) showSpectatorCardPreview(enriched, s || G.gameState);
    else showHoverCardPreview(enriched, s || G.gameState, viewerId || G.playerId);
  });
  node.addEventListener('pointerleave', () => {
    if (G.isSpectator) clearSpectatorCardPreview();
    else if (!pinnedHandPreviewIid()) clearHoverCardPreview();
  });
}

function renderWaitingRoomPile(pileId, cards, s, viewerId, preferSrcByIdx = {}) {
  const pile = el(pileId);
  if (!pile) return;
  const stack = pile.querySelector('.deck-stack');
  if (!stack) return;
  stack.querySelectorAll('.wr-face-stack').forEach(n => n.remove());
  const wr = waitingRoomCardsForDisplay(cards);
  if (!wr.length) {
    stack.classList.remove('wr-has-cards');
    return;
  }
  stack.classList.add('wr-has-cards');
  const faceStack = document.createElement('div');
  faceStack.className = 'wr-face-stack';
  const topIdx = wr.length - 1;
  const underIdx = wr.length > 1 ? wr.length - 2 : -1;
  [underIdx, topIdx].forEach((idx, i) => {
    if (idx < 0) return;
    const card = wr[idx];
    const d = document.createElement('div');
    d.className = 'wr-face-card ' + (idx === topIdx ? 'wr-top' : 'wr-under');
    d.dataset.wrIdx = String(idx);
    if (card?.instance_id) d.dataset.iid = card.instance_id;
    if (isLiveCard(card)) d.dataset.wrLive = '1';
    appendWrPileCardFace(d, card, { preferSrc: preferSrcByIdx[idx] || '' });
    bindWaitingRoomFaceHover(d, card, s, viewerId);
    faceStack.appendChild(d);
  });
  stack.insertBefore(faceStack, stack.firstChild);
  requestAnimationFrame(() => layoutWaitingRoomFaceSizes(pileId, wr));
  void preloadWrPileDisplayCards(wr).then(fresh => {
    if (!fresh || !Object.keys(fresh).length) return;
    const pile = el(pileId);
    if (!pile) return;
    pile.querySelectorAll('.wr-face-card').forEach(face => {
      const idx = parseInt(face.dataset.wrIdx, 10);
      const url = fresh[idx];
      if (!url) return;
      const img = face.querySelector('img.card-face-fill');
      if (img && img.src !== url) img.src = url;
    });
  });
}

function renderDeckPileOpacity(pileId, count) {
  const pile = el(pileId);
  if (!pile) return;
  const stack = pile.querySelector('.deck-stack');
  if (!stack) return;
  stack.style.opacity = count > 0 ? '1' : '0.22';
  stack.classList.toggle('thick', count > 3);
  layoutDeckPiles();
}

function layoutDeckPiles() {
  const fill = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--pile-fill-scale')) || 1.14;
  document.querySelectorAll('.mat-zone.deck-pile').forEach(zone => {
    const stack = zone.querySelector('.deck-stack');
    if (!stack || zone.clientWidth < 4 || zone.clientHeight < 4) return;
    stack.style.width = Math.round(zone.clientWidth * fill) + 'px';
    stack.style.height = Math.round(zone.clientHeight * fill) + 'px';
  });
}

function fitLandscapeCardInBox(w, h, reserve = 0) {
  return fitLiveCardSize(w, h, reserve);
}

function renderOpponentHand(count) {
  const zone = el('opp-hand-zone');
  if (!zone) return;
  const existing = [...zone.querySelectorAll('.opp-hand-card')];
  const faceDown = existing.length > 0 && existing.every(c => !c.classList.contains('hcard'));
  if (faceDown && existing.length === count) {
    layoutHandFan(zone, { animate: false });
    return;
  }
  zone.replaceChildren();
  if (!count) return;
  for (let i = 0; i < count; i++) {
    const c = document.createElement('div');
    c.className = 'opp-hand-card';
    c.title = 'Face-down card';
    zone.appendChild(c);
  }
  layoutHandFan(zone);
}

/** Face-down opponent hand with stable per-card slots (CPU solo — ids known, faces hidden). */
function renderOpponentHandTracked(hand, s, oppId, opts = {}) {
  if (handsHiddenOnMat(s)) { const zone = el('opp-hand-zone'); if (zone) zone.innerHTML = ''; return; }
  const zone = el('opp-hand-zone');
  if (!zone) return;
  const cards = hand || [];
  const beforeByKey = opts.beforeByKey;
  const beforeRects = beforeByKey?.size ? null : captureShiftRectsByEl(
    [...zone.querySelectorAll('.opp-hand-card')],
    c => c.classList.contains('card-arriving'),
  );
  const existing = new Map([...zone.querySelectorAll('.opp-hand-card')].map(n => [n.dataset.iid, n]));
  const keep = new Set(cards.map(c => c.instance_id));
  existing.forEach((node, iid) => { if (!keep.has(iid)) node.remove(); });

  cards.forEach((card, idx) => {
    let d = existing.get(card.instance_id);
    if (!d) {
      d = document.createElement('div');
      d.className = 'opp-hand-card';
      d.dataset.iid = card.instance_id;
      d.title = 'Face-down card';
    }
    clearCardFoilFx(d);
    d.classList.toggle('card-arriving', !!(G._animHideIids?.has(card.instance_id)));
    const at = zone.children[idx];
    if (at !== d) zone.insertBefore(d, at || null);
  });

  layoutHandFan(zone, { beforeRects, beforeByKey, animate: opts.animate });
  if (beforeByKey?.size) {
    cards.forEach(card => {
      if (!G._animHideIids?.has(card.instance_id)) return;
      zone.querySelector(`.opp-hand-card[data-iid="${CSS.escape(card.instance_id)}"]`)
        ?.classList.add('card-arriving');
    });
  }
}

function renderOpponentHandVisible(hand, s, oppId, opts = {}) {
  const zone = el('opp-hand-zone');
  if (!zone) return;
  const cards = hand || [];
  const beforeByKey = opts.beforeByKey;
  const beforeRects = beforeByKey?.size ? null : captureShiftRectsByEl(
    [...zone.querySelectorAll('.opp-hand-card')],
    c => c.classList.contains('card-arriving'),
  );
  const existing = new Map([...zone.querySelectorAll('.opp-hand-card')].map(n => [n.dataset.iid, n]));
  const keep = new Set(cards.map(c => c.instance_id));
  existing.forEach((node, iid) => { if (!keep.has(iid)) node.remove(); });

  cards.forEach((card, idx) => {
    let d = existing.get(card.instance_id);
    if (!d) {
      d = document.createElement('div');
      d.className = 'opp-hand-card hcard';
      d.dataset.iid = card.instance_id;
      if (isLiveCard(card)) d.classList.add('card-live-hand');
      appendCardFace(d, card, { sideways: isLiveCard(card) });
    } else {
      d.classList.toggle('card-live-hand', isLiveCard(card));
    }
    if (G.isSpectator) {
      bindSpectatorCardInspect(d, card, s);
    } else {
    d.onclick = () => showCard(card, null, s, oppId);
    }
    const at = zone.children[idx];
    if (at !== d) zone.insertBefore(d, at || null);
  });

  const animateFan = opts.animate !== false && !(G.isTutorial && !G.tutorialAnimating);
  layoutHandFan(zone, { beforeRects, beforeByKey, animate: animateFan });
}

function handTypeBadgeKey(card) {
  if (card?.card_type === 'メンバー') return 'mb';
  if (card?.card_type === 'ライブ') return 'lv';
  return 'en';
}

function handTypeBadgeLabel(key) {
  return key === 'mb' ? 'MBR' : key === 'lv' ? 'LIVE' : 'NRG';
}

/** Corner type pill (MBR / LIVE / NRG) — must survive draw-flip upgrades and deferred sync. */
function ensureHandTypeBadge(d, card) {
  if (!d || !card) return;
  const tk = handTypeBadgeKey(card);
  let tb = d.querySelector(':scope > .tbadge');
  if (!tb) {
    tb = document.createElement('div');
    d.appendChild(tb);
  }
  tb.className = `tbadge ${tk}`;
  tb.textContent = handTypeBadgeLabel(tk);
}

function paintHandCard(d, card, s, myId) {
  const ph = s?.phase;
  const isMe = s?.active_player === myId;
  const ae = (s?.players?.[myId]?.energy_zone || []).filter(energyChipActive).length;
  const hand = s?.players?.[myId]?.hand || [];
  const livePick = isLiveSetSelecting(s, myId);
  const liveSelected = livePick && G.liveSel.includes(card.instance_id);
  const tutPinned = G.isTutorial && !G.tutorialLive && card.instance_id === G.tutPreviewIid;
  d.classList.toggle('card-arriving', !!(G._animHideIids?.has(card.instance_id)));
  d.classList.toggle('dragging-source', G.drag?.iid === card.instance_id);
  d.classList.toggle('play-sel', !tutPinned && (G.selCard === card.instance_id || liveSelected));
  d.classList.toggle('hover-sel', G.hoverCardId === card.instance_id && !liveSelected);
  const isMain = ph === 'main_first' || ph === 'main_second';
  d.classList.toggle('playable', card.card_type === 'メンバー' && isMe && isMain && effectiveCost(card, hand) <= ae);
  d.classList.toggle('card-live-hand', isLiveCard(card));
  let cc = d.querySelector('.ccost');
  if (card.cost !== undefined && card.card_type === 'メンバー') {
    const ec = getDisplayPlayCost(card, hand, s, myId);
    if (!cc) {
      cc = document.createElement('div');
      cc.className = 'ccost';
      cc.appendChild(mkGameIcon('icon_energy.png', 'ticon', 'Cost'));
      cc.appendChild(document.createTextNode(String(ec)));
      d.appendChild(cc);
    }
    cc.classList.toggle('ok', ae >= ec);
    cc.classList.toggle('no', ae < ec);
    cc.classList.toggle('play-cost-emphasis', G.selCard === card.instance_id);
    cc.classList.toggle('cost-discount', G.selCard === card.instance_id && G.previewSlot && ec < effectiveCost(card, hand));
    const textNode = [...cc.childNodes].find(n => n.nodeType === Node.TEXT_NODE);
    if (textNode) textNode.textContent = String(ec);
  } else if (cc) {
    cc.remove();
  }
  ensureHandTypeBadge(d, card);
}

function buildHandFlipStructure(d, card) {
  d.classList.add('hand-draw-flip');
  const inner = document.createElement('div');
  inner.className = 'hand-flip-inner';
  const backFace = document.createElement('div');
  backFace.className = 'hand-flip-face hand-flip-back';
  const backInner = document.createElement('div');
  backInner.className = 'back live-back card-back-fill';
  backFace.appendChild(backInner);
  const frontFace = document.createElement('div');
  frontFace.className = 'hand-flip-face hand-flip-front';
  appendCardFace(frontFace, card, { sideways: isLiveCard(card) });
  inner.appendChild(backFace);
  inner.appendChild(frontFace);
  d.appendChild(inner);
}

/** Add flip structure to one hand card without re-rendering the whole fan. */
function upgradeHandCardForDrawFlip(d, card) {
  if (!d || d.querySelector('.hand-flip-inner')) return;
  d.querySelectorAll(':scope > img, :scope > .card-art, :scope > .noimg, :scope > .back').forEach(n => n.remove());
  d.classList.remove('hand-facedown-spec');
  buildHandFlipStructure(d, card);
  ensureHandTypeBadge(d, card);
  d.classList.add('hand-draw-flip');
  d.classList.remove('revealed');
}

/** Face-down tracked hand for tournament spectate (bottom seat). */
function renderHandFaceDownTracked(hand, s, myId, opts = {}) {
  if (handsHiddenOnMat(s)) { clearMatHands(); return; }
  const wrap = el('hand-row');
  if (!wrap) return;
  const cards = hand || [];
  if (el('hand-n')) el('hand-n').textContent = String(cards.length);
  const beforeByKey = opts.beforeByKey;
  const beforeRects = beforeByKey?.size ? null : captureShiftRectsByEl(
    [...wrap.querySelectorAll('.hcard')],
    c => c.classList.contains('card-arriving'),
  );
  const existing = new Map([...wrap.querySelectorAll('.hcard')].map(n => [n.dataset.iid, n]));
  const keep = new Set(cards.map(c => c.instance_id).filter(Boolean));
  existing.forEach((node, iid) => { if (!keep.has(iid)) node.remove(); });

  cards.forEach((card, idx) => {
    const iid = card?.instance_id;
    if (!iid) return;
    let d = existing.get(iid);
    const skillReveal = !!(d && (d.classList.contains('hand-skill-revealed') || d.classList.contains('revealed')));
    if (!d) {
      d = document.createElement('div');
      d.className = 'hcard hand-facedown-spec';
      d.dataset.iid = iid;
      d.title = 'Face-down card';
      const back = document.createElement('div');
      back.className = 'back live-back card-back-fill';
      d.appendChild(back);
    } else if (!skillReveal) {
      d.className = 'hcard hand-facedown-spec';
      d.title = 'Face-down card';
      clearCardFoilFx(d);
      if (!d.querySelector(':scope > .back.live-back, :scope > .card-back-fill')) {
        d.replaceChildren();
        const back = document.createElement('div');
        back.className = 'back live-back card-back-fill';
        d.appendChild(back);
      }
    }
    d.classList.toggle('card-arriving', !!(G._animHideIids?.has(iid)));
    d.classList.toggle('card-live-hand', isLiveCard(card));
    const at = wrap.children[idx];
    if (at !== d) wrap.insertBefore(d, at || null);
  });

  layoutHandFan(wrap, { beforeRects, beforeByKey, animate: opts.animate });
}

function renderHand(hand,s,myId, opts = {}){
  if (handsHiddenOnMat(s)) { clearMatHands(); return; }
  const wrap=el('hand-row');
  const beforeByKey = opts.beforeByKey;
  const beforeRects = beforeByKey?.size ? null : captureShiftRectsByEl(
    [...wrap.querySelectorAll('.hcard')],
    c => c.classList.contains('dragging-source') || c.classList.contains('card-arriving'),
  );
  const flipKeys = G._handRevealFlips || new Set();
  const existing=new Map([...wrap.querySelectorAll('.hcard')].map(n=>[n.dataset.iid,n]));
  const keep=new Set(hand.map(c=>c.instance_id));
  existing.forEach((node,iid)=>{ if(!keep.has(iid)) node.remove(); });

  hand.forEach((card,idx)=>{
    const iid=card?.instance_id;
    if(!iid) return;
    let d=existing.get(iid);
    const needsFlip = flipKeys.has(iid);
    const incomingHide = !!(G._animHideIids?.has(iid));
    if(!d){
      d=document.createElement('div');
      d.className='hcard';
      d.dataset.iid=iid;
      if (needsFlip) buildHandFlipStructure(d, card);
      else appendCardFace(d, card, { sideways: isLiveCard(card) });
      ensureHandTypeBadge(d, card);
      if (G.isSpectator) bindSpectatorCardInspect(d, card, s);
      else bindHandCardEvents(d, card, s, myId);
    } else {
      if(d.dataset.iid!==iid) d.dataset.iid=iid;
      // Cards may have been created while tutorial info steps blocked binding.
      if (!G.isSpectator) bindHandCardEvents(d, card, s, myId);
    }
    if (G.isSpectator) bindSpectatorCardInspect(d, card, s);
    if(d && needsFlip && !d.querySelector('.hand-flip-inner')) {
      d.querySelectorAll(':scope > img, :scope > .card-art, :scope > .noimg').forEach(n => n.remove());
      buildHandFlipStructure(d, card);
      ensureHandTypeBadge(d, card);
    } else if (!needsFlip && d.querySelector('.hand-flip-inner')) {
      finalizeCardFlip(d);
    }
    paintHandCard(d, card, s, myId);
    d.classList.toggle('hand-draw-flip', needsFlip);
    if (d.classList.contains('hand-draw-flip') && !d.classList.contains('revealed')) {
      if (needsFlip && !incomingHide && !G._animHideIids?.has(card.instance_id)) {
        scheduleHandDrawReveal(d, card.instance_id);
      } else if (!needsFlip) {
        finalizeCardFlip(d);
      }
    }
    if (incomingHide) d.classList.add('card-arriving');
    const at=wrap.children[idx];
    if(at!==d) wrap.insertBefore(d, at||null);
  });
  layoutHandFan(wrap, { beforeRects, beforeByKey, animate: opts.animate });
  if (beforeByKey?.size) {
    hand.forEach(card => {
      if (!G._animHideIids?.has(card.instance_id)) return;
      wrap.querySelector(`.hcard[data-iid="${CSS.escape(card.instance_id)}"]`)?.classList.add('card-arriving');
    });
  }
  // Hand DOM rebuild clears classes — restore live-tutorial guided card outline.
  if (typeof reapplyTutorialHandGuide === 'function') reapplyTutorialHandGuide();
}

function energyZoneLayoutKey(zone) {
  const list = zone || [];
  return list.length + ':' + list.map(ec => ec.instance_id || '').join('|');
}

function layoutEnergyStack(stackEl, opts = {}) {
  if (!stackEl) return;
  const zone = stackEl.closest('.mat-zone') || stackEl.parentElement;
  const chips = [...stackEl.querySelectorAll('.echip-stack')];
  const skip = c => c.classList.contains('card-arriving');
  const flipAnim = stackEl.classList.contains('energy-flip-anim');
  const wantShiftAnim = opts.animate !== false && !G.tutorialAnimating
    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let beforeByEl = opts.beforeRects;
  if (wantShiftAnim && !beforeByEl && opts.beforeByKey?.size) {
    beforeByEl = new Map();
    chips.forEach(chip => {
      const key = chip.dataset.iid;
      if (key && opts.beforeByKey.has(key)) beforeByEl.set(chip, opts.beforeByKey.get(key));
    });
  }
  if (wantShiftAnim && !beforeByEl) {
    beforeByEl = captureShiftRectsByEl(chips, skip);
  }
  if (!wantShiftAnim) beforeByEl = null;
  chips.forEach(c => { c.style.transition = (wantShiftAnim || flipAnim) ? '' : 'none'; });
  chips.forEach(c => { c.style.marginLeft = '0'; c.style.height = ''; c.style.width = ''; });
  if (!chips.length || !zone) {
    if (!wantShiftAnim) chips.forEach(c => { c.style.transition = ''; });
    return;
  }

  const availW = zone.clientWidth;
  const availH = zone.clientHeight;
  if (availW < 10 || availH < 10) {
    if (!wantShiftAnim) chips.forEach(c => { c.style.transition = ''; });
    return;
  }

  const metrics = energyStackMetrics(zone, chips.length);
  if (!metrics) {
    if (!wantShiftAnim) chips.forEach(c => { c.style.transition = ''; });
    return;
  }
  const { cardH, cardW, cardWUsed, cardHUsed, step } = metrics;
  chips.forEach((wrap, i) => {
    const used = wrap.classList.contains('used');
    if (used) {
      wrap.style.height = cardHUsed + 'px';
      wrap.style.width = cardWUsed + 'px';
    } else {
      wrap.style.height = cardH + 'px';
      wrap.style.width = cardW + 'px';
    }
    wrap.style.aspectRatio = 'unset';
    if (i > 0) wrap.style.marginLeft = (step - (used ? cardWUsed : cardW)) + 'px';
    wrap.style.zIndex = String(i + 1);
  });
  if (beforeByEl) {
    playShiftAnimation(chips, beforeByEl, skip);
    const shiftMs = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--fan-shift-ms'), 10) + 80 || 360;
    window.setTimeout(() => {
      chips.forEach(w => markEnergyChipFaceReady(w.querySelector('.echip')));
    }, shiftMs);
  } else if (!wantShiftAnim && !flipAnim) {
    requestAnimationFrame(() => {
      chips.forEach(c => {
        if (!c.classList.contains('stack-shift-anim')) c.style.transition = '';
      });
    });
  }
}

function renderEnergy(id, zone) {
  const e = el(id);
  if (!e) return;
  const layoutKey = energyZoneLayoutKey(zone);
  const stack = e.querySelector('.energy-stack');
  if (stack && stack.dataset.energyKey === layoutKey) {
    const beforeByKey = captureShiftRectsByKey('.echip-stack', 'data-iid', e);
    const usedChanged = applyEnergyUsedStateBatch(stack, zone, { animate: true });
    layoutEnergyStack(stack, {
      animate: usedChanged,
      beforeByKey: usedChanged ? beforeByKey : null,
    });
    return;
  }
  const beforeByKey = stack ? captureShiftRectsByKey('.echip-stack', 'data-iid', e) : new Map();
  e.innerHTML = '';
  if (!zone.length) return;
  const newStack = document.createElement('div');
  newStack.className = 'energy-stack';
  newStack.dataset.energyKey = layoutKey;
  zone.forEach((ec) => {
    const wrap = document.createElement('div');
    wrap.className = 'echip-stack' + (energyChipActive(ec) ? '' : ' used');
    wrap.dataset.iid = ec.instance_id;
    const chip = document.createElement('div');
    chip.className = 'echip';
    syncEnergyChipFace(chip, ec, !energyChipActive(ec));
    chip.title = energyChipActive(ec) ? 'Available energy' : 'Used energy';
    wrap.appendChild(chip);
    newStack.appendChild(wrap);
  });
  e.appendChild(newStack);
  layoutEnergyStack(newStack, {
    beforeByKey,
    animate: !G.tutorialAnimating && beforeByKey.size > 0,
  });
}

/** Success live groove tops within the playmat outline (measured from playmat.png). */
const SUCCESS_LIVE_GROOVE_TOP = [0, 0.175, 0.35];

function successLiveStackMetrics(zoneEl, n) {
  const availW = zoneEl.clientWidth;
  const availH = zoneEl.clientHeight;
  const fill = pileFillScale();
  const prefix = zoneEl.id === 'opp-pips' ? 'opp' : 'my';
  let fit = fitLiveStorageFieldSize(prefix, availW, availH);
  if (!fit?.cw) fit = fitLiveCardSize(availW, availH, 0);
  let cardW = Math.min(fit.cw, availW * fill);
  let cardH = cardW * (63 / 88);
  const gi = Math.max(0, Math.min(n - 1, SUCCESS_LIVE_GROOVE_TOP.length - 1));
  const anchorY = SUCCESS_LIVE_GROOVE_TOP[gi] * availH;
  const maxCardH = availH - anchorY - Math.max(1, availH * 0.02);
  if (cardH > maxCardH) {
    cardH = maxCardH;
    cardW = cardH * (88 / 63);
  }
  return { cardW, cardH, grooveTop: SUCCESS_LIVE_GROOVE_TOP };
}

function successLiveChipRect(zoneEl, metrics, index, isOpp) {
  if (!zoneEl || !metrics) return null;
  const zr = zoneEl.getBoundingClientRect();
  const { cardW, cardH, grooveTop } = metrics;
  const gi = Math.min(index, grooveTop.length - 1);
  const anchor = grooveTop[gi] * zr.height;
  const left = zr.left + (zr.width - cardW) / 2;
  const top = isOpp
    ? zr.top + zr.height - anchor - cardH
    : zr.top + anchor;
  return { left, top, width: cardW, height: cardH };
}

function layoutSuccessLiveStack(stackEl) {
  if (!stackEl) return;
  const zone = stackEl.closest('.mat-zone') || stackEl.parentElement;
  const isOpp = zone?.id === 'opp-pips';
  const chips = [...stackEl.querySelectorAll('.slive-chip')];
  chips.forEach(c => {
    c.style.marginTop = '0';
    c.style.marginBottom = '0';
    c.style.top = '';
    c.style.bottom = '';
    c.style.width = '';
    c.style.height = '';
    c.style.zIndex = '';
    c.style.transform = 'translateX(-50%)';
  });
  if (!chips.length || !zone) return;

  const availW = zone.clientWidth;
  const availH = zone.clientHeight;
  if (availW < 10 || availH < 10) return;

  const n = chips.length;
  const metrics = successLiveStackMetrics(zone, n);
  const { cardW, cardH, grooveTop } = metrics;

  chips.forEach((wrap, i) => {
    wrap.style.width = cardW + 'px';
    wrap.style.height = cardH + 'px';
    wrap.style.aspectRatio = 'unset';
    wrap.style.zIndex = String(i + 1);
    const gi = Math.min(i, grooveTop.length - 1);
    const anchor = grooveTop[gi] * availH;
    if (isOpp) {
      wrap.style.top = '';
      wrap.style.bottom = anchor + 'px';
    } else {
      wrap.style.bottom = '';
      wrap.style.top = anchor + 'px';
    }
  });
}

function fitStageMemberCardSize(zoneW, zoneH) {
  const fill = pileFillScale();
  const refH = referencePileHeight();
  let ch = Math.max(
    zoneH * fill,
    refH > 0 ? refH : 0,
    24
  );
  let cw = ch * (63 / 88);
  if (cw > zoneW * fill * 1.05) {
    cw = zoneW * fill;
    ch = cw * (88 / 63);
  }
  return { cw, ch };
}

function fitPortraitCardInBox(w, h, reserveBottom = 0) {
  const scale = fieldCardScale();
  const innerH = Math.max(4, h - reserveBottom - 2);
  const byW = { cw: w, ch: w * (88 / 63) };
  const byH = { cw: innerH * (63 / 88), ch: innerH };
  const pick = byW.ch <= innerH ? byW : byH;
  return { cw: pick.cw * scale, ch: pick.ch * scale };
}

function syncStageFlipInnerSize(mslot, fit) {
  const inner = mslot?.querySelector('.live-flip-inner');
  if (!inner) return;
  const w = fit?.cw ?? mslot.offsetWidth;
  const h = fit?.ch ?? mslot.offsetHeight;
  if (w > 0 && h > 0) {
    inner.style.width = `${w}px`;
    inner.style.height = `${h}px`;
  }
}

function syncLiveStorageFlipInnerSize(card, fit, layoutPrefix = 'my') {
  if (!card) return;
  const w = fit?.cw ?? card.offsetWidth;
  const h = fit?.ch ?? card.offsetHeight;
  if (w <= 0 || h <= 0) return;
  card.style.width = `${w}px`;
  card.style.height = `${h}px`;
  const inner = card.querySelector('.live-flip-inner');
  if (!inner) return;
  if (card.classList.contains('storage-sideways-member')) {
    const pile = referencePileCardSize(layoutPrefix);
    inner.style.width = `${pile?.cw ?? h * (63 / 88)}px`;
    inner.style.height = `${pile?.ch ?? h}px`;
  } else {
    inner.style.width = `${w}px`;
    inner.style.height = `${h}px`;
  }
}

function layoutStageSlots(prefix) {
  for (const slot of ['left', 'center', 'right']) {
    const wrap = el(`${prefix}-stage-${slot}`);
    const mslot = wrap?.querySelector('.mslot');
    if (!wrap || !mslot) continue;
    const fit = fitStageMemberCardSize(wrap.clientWidth, wrap.clientHeight);
    mslot.style.width = fit.cw + 'px';
    mslot.style.height = fit.ch + 'px';
    mslot.style.flex = '0 0 auto';
    mslot.style.maxWidth = '';
    if (mslot.classList.contains('stage-flip')) syncStageFlipInnerSize(mslot, fit);
    if (typeof layoutMemberUnderStack === 'function') layoutMemberUnderStack(mslot);
  }
}

function layoutLiveSlots(prefix) {
  layoutDeckPiles();
  const myId = G.playerId || 'p1';
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  const pid = prefix === 'my' ? myId : oppId;
  const zone = G.gameState?.players?.[pid]?.live_zone || [];
  for (let i = 0; i < 3; i++) {
    const zoneEl = el(`${prefix}-live-${i}`);
    if (!zoneEl) continue;
    const wrap = zoneEl.querySelector('.live-slot-wrap');
    const card = wrap?.querySelector('.lcard.live-card');
    if (!card || !wrap) continue;
    wrap.style.transform = '';
    const w = zoneEl.clientWidth;
    const h = zoneEl.clientHeight;
    if (w < 4 || h < 4) continue;
    const fit = fitLiveStorageFieldSize(prefix, w, h);
    card.style.width = fit.cw + 'px';
    card.style.height = fit.ch + 'px';
    card.style.flex = '0 0 auto';
    if (card.classList.contains('live-storage-flip')) syncLiveStorageFlipInnerSize(card, fit, prefix);
  }
}

function renderSuccessLives(id, succs) {
  const e = el(id);
  if (!e) return;
  e.innerHTML = '';
  const stack = document.createElement('div');
  stack.className = 'success-live-stack';
  (succs || []).forEach(card => {
    const wrap = document.createElement('div');
    wrap.className = 'slive-chip';
    const sc = enrichCard(card);
    wrap.dataset.iid = sc.instance_id;
    const d = document.createElement('div');
    d.className = 'lcard live-card';
    appendCardFace(d, sc, { sideways: liveStorageUseArtSpin(sc) });
    wrap.appendChild(d);
    wrap.title = sc.name_en || sc.name || 'Live Success';
    wrap.onclick = typeof boardCardShowClickHandler === 'function'
      ? boardCardShowClickHandler(sc, G.gameState, G.playerId)
      : () => showCard(sc, null, G.gameState, G.playerId);
    bindSuccessLiveHover(wrap, sc, G.gameState, G.playerId);
    if (typeof bindBoardCardLongPressInspect === 'function') {
      bindBoardCardLongPressInspect(wrap, sc, G.gameState, G.playerId);
    }
    stack.appendChild(wrap);
  });
  e.appendChild(stack);
  requestAnimationFrame(() => layoutSuccessLiveStack(stack));
}

function renderLiveSlots(prefix, zone, isMe, pid){
  const isOpp = prefix === 'opp';
  const flipKeys = liveStorageFlipKeysForRender(G.gameState, G._liveRevealFlips || new Set());
  const s = G.gameState;
  const myId = G.playerId;
  const hiddenSpectate = typeof spectatorHandsHidden === 'function' && spectatorHandsHidden();
  const stagePreview = isMe && !hiddenSpectate && liveStorageUsesBaseScorePreview(s);
  const stageBonus = stagePreview ? stageLiveScoreBonusFor(s, pid, myId) : 0;
  const lastScoredLiveSlot = stagePreview && stageBonus > 0 ? liveZoneLastScoredLiveSlotIndex(zone) : -1;
  for(let i=0;i<3;i++){
    const e=el(`${prefix}-live-${i}`);
    if(!e) continue;
    const card = liveZoneCardAtSlot(zone, i);
    const existingWrap = e.querySelector('.live-slot-wrap');
    const existingCard = existingWrap?.querySelector('.lcard.live-card');

    // A held pre-outcome snapshot can be repainted while a newer state is
    // committing. Once a flight has handed off, never recreate that source.
    const slotIid = card?.instance_id || existingCard?.dataset?.iid;
    if (slotIid && (
      (typeof liveStorageDepartureLatched === 'function'
        ? liveStorageDepartureLatched(slotIid, s?.turn ?? s?.live_show?.turn)
        : G._liveStorageDepartedIids?.has(String(slotIid)))
    )) {
      e.innerHTML = '';
      continue;
    }

    if (!card) {
      const ghostIid = existingCard?.dataset?.iid || existingCard?.parentElement?.dataset?.iid;
      if (ghostIid && typeof liveStorageDepartureLatched === 'function'
          && liveStorageDepartureLatched(ghostIid, s?.turn ?? s?.live_show?.turn)) {
        e.innerHTML = '';
        continue;
      }
      // Mid-reveal / WR flight may still own the shell; otherwise ghost flips from the
      // previous Live round survive into Main when the server zone is already empty.
      const flipBusy = existingCard?.classList.contains('live-storage-flip')
        && (G._liveStorageRevealRunning
          || ((G._liveStorageRevealAnimCount || 0) > 0)
          || (G._liveRoundPlaybackActive && G._liveRevealFlips?.size));
      if (flipBusy && !(existingCard?.dataset?.iid && liveStorageDepartureLatched(existingCard.dataset.iid))) continue;
      if (existingCard?.classList.contains('card-arriving')) {
        const arrIid = existingCard?.dataset?.iid;
        if (arrIid && typeof liveStorageDepartureLatched === 'function'
            && liveStorageDepartureLatched(arrIid, s?.turn ?? s?.live_show?.turn)) {
          e.innerHTML = '';
          continue;
        }
        if (G._liveWrDiscardInProgress) continue;
      }
      e.innerHTML='';
      continue;
    }

    const flipKey = `${pid}:${card.instance_id}`;
    const flipPending = flipKeys.has(flipKey)
      && !(isLiveSetPhase(s?.phase) && liveSetPlacementInProgress(s))
      && (hiddenSpectate || !isMe);
    const hideOppFace = !isMe && !flipPending && shouldHideOpponentLiveStorageFaces(s);
    // Open spectate broadcasts faces; hidden-hand tournament view follows revealed,
    // except the current reveal beat stays face-down until the flip animation owns it.
    const spectatorBroadcastFace = G.isSpectator && !hiddenSpectate
      && card.card_no && card.card_no !== '?';
    const revealBeatKey = (hiddenSpectate && s?.live_show?.stage === 'reveal' && s.live_show?.performer)
      ? `${s.live_show.turn}:${s.live_show.stage_seq}:reveal:${s.live_show.performer}`
      : null;
    const pendingHiddenReveal = !!(revealBeatKey
      && pid === s.live_show.performer
      && !G._spectatorHiddenRevealBeats?.has(revealBeatKey));
    const showFace = spectatorBroadcastFace
      || (!hiddenSpectate && isMe)
      || flipPending
      || (hiddenSpectate
        ? (!pendingHiddenReveal && !!card.revealed)
        : (!hideOppFace && !!card.revealed));
    const doFlip = flipPending;
    const canFlipSeat = hiddenSpectate || isOpp;

    if (existingCard?.dataset.iid != null && String(existingCard.dataset.iid) === String(card.instance_id)) {
      if (existingCard.classList.contains('live-storage-flip')) {
        existingCard.classList.toggle('card-arriving', !!(G._animHideIids?.has(card.instance_id) || G._animHideIids?.has(String(card.instance_id))));
        // Outside the reveal sequence, collapse leftover flip shells (never re-arm).
        if (typeof shouldSuppressLiveStorageFlipsNow === 'function' && shouldSuppressLiveStorageFlipsNow(s)) {
          if (settleLiveStorageFlipCard(existingCard, card)) {
            applyCardFoilFx(existingCard, card);
          }
          continue;
        }
        const flipActive = doFlip || G._liveFlipScheduled?.has(flipKey);
        if (!flipActive && settleStaleLiveStorageFlipCard(existingCard, card, s, flipKeys, flipKey)) {
          applyCardFoilFx(existingCard, card);
          continue;
        }
        if (!existingCard.classList.contains('revealed') && flipActive) {
          ensureLiveStorageFlipScheduled(
            existingCard, card, flipKey, flipKeys, i * LIVE_STORAGE_FLIP_STAGGER_MS, prefix
          );
        }
        continue;
      }
      if (doFlip && canFlipSeat && existingCard.classList.contains('live-storage-facedown')) {
        upgradeLiveStorageCardForReveal(existingCard, card);
        layoutLiveSlots(prefix);
        ensureLiveStorageFlipScheduled(
          existingCard, card, flipKey, flipKeys, i * LIVE_STORAGE_FLIP_STAGGER_MS, prefix
        );
        continue;
      }
      if (existingCard.classList.contains('card-arriving')) {
        continue;
      }
      if (doFlip && canFlipSeat && liveStorageCardShowsFace(existingCard)
          && !existingCard.classList.contains('live-storage-flip')) {
        // Face-up outside active reveal playback: do not rebuild a flip shell (causes
        // "already revealed / never-played" cards to flip again during Main).
        if (!G._liveRoundPlaybackActive) {
          existingCard.classList.toggle('card-arriving', !!(G._animHideIids?.has(card.instance_id) || G._animHideIids?.has(String(card.instance_id))));
          applyCardFoilFx(existingCard, card);
          continue;
        }
        rebuildLiveStorageFlipFromFace(existingCard, card);
        layoutLiveSlots(prefix);
        ensureLiveStorageFlipScheduled(
          existingCard, card, flipKey, flipKeys, i * LIVE_STORAGE_FLIP_STAGGER_MS, prefix
        );
        continue;
      }
      if (!flipPending && !G._liveFlipScheduled?.has(flipKey) && liveStorageCardShowsFace(existingCard)) {
        existingCard.classList.toggle('card-arriving', !!(G._animHideIids?.has(card.instance_id) || G._animHideIids?.has(String(card.instance_id))));
        applyCardFoilFx(existingCard, card);
        continue;
      }
      if (canFlipSeat && !card.revealed && existingCard.classList.contains('live-storage-facedown') && !doFlip) {
        existingCard.classList.toggle('card-arriving', !!(G._animHideIids?.has(card.instance_id) || G._animHideIids?.has(String(card.instance_id))));
        continue;
      }
      // Hidden spectate: keep face-down shell until this performer's reveal.
      if (hiddenSpectate && !showFace && existingCard.classList.contains('live-storage-facedown') && !doFlip) {
        existingCard.classList.toggle('card-arriving', !!(G._animHideIids?.has(card.instance_id) || G._animHideIids?.has(String(card.instance_id))));
        continue;
      }
    }

    e.innerHTML='';
    const outer = document.createElement('div');
    outer.className = 'live-slot-wrap' + (isOpp ? ' opp' : '');
    const d=document.createElement('div'); d.className='lcard live-card';
    d.dataset.iid = card.instance_id;
    if (!showFace) {
      d.classList.add('live-storage-facedown');
      const shell = document.createElement('div');
      shell.className = 'live-facedown-shell';
      const backFace = document.createElement('div');
      backFace.className = 'live-flip-face live-flip-back';
      appendLiveStorageBack(backFace, card);
      shell.appendChild(backFace);
      d.appendChild(shell);
    } else if (doFlip) {
      d.classList.add('live-storage-flip');
      applyLiveStorageMemberSpin(d, card);
      d.appendChild(buildLiveStorageFlipInner(card));
      layoutLiveSlots(prefix);
      ensureLiveStorageFlipScheduled(d, card, flipKey, flipKeys, i * LIVE_STORAGE_FLIP_STAGGER_MS, prefix);
      d.onclick = G.isSpectator ? null : (
        typeof boardCardShowClickHandler === 'function'
          ? boardCardShowClickHandler(card, G.gameState, isMe ? G.playerId : pid)
          : () => showCard(card, null, G.gameState, isMe ? G.playerId : pid)
      );
    } else {
      appendLiveStorageFace(d, card);
      d.onclick = G.isSpectator ? null : (
        typeof boardCardShowClickHandler === 'function'
          ? boardCardShowClickHandler(card, G.gameState, isMe ? G.playerId : pid)
          : () => showCard(card, null, G.gameState, isMe ? G.playerId : pid)
      );
    }
    d.classList.toggle('card-arriving', !!(G._animHideIids?.has(card.instance_id)));
    if(showFace) applyCardFoilFx(d, card);
    if (showFace && !G.isSpectator && typeof bindBoardCardLongPressInspect === 'function') {
      bindBoardCardLongPressInspect(d, card, G.gameState, isMe ? G.playerId : pid);
    }
    outer.appendChild(d);
    if(showFace && card.score != null && ((!hiddenSpectate && isMe) || card.revealed)){
      const extraBonus = stagePreview && i === lastScoredLiveSlot ? stageBonus : 0;
      appendLiveScoreBadge(outer, card, { baseOnly: stagePreview, extraBonus });
    }
    e.appendChild(outer);
  }
  layoutLiveSlots(prefix);
  requestAnimationFrame(() => {
    layoutDeckPiles();
    layoutLiveSlots(prefix);
  });
}

function renderLog(log, prevLen = 0){
  const e=el('game-log');
  if (!e) return;
  const logArr = log || [];
  let addedAnimated = false;

  let clientTail = countClientOnlyTailAfterState(e, logArr.length);
  let matchedLen = e.children.length - clientTail;

  if (matchedLen > logArr.length) {
    let guard = e.children.length + 2;
    while (e.children.length > logArr.length + clientTail && guard-- > 0) {
      const node = e.children[logArr.length];
      if (!node) break;
      node.remove();
    }
    clientTail = countClientOnlyTailAfterState(e, logArr.length);
    matchedLen = e.children.length - clientTail;
  }

  if (matchedLen > 0 && logArr.length > 0 && !logDomPrefixMatches(logArr, matchedLen)) {
    let syncAt = 0;
    for (let i = 0; i < matchedLen && i < logArr.length; i++) {
      if (logDomMatchesEntry(e.children[i], logArr[i])) syncAt = i + 1;
      else break;
    }
    let guard = e.children.length + 2;
    while (e.children.length > syncAt + clientTail && guard-- > 0) {
      const node = e.children[syncAt];
      if (!node) break;
      node.remove();
    }
    clientTail = countClientOnlyTailAfterState(e, logArr.length);
    matchedLen = syncAt;
  }

  const batchStart = Math.max(matchedLen, prevLen);
  for (let i = matchedLen; i < logArr.length; i++) {
    if (i === matchedLen) absorbOpponentSkillWaitClientLog(e, logArr[i]);
    if (i === matchedLen && absorbMatchingClientLogTail(e, logArr[i])) continue;
    const animate = i >= batchStart;
    if (animate) addedAnimated = true;
    e.appendChild(createLogLineElement(logArr[i], {
      animate,
      animDelay: animate ? (i - batchStart) * 55 : 0,
    }));
  }
  maybeScrollGameLog(e, { animated: addedAnimated });
}

// ── CARD DETAIL / HOVER / DRAG (constants; handlers remain in index.html) ──
const LONG_PRESS_MS = 480;
const DRAG_THRESHOLD = 10;

/** Immutable zone snapshot for <ll-*> web components. */
function llBoardViewModel(state, myId) {
  const me = state?.players?.[myId] || {};
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  const opp = state?.players?.[oppId] || {};
  return {
    seq: state?.seq ?? 0,
    phase: state?.phase || '',
    myId,
    oppId,
    myStage: me.stage || {},
    oppStage: opp.stage || {},
    myHand: me.hand || [],
    myLive: me.live_zone || [],
    oppLive: opp.live_zone || [],
    log: state?.log || [],
  };
}

function llRenderGame(state, opts) {
  return renderGame(state, opts);
}
