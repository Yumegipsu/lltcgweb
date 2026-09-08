/**
 * Prompt UI: submit guards, pick openers, renderPrompt, choice handler.
 */
(function (global) {
  'use strict';

  function pt(key, vars) {
    var i18n = global.LLTCG_I18N;
    return i18n && typeof i18n.t === 'function' ? i18n.t(key, vars) : key;
  }

  /** Mirrors PHP handMembersMatchingPlayAbility; honors any_group (Maki PR-015). */
  global.handMembersMatchingPlayAbilityClient = function handMembersMatchingPlayAbilityClient(hand, ab, candidates) {
    const list = Array.isArray(hand) ? hand : [];
    const abs = ab && typeof ab === 'object' ? ab : {};
    const names = abs.names || [];
    const anyGroup = !!abs.any_group;
    const grp = abs.group || 'Nijigasaki';
    const maxCost = abs.max_cost ?? 4;
    const candIds = new Set((candidates || []).map(c => c && c.instance_id).filter(Boolean));
    if (candIds.size) {
      const matched = list.filter(c => c && candIds.has(c.instance_id));
      if (matched.length) return matched;
      const fromPrompt = (candidates || []).filter(c => c && c.instance_id);
      if (fromPrompt.length) return fromPrompt;
    }
    return list.filter(c => {
      if (!c || c.card_type !== 'メンバー') return false;
      if ((c.cost || 0) > maxCost) return false;
      if (names.length) {
        const label = c.name_en || c.name || '';
        return names.some(n => label === n || label.includes(n));
      }
      if (anyGroup) return true;
      return (c.group || '') === grp;
    });
  };

  global.promptIdentityKey = function promptIdentityKey(s) {
    const pr = s?.pending_prompt;
    if (!pr || !s) return null;
    const src = pr.source_id || pr.card_instance_id || pr.source_instance_id || '';
    const abIdx = pr.ability_index ?? '';
    return `${s.seq}:${pr.type}:${pr.step ?? ''}:${pr.responder ?? ''}:${src}:${abIdx}`;
  };

  /**
   * Seq-free identity for "already answered this skill this turn".
   * Seq bumps after resolve must not reopen the same picker (force-apply / poll races).
   */
  global.promptLogicalKey = function promptLogicalKey(s) {
    const pr = s?.pending_prompt;
    if (!pr || !s) return null;
    const src = pr.source_id || pr.card_instance_id || pr.source_instance_id || '';
    const abIdx = pr.ability_index ?? '';
    const turn = s.turn ?? '';
    // Order prompts have empty source_id — include candidate ids so a real server
    // reopen after dismiss/default-order is not treated as "already answered" (#165).
    let orderIds = '';
    if (pr.type === 'live_success_order_sources' || pr.type === 'live_start_order_sources') {
      orderIds = (pr.candidates || []).map((c) => c?.instance_id).filter(Boolean).slice().sort().join(',');
    }
    return `${turn}:${pr.type}:${pr.step ?? ''}:${pr.responder ?? ''}:${src}:${abIdx}:${orderIds}`;
  };

  global.promptSubmitKey = function promptSubmitKey(s) {
    return global.promptIdentityKey(s);
  };

  global.markPromptSubmitting = function markPromptSubmitting(s) {
    const snap = s || global.G.gameState;
    const key = global.promptSubmitKey(snap);
    global.G._promptSubmitKey = key;
    // Remember answered identity so ensurePendingPromptSurfaced / renderPrompt cannot
    // reopen it after the overlay closes (stale gate-entry / force-apply races).
    const logical = global.promptLogicalKey(snap);
    if (logical) global.G._lastResolvedPromptKey = logical;
  };

  global.syncPromptSubmitState = function syncPromptSubmitState(s) {
    const pr = s?.pending_prompt;
    if (!pr) {
      // Prompt cleared — keep logical _lastResolvedPromptKey so a stale same-turn
      // snapshot cannot reopen after force-apply / dismiss cleared the submit latch.
      global.G._promptSubmitKey = null;
      global.G._resolvePromptSentKey = null;
      global.G._lastSurfacedPromptKey = null;
      if (global.G._deferredPromptState?.pending_prompt) global.clearDeferredPromptState();
      return;
    }
    const idKey = global.promptIdentityKey(s);
    const logical = global.promptLogicalKey(s);
    // Same logical skill still pending (seq-only bump or slow ack) — keep locks.
    if (logical && global.G._lastResolvedPromptKey === logical) return;
    if (global.G._promptSubmitKey && idKey === global.G._promptSubmitKey) return;
    if (!global.G._promptSubmitKey && !global.G._lastResolvedPromptKey) return;
    // Different skill/step — allow the next picker (Ginko yes→WR, chained Live Start).
    global.G._lastResolvedPromptKey = null;
    global.G._promptSubmitKey = null;
    global.G._resolvePromptSentKey = null;
    global.G._lastSurfacedPromptKey = null;
  };

  global.isPromptSubmitting = function isPromptSubmitting(s) {
    if (!global.G._promptSubmitKey) return false;
    const key = global.promptSubmitKey(s);
    if (key && key === global.G._promptSubmitKey) return true;
    return false;
  };

  global.isPromptAlreadyResolved = function isPromptAlreadyResolved(s) {
    const logical = global.promptLogicalKey(s);
    return !!(logical && global.G._lastResolvedPromptKey === logical);
  };

  global.suppressPromptOverlaysWhileSubmitting = function suppressPromptOverlaysWhileSubmitting() {
    global.el('overlay-prompt')?.classList.remove('open');
    global.closeM('overlay-hand-pick');
    global.closeM('overlay-pick');
    global.closeM('overlay-heart');
  };

  const REPLAY_PROMPT_OVERLAY_IDS = [
    'overlay-prompt',
    'overlay-hand-pick',
    'overlay-pick',
    'overlay-heart',
    'overlay-surveil',
  ];

  function isReplayPromptReadOnlyState(s) {
    if (typeof global.isReplayViewing !== 'function' || !global.isReplayViewing()) {
      return false;
    }
    return !!s?.pending_prompt;
  }

  function scheduleReplayPromptReadOnlyUi(readOnly) {
    setTimeout(() => global.syncReplayPromptReadOnlyUi?.(readOnly), 0);
  }

  global.syncReplayPromptReadOnlyUi = function syncReplayPromptReadOnlyUi(forceReadOnly) {
    const readOnly = forceReadOnly ?? isReplayPromptReadOnlyState(global.G?.gameState);
    REPLAY_PROMPT_OVERLAY_IDS.forEach((id) => {
      const overlay = global.el?.(id);
      if (!overlay) return;
      const active = !!(readOnly && overlay.classList.contains('open'));
      overlay.classList.toggle('replay-prompt-readonly', active);
      overlay.setAttribute('aria-readonly', active ? 'true' : 'false');
      overlay.querySelectorAll('button, input, select, textarea').forEach((node) => {
        if (active) {
          if (node.dataset.replayReadonlyWasDisabled == null) {
            node.dataset.replayReadonlyWasDisabled = node.disabled ? '1' : '0';
          }
          node.disabled = true;
          node.setAttribute('aria-disabled', 'true');
        } else if (node.dataset.replayReadonlyWasDisabled != null) {
          node.disabled = node.dataset.replayReadonlyWasDisabled === '1';
          delete node.dataset.replayReadonlyWasDisabled;
          node.removeAttribute('aria-disabled');
        }
      });
    });
  };

  global.syncAntiSoftlockButton = function syncAntiSoftlockButton(s, myId) {
  const btn = el('btn-anti-softlock');
  if (!btn) return;
  const onGame = el('screen-game')?.classList.contains('active');
  const replayViewing = typeof global.isReplayViewing === 'function' && global.isReplayViewing();
  const show = !!(onGame && !G.isSpectator && !replayViewing && hasAntiSoftlockTarget(s, myId));
  btn.hidden = !show;
}

global.mkPickCardEl = function mkPickCardEl(card, cls, onClick){
  const d=document.createElement('div');
  const live=isLiveCard(card);
  const portraitFrame=cls==='hand-pick-card';
  if(portraitFrame&&live){
    d.className=cls+' portrait card-live-hand';
  } else {
    d.className=cls+' '+menuCardClasses(card);
  }
  d.dataset.id=card.instance_id;
  appendCardFace(d, card, { sideways: live && portraitFrame });
  bindMenuCardPress(d, card, onClick);
  return d;
}

/** Reorder Live Success / Live Start sources (first → last). ↑/↓ rearrange; Confirm sends card_ids. */
global.openLiveSuccessOrderPick = function openLiveSuccessOrderPick(pr) {
  const cards = Array.isArray(pr.candidates) ? pr.candidates.slice() : [];
  const byId = {};
  cards.forEach((c) => {
    if (c?.instance_id) byId[c.instance_id] = c;
  });
  let order = cards.map((c) => c.instance_id).filter(Boolean);
  const isLiveStart = pr.type === 'live_start_order_sources';

  el('pick-ttl').textContent = promptDisplayTitle(pr, isLiveStart ? 'Live Start' : 'Live Success');
  el('pick-msg').textContent = promptDisplayText(pr, isLiveStart
      ? 'Choose the order to activate Live Start abilities (first → last).'
      : 'Choose the order to activate Live Success abilities (first → last).');
  const g = el('pick-grid');
  g.innerHTML = '';
  g.classList.add('pick-grid-order');

  const btnOk = el('btn-pick-ok');
  const btnCancel = el('btn-pick-cancel');
  if (btnOk) btnOk.style.display = 'inline-block';
  if (btnCancel) btnCancel.style.display = 'none';

  function syncMarkedFromOrder() {
    if (!G.pickMarked || typeof G.pickMarked.clear !== 'function') {
      G.pickMarked = new Set();
    }
    G.pickMarked.clear();
    order.forEach((id) => G.pickMarked.add(id));
  }

  function paint() {
    g.innerHTML = '';
    order.forEach((id, idx) => {
      const card = byId[id];
      if (!card) return;
      const wrap = document.createElement('div');
      wrap.className = 'pick-order-item';
      wrap.dataset.id = id;

      const badge = document.createElement('div');
      badge.className = 'pick-order-badge';
      badge.textContent = String(idx + 1);

      const cardEl = mkPickCardEl(card, 'pickcard', null);
      cardEl.classList.add('sel');

      const controls = document.createElement('div');
      controls.className = 'pick-order-controls';
      const up = document.createElement('button');
      up.type = 'button';
      up.className = 'btn-ghost pick-order-btn';
      up.textContent = '↑';
      up.disabled = idx === 0;
      up.onclick = (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        if (idx <= 0) return;
        const tmp = order[idx - 1];
        order[idx - 1] = order[idx];
        order[idx] = tmp;
        paint();
      };
      const down = document.createElement('button');
      down.type = 'button';
      down.className = 'btn-ghost pick-order-btn';
      down.textContent = '↓';
      down.disabled = idx >= order.length - 1;
      down.onclick = (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        if (idx >= order.length - 1) return;
        const tmp = order[idx + 1];
        order[idx + 1] = order[idx];
        order[idx] = tmp;
        paint();
      };
      controls.appendChild(up);
      controls.appendChild(down);

      wrap.appendChild(badge);
      wrap.appendChild(cardEl);
      wrap.appendChild(controls);
      g.appendChild(wrap);
    });
    syncMarkedFromOrder();
    el('pick-count').textContent = order.length
      ? `Activation order: 1 → ${order.length}`
      : '';
  }

  G.pickCtx = {
    count: Math.max(1, order.length),
    min: Math.max(1, order.length),
    onConfirm: (ids) => sendAct('resolve_prompt', { card_ids: ids }),
  };
  paint();
  if (typeof syncPickOverlayButtons === 'function') syncPickOverlayButtons();
  openM('overlay-pick');
};


global.openSurveilPickOne = function openSurveilPickOne(pr){
  const cards=pr.look_cards||pr.candidates||[];
  openLookedDeckPick({
    ...pr,
    candidates: cards,
    pick_count: 1,
    eligible_ids: cards.map(c=>c.instance_id),
  });
}


global.openLookedDeckPick = function openLookedDeckPick(pr){
  if (pr.step === 'pick_destination') {
    el('pick-ttl').textContent = promptDisplayTitle(pr, 'Choose destination');
    el('pick-msg').textContent = promptDisplayText(pr, 'Add to hand or play to an empty Stage area?');
    const g = el('pick-grid');
    g.innerHTML = '';
    const handBtn = document.createElement('button');
    handBtn.className = 'btn-grad';
    handBtn.style.width = '100%';
    handBtn.textContent = pt('prompt.addToHand');
    handBtn.onclick = () => {
      closeM('overlay-pick');
      sendAct('resolve_prompt', { choice: 'hand' });
    };
    g.appendChild(handBtn);
    (pr.slots || []).forEach((slot) => {
      const b = document.createElement('button');
      b.className = 'btn-grad';
      b.style.width = '100%';
      b.style.marginTop = '8px';
      b.textContent = pt('prompt.playToSlot', { slot: slotLabel(slot) });
      b.onclick = () => {
        closeM('overlay-pick');
        sendAct('resolve_prompt', { choice: slot, slot });
      };
      g.appendChild(b);
    });
    openM('overlay-pick');
    return;
  }
  const cards=pr.candidates||[];
  const eligible=new Set(pr.eligible_ids||[]);
  const need=pr.pick_count||1;
  const optional=!!pr.optional;
  const distinctGroups=!!(pr.ability?.distinct_groups);
  const cardGroup=(c)=>String(c?.group||'');
  const noneEligible=eligible.size===0;
  const singleTap=need===1;
  const skipLabel=noneEligible
    ? pt('prompt.noMatchAllWaitingRoom')
    : pt('prompt.skipAllWaitingRoom');
  el('pick-ttl').textContent=promptDisplayTitle(pr, 'Choose from deck');
  el('pick-msg').textContent=promptDisplayText(pr, noneEligible
    ? 'No matching cards among these. Confirm to put them into the Waiting Room.'
    : (distinctGroups
      ? `Choose up to ${need} card(s) with different group names to add to your hand.`
      : 'Choose card(s) to add to your hand.'));
  const g=el('pick-grid'); g.innerHTML='';
  g.classList.remove('pick-grid-order');
  const btnOk=el('btn-pick-ok');
  const btnCancel=el('btn-pick-cancel');
  if(btnOk) btnOk.style.display=singleTap?'none':'inline-block';
  if(btnCancel) btnCancel.style.display=singleTap?'none':'inline-block';
  if(singleTap){
    G.pickCtx=null;
    cards.forEach(card=>{
      const ok=eligible.has(card.instance_id);
      const elCard=mkPickCardEl(card,'pickcard',()=>{
        if(!ok) return;
        closeM('overlay-pick');
        sendAct('resolve_prompt',{card_id:card.instance_id});
      });
      if(!ok) elCard.classList.add('ineligible');
      g.appendChild(elCard);
    });
    if(optional){
      const skipBtn=document.createElement('button');
      skipBtn.className=noneEligible?'btn-grad':'btn-ghost';
      skipBtn.style.width='100%'; skipBtn.style.marginTop='10px';
      skipBtn.textContent=skipLabel;
      skipBtn.onclick=()=>{
        closeM('overlay-pick');
        sendAct('resolve_prompt',{choice:'skip'});
      };
      g.appendChild(skipBtn);
    }
    el('pick-count').textContent='';
  }else{
    G.pickCtx={count:need,min:optional?0:need,onConfirm:(ids)=>sendAct('resolve_prompt',{card_ids:ids})};
    G.pickMarked.clear();
    cards.forEach(card=>{
      const ok=eligible.has(card.instance_id);
      const elCard=mkPickCardEl(card,'pickcard',()=>{
        if(!ok) return;
        if(G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
        else {
          if(G.pickMarked.size>=need){ toast(t('prompt.selectAtMost', { n: need })); return; }
          if(distinctGroups){
            const gName=cardGroup(card);
            if(!gName){ toast(t('prompt.cardNotEligible')||'That card has no group name.'); return; }
            const clash=[...G.pickMarked].some(id=>{
              const other=cards.find(c=>c.instance_id===id);
              return other && cardGroup(other)===gName;
            });
            if(clash){ toast(t('prompt.onePerGroup')||'Only 1 card per group name.'); return; }
          }
          G.pickMarked.add(card.instance_id);
          sfxCardPick();
        }
        [...g.children].forEach(c=>{
          if(c.classList?.contains('pickcard'))
            c.classList.toggle('sel',G.pickMarked.has(c.dataset.id));
        });
        el('pick-count').textContent=formatSelectedCount(G.pickMarked.size, need);
      });
      if(!ok) elCard.classList.add('ineligible');
      g.appendChild(elCard);
    });
    if(optional){
      const skipBtn=document.createElement('button');
      skipBtn.className=noneEligible?'btn-grad':'btn-ghost';
      skipBtn.style.width='100%'; skipBtn.style.marginTop='10px';
      skipBtn.textContent=skipLabel;
      skipBtn.onclick=()=>{
        closeM('overlay-pick');
        G.pickCtx=null; G.pickMarked.clear();
        sendAct('resolve_prompt',{choice:'skip'});
      };
      g.appendChild(skipBtn);
    }
    el('pick-count').textContent=formatSelectedCount(0, need);
    syncPickOverlayButtons();
  }
  openM('overlay-pick');
}


global.openStageMemberPickById = function openStageMemberPickById(pr){
  const cards=pr.candidates||[];
  // Clear multi-pick leftovers (Live Start order Confirm/Cancel) so this click-to-pick
  // UI does not send empty / wrong payloads (#Honoka PL!-bp3-001 activate_members_pick).
  G.pickCtx=null;
  if(G.pickMarked&&typeof G.pickMarked.clear==='function') G.pickMarked.clear();
  const btnOk=el('btn-pick-ok');
  const btnCancel=el('btn-pick-cancel');
  if(btnOk) btnOk.style.display='none';
  if(btnCancel) btnCancel.style.display='none';
  el('pick-ttl').textContent=promptDisplayTitle(pr, pt('prompt.chooseMemberTitle'));
  el('pick-msg').textContent=promptDisplayText(pr, 'Choose 1 Member on your Stage.');
  const g=el('pick-grid'); g.innerHTML='';
  g.classList.remove('pick-grid-order');
  cards.forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      closeM('overlay-pick');
      // Server activate_members_pick prefers member_id; keep card_id for older prompt types
      // that still read card_id (blade_per_discarded_pick_member, etc.).
      sendAct('resolve_prompt',{
        member_id:card.instance_id,
        card_id:card.instance_id,
        slot:card.slot||undefined,
      });
    }));
  });
  // "Up to N" activate picks (Honoka Live Start) — allow decline.
  if(pr.type==='activate_members_pick' || pr.optional || (Array.isArray(pr.choices)&&pr.choices.includes('skip'))){
    const skipBtn=document.createElement('button');
    skipBtn.className='btn-ghost';
    skipBtn.style.width='100%';
    skipBtn.style.marginTop='10px';
    skipBtn.textContent=(typeof t==='function' ? (t('prompt.skip') || 'Skip') : 'Skip');
    skipBtn.onclick=()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{choice:'skip'});
    };
    g.appendChild(skipBtn);
  }
  el('pick-count').textContent='';
  openM('overlay-pick');
}


global.openStageSlotPick = function openStageSlotPick(pr){
  const step=pr.step||'pick_slot';
  const cards=(pr.candidates||[]).filter(c=>{
    if(step==='pick_named') return !!c.named;
    // Second pick: any other Stage Member (including other named targets), not "non-named only".
    if(step==='pick_other') {
      const first = pr.first_slot || '';
      return !!(c && c.slot && c.slot !== first);
    }
    return !!(c.slot);
  });
  // Softlock guard: empty second-step picker (legacy servers / race) — dismiss UI; server should
  // have already skipped or finished the ability when no other Liella! Member exists (#68).
  if(step==='pick_other' && !cards.length
    && (pr.type==='pick_named_members_grant_blade' || pr.type==='pick_named_members_grant_hearts')){
    closeM('overlay-pick');
    G.pickCtx=null;
    if(typeof toast==='function'){
      toast(pt('prompt.noValidTargets') || 'No other valid Member on Stage.', 2800);
    }
    sendAct('resolve_prompt',{choice:'cancel'});
    return;
  }
  const maxPick = Number(pr.pick_count || 1);
  const upTo = !!pr.up_to || maxPick > 1;
  if(upTo && maxPick > 1){
    // One legal target for an "up to N" wait — resolve immediately (Eli discard→wait chain).
    if (cards.length === 1) {
      sendAct('resolve_prompt', { slots: [cards[0].slot] });
      return;
    }
    if (cards.length === 0) {
      sendAct('resolve_prompt', { slots: [] });
      return;
    }
    G.pickMarked.clear();
    const slotById = new Map(cards.map(c=>[c.instance_id, c.slot]));
    const isOppWaitPick = pr.type === 'wait_opponent_stage_pick';
    // When targets exist, Confirm must pick ≥1; use Cancel for "wait none" (min 0 alone allowed empty Confirm).
    const minSel = isOppWaitPick ? 1 : 0;
    G.pickCtx={
      count: maxPick,
      min: minSel,
      onConfirm: (ids)=>{
        const slots = ids.map(id=>slotById.get(id)).filter(Boolean);
        sendAct('resolve_prompt',{slots});
      },
      onCancel: ()=> sendAct('resolve_prompt',{slots:[]}),
    };
    el('pick-ttl').textContent=promptDisplayTitle(pr, pt('prompt.chooseMemberTitle'));
    el('pick-msg').textContent=promptDisplayText(pr, `Choose up to ${maxPick} Member(s).`);
    const g=el('pick-grid'); g.innerHTML='';
    const btnOk=el('btn-pick-ok');
    const btnCancel=el('btn-pick-cancel');
    if(btnOk) btnOk.style.display='';
    if(btnCancel) btnCancel.style.display='';
    cards.forEach(card=>{
      g.appendChild(mkPickCardEl(card,'pickcard',()=>{
        if(G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
        else {
          if(G.pickMarked.size>=maxPick){ toast(t('prompt.selectAtMost', { n: maxPick })); return; }
          G.pickMarked.add(card.instance_id);
          sfxCardPick();
        }
        [...g.children].forEach(c=>c.classList.toggle('sel',G.pickMarked.has(c.dataset.id)));
        el('pick-count').textContent=formatSelectedCount(G.pickMarked.size, maxPick);
      }));
    });
    el('pick-count').textContent=formatSelectedCount(0, maxPick);
    syncPickOverlayButtons();
    openM('overlay-pick');
    return;
  }
  el('pick-ttl').textContent=promptDisplayTitle(pr, pt('prompt.chooseMemberTitle'));
  el('pick-msg').textContent = pr?.type === 'pos_change_opp_front_pick'
    ? pt('prompt.posChangeOppFront')
    : promptDisplayText(pr, 'Choose a Member on your Stage.');
  const g=el('pick-grid'); g.innerHTML='';
  const btnOk=el('btn-pick-ok');
  const btnCancel=el('btn-pick-cancel');
  if(btnOk) btnOk.style.display='none';
  if(btnCancel) btnCancel.style.display='none';
  G.pickCtx=null;
  cards.forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{slot:card.slot});
    }));
  });
  // COMPASS / other optional stage picks — allow decline (card text is "you may").
  if (pr.optional || pr.type === 'live_start_activate_stage_live_start_ability'
      || (Array.isArray(pr.choices) && pr.choices.includes('skip'))) {
    const skipBtn=document.createElement('button');
    skipBtn.className='btn-ghost';
    skipBtn.style.width='100%';
    skipBtn.style.marginTop='10px';
    skipBtn.textContent=(typeof t==='function' ? (t('prompt.skip') || 'Skip') : 'Skip');
    skipBtn.onclick=()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{choice:'skip'});
    };
    g.appendChild(skipBtn);
  }
  el('pick-count').textContent='';
  openM('overlay-pick');
}


global.openWrToHandPick = function openWrToHandPick(pr, opts = {}) {
  // Missing pick_count means "pick 1" (Rina / Ayumu WR prompts). Only auto-skip when
  // pick_count is explicitly 0 (leave-stage prompts with nothing to pick).
  const need = pr.pick_count == null ? 1 : Number(pr.pick_count);
  if (need <= 0) {
    sendAct('resolve_prompt', { card_id: 'NO_CARD_NEEDED' });
    return;
  }
  const s = opts.state || G.gameState;
  const myId = opts.myId || G.playerId;
  const wrOwner = (typeof wrPickOwnerId === 'function')
    ? wrPickOwnerId(pr, myId)
    : ((pr?.target === 'p1' || pr?.target === 'p2') ? pr.target : myId);
  const cfg = wrPickCfgFromPrompt(pr);
  // Live WR only — never fall back to stale prompt.candidates after a deck refresh.
  // wrOwner follows pr.target so opponent-WR skills (Hanamaru PL!S-bp3-007) resolve.
  let cards = wrToHandPickCards(pr, s, myId).filter(c => cardMatchesWrPickClient(c, cfg));
  if (!cards.length && (pr.candidates || []).length) {
    // Server listed candidates that match cfg but client filter rejected (punctuation).
    // Still require the card to be in the targeted player's Waiting Room.
    const wrIds = new Set((s?.players?.[wrOwner]?.waiting_room || []).map(c => c.instance_id).filter(Boolean));
    cards = wrToHandPickCards(pr, s, myId).filter(c => wrIds.has(c.instance_id));
    if (!cards.length) {
      // Last resort: show server candidates (avoids softlock when WR owner was wrong).
      cards = (pr.candidates || []).map(c => (typeof enrichCard === 'function' ? enrichCard(c) : c))
        .filter(c => c?.instance_id);
    }
  }
  if (!cards.length) {
    // Never leave a mandatory WR pick hanging (Ginko PB1 second step softlock).
    const fallbackId = (pr.candidates || []).map(c => c?.instance_id).find(Boolean);
    closeM('overlay-pick');
    G.pickCtx = null;
    if (fallbackId && !isPromptSubmitting(s)) {
      toast(pt('prompt.wrNoMatch') || pt('prompt.wrEmpty'), 2200);
      sendAct('resolve_prompt', { card_id: fallbackId });
      return;
    }
    if (!isPromptSubmitting(s)) {
      toast(pt('prompt.wrNoMatch') || pt('prompt.wrEmpty'), 3200);
      sendAct('anti_softlock_skip', {});
    }
    return;
  }
  // Explicit up_to wins; legacy multi-pick without the flag is "up to N".
  const upTo = Object.prototype.hasOwnProperty.call(pr || {}, 'up_to')
    ? !!pr.up_to
    : (need > 1);
  el('pick-ttl').textContent = promptDisplayTitle(pr, pt('prompt.wrPickTitle'), s);
  el('pick-msg').textContent = promptDisplayText(pr, 'prompt.wrPickMsg', s);
  const g = el('pick-grid');
  g.innerHTML = '';
  const onCancel = opts.onCancel || (upTo
    ? () => sendAct('resolve_prompt', { card_ids: [] })
    : null);
  G.pickCtx = onCancel ? { onCancel } : null;
  const btnOk = el('btn-pick-ok');
  const btnCancel = el('btn-pick-cancel');
  const serverIds = new Set((pr.candidates || []).map(c => c.instance_id).filter(Boolean));
  if (need === 1 && !upTo) {
    if (btnOk) btnOk.style.display = 'none';
    if (btnCancel) btnCancel.style.display = onCancel ? '' : 'none';
    cards.forEach(card => {
      const ok = cardMatchesWrPickClient(card, cfg) || serverIds.has(card.instance_id);
      const elCard = mkPickCardEl(card, 'pickcard', () => {
        if (!ok || isPromptSubmitting(s)) return;
        closeM('overlay-pick');
        G.pickCtx = null;
        sendAct('resolve_prompt', { card_id: card.instance_id });
      });
      if (!ok) elCard.classList.add('ineligible');
      g.appendChild(elCard);
    });
    el('pick-count').textContent = '';
    openM('overlay-pick');
    return;
  }
  // Multi / up-to-N: toggle select, Confirm sends card_ids (may be empty when upTo).
  G.pickMarked.clear();
  G.pickCtx = {
    count: need,
    min: upTo ? 0 : need,
    onConfirm: (ids) => sendAct('resolve_prompt', { card_ids: ids }),
    ...(onCancel ? { onCancel } : {}),
  };
  if (btnOk) btnOk.style.display = '';
  if (btnCancel) btnCancel.style.display = onCancel ? '' : 'none';
  cards.forEach(card => {
    const ok = cardMatchesWrPickClient(card, cfg) || serverIds.has(card.instance_id);
    const elCard = mkPickCardEl(card, 'pickcard', () => {
      if (!ok || isPromptSubmitting(s)) return;
      if (G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
      else {
        if (G.pickMarked.size >= need) { toast(t('prompt.selectAtMost', { n: need })); return; }
        G.pickMarked.add(card.instance_id);
        sfxCardPick();
      }
      [...g.children].forEach(c => {
        if (c.classList?.contains('pickcard'))
          c.classList.toggle('sel', G.pickMarked.has(c.dataset.id));
      });
      el('pick-count').textContent = formatSelectedCount(G.pickMarked.size, need);
    });
    if (!ok) elCard.classList.add('ineligible');
    g.appendChild(elCard);
  });
  el('pick-count').textContent = formatSelectedCount(0, need);
  syncPickOverlayButtons();
  openM('overlay-pick');
}


global.openYellRevealPick = function openYellRevealPick(pr, opts = {}) {
  const s = opts.state || G.gameState;
  const myId = opts.myId || G.playerId;
  const cards = yellRevealPickCards(pr, s, myId);
  const onCancel = opts.onCancel;
  if (!cards.length) {
    if (onCancel) {
      onCancel();
      return;
    }
    const autoSkip = pr?.type === 'pick_yell_member'
      || pr?.type === 'live_success_pick_yell_live'
      || pr?.type === 'live_success_pick_yell_deck_top'
      || pr?.type === 'live_success_yell_live_deck_bottom';
    if (autoSkip && !isPromptSubmitting(s)) {
      sendAct('resolve_prompt', { choice: 'skip' });
      return;
    }
    toast(pt('prompt.yellNoCards'), 3200);
    return;
  }
  el('pick-ttl').textContent = promptDisplayTitle(pr, pt('prompt.yellPickTitle'), s);
  el('pick-msg').textContent = promptDisplayText(pr, 'prompt.yellPickMsg', s);
  const g = el('pick-grid');
  g.innerHTML = '';
  G.pickCtx = onCancel ? { onCancel } : null;
  const btnOk = el('btn-pick-ok');
  const btnCancel = el('btn-pick-cancel');
  if (btnOk) btnOk.style.display = 'none';
  if (btnCancel) btnCancel.style.display = onCancel ? '' : 'none';
  cards.forEach(card => {
    g.appendChild(mkPickCardEl(card, 'pickcard', () => {
      closeM('overlay-pick');
      G.pickCtx = null;
      sendAct('resolve_prompt', { card_id: card.instance_id });
    }));
  });
  el('pick-count').textContent = '';
  openM('overlay-pick');
}


global.openJudgeSuccessLivePick = function openJudgeSuccessLivePick(pr, opts = {}) {
  const s = opts.state || G.gameState;
  const myId = opts.myId || G.playerId;
  if (s?.status === 'finished') return;
  if (typeof isPromptSubmitting === 'function' && isPromptSubmitting(s)) return;
  const cards = judgeSuccessLivePickCards(pr, s, myId);
  if (!cards.length) {
    toast(pt('prompt.noLiveSuccess'), 3200);
    return;
  }
  el('pick-ttl').textContent = promptDisplayTitle(pr, pt('prompt.successLivePickTitle'), s);
  el('pick-msg').textContent = promptDisplayText(pr, 'prompt.successLivePickMsg', s);
  const g = el('pick-grid');
  g.innerHTML = '';
  G.pickCtx = null;
  const btnOk = el('btn-pick-ok');
  const btnCancel = el('btn-pick-cancel');
  if (btnOk) btnOk.style.display = 'none';
  if (btnCancel) btnCancel.style.display = 'none';
  cards.forEach(card => {
    g.appendChild(mkPickCardEl(card, 'pickcard', () => {
      if (typeof isPromptSubmitting === 'function' && isPromptSubmitting(G.gameState || s)) return;
      if (typeof markPromptSubmitting === 'function') markPromptSubmitting(G.gameState || s);
      closeM('overlay-pick');
      G.pickCtx = null;
      sendAct('resolve_prompt', { card_id: card.instance_id });
    }));
  });
  el('pick-count').textContent = '';
  openM('overlay-pick');
}


global.openSuccessLiveAreaPick = function openSuccessLiveAreaPick(pr, opts = {}) {
  const s = opts.state || G.gameState;
  const myId = opts.myId || G.playerId;
  const pool = s?.players?.[myId]?.success_lives || [];
  const byId = new Map(pool.map(c => [c.instance_id, c]));
  const cards = (pr.candidates || []).map(c => {
    const full = byId.get(c.instance_id);
    return full ? { ...c, ...full } : c;
  }).filter(c => c.instance_id);
  if (!cards.length) {
    toast(pt('prompt.noCardsSuccessLive'), 3200);
    return;
  }
  el('pick-ttl').textContent = promptDisplayTitle(pr, pt('prompt.successLiveHandTitle'), s);
  el('pick-msg').textContent = promptDisplayText(pr, 'prompt.successLiveHandMsg', s);
  const g = el('pick-grid');
  g.innerHTML = '';
  G.pickCtx = null;
  const btnOk = el('btn-pick-ok');
  const btnCancel = el('btn-pick-cancel');
  if (btnOk) btnOk.style.display = 'none';
  if (btnCancel) btnCancel.style.display = 'none';
  cards.forEach(card => {
    g.appendChild(mkPickCardEl(card, 'pickcard', () => {
      closeM('overlay-pick');
      G.pickCtx = null;
      sendAct('resolve_prompt', { card_id: card.instance_id });
    }));
  });
  el('pick-count').textContent = '';
  openM('overlay-pick');
}


global.openLiveZonePick = function openLiveZonePick(pr, opts = {}) {
  const s = opts.state || G.gameState;
  const myId = opts.myId || G.playerId;
  const pool = s?.players?.[myId]?.live_zone || [];
  const byId = new Map(pool.map(c => [c.instance_id, c]));
  const cards = (pr.candidates || []).map(c => {
    const full = byId.get(c.instance_id);
    return full ? { ...c, ...full } : c;
  }).filter(c => c.instance_id);
  if (!cards.length) {
    toast(pt('prompt.noCardsInLive'), 3200);
    return;
  }
  el('pick-ttl').textContent = promptDisplayTitle(pr, 'Choose Live', s);
  el('pick-msg').textContent = promptDisplayText(pr, 'Choose 1 Live card in your Live.', s);
  const g = el('pick-grid');
  g.innerHTML = '';
  G.pickCtx = null;
  const btnOk = el('btn-pick-ok');
  const btnCancel = el('btn-pick-cancel');
  if (btnOk) btnOk.style.display = 'none';
  if (btnCancel) btnCancel.style.display = 'none';
  cards.forEach(card => {
    g.appendChild(mkPickCardEl(card, 'pickcard', () => {
      closeM('overlay-pick');
      G.pickCtx = null;
      sendAct('resolve_prompt', { card_id: card.instance_id });
    }));
  });
  el('pick-count').textContent = '';
  openM('overlay-pick');
}


global.openWrLivePick = function openWrLivePick(pr, opts = {}){
  openWrToHandPick(pr, opts);
}


global.openActivateWrMemberPick = function openActivateWrMemberPick(pr, opts = {}){
  openWrToHandPick(pr, opts);
}


global.openWrMembersDeckTopPick = function openWrMembersDeckTopPick(pr){
  const cards=pr.candidates||[];
  const need=pr.pick_count||2;
  const upTo = !!pr.up_to || /up to/i.test(String(pr.prompt || ''));
  const minPick = upTo ? 0 : need;
  G.pickCtx={count:need, min:minPick, onConfirm:(ids)=>sendAct('resolve_prompt',{card_ids:ids})};
  G.pickMarked.clear();
  el('pick-ttl').textContent=promptDisplayTitle(pr, pt('prompt.chooseMembersTitle'));
  el('pick-msg').textContent=promptDisplayText(pr, upTo
    ? `Choose up to ${need} Member card(s) from your Waiting Room (order = deck top).`
    : `Choose ${need} Member card(s) from your Waiting Room (order = deck top).`);
  const g=el('pick-grid'); g.innerHTML='';
  // Prior single-tap pickers hide Confirm; multi-select needs it visible again (#78).
  const btnOk=el('btn-pick-ok');
  const btnCancel=el('btn-pick-cancel');
  if(btnOk) btnOk.style.display='';
  if(btnCancel) btnCancel.style.display=upTo ? '' : 'none';
  if(btnCancel && upTo){
    btnCancel.onclick=()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{card_ids:[]});
    };
  }
  cards.forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      if(G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
      else {
        if(G.pickMarked.size>=need){ toast(t('prompt.selectAtMost', { n: need })); return; }
        G.pickMarked.add(card.instance_id);
        sfxCardPick();
      }
      [...g.children].forEach(c=>c.classList.toggle('sel',G.pickMarked.has(c.dataset.id)));
      el('pick-count').textContent=formatSelectedCount(G.pickMarked.size, need);
    }));
  });
  el('pick-count').textContent=formatSelectedCount(0, need);
  syncPickOverlayButtons();
  openM('overlay-pick');
}


global.openBatch99StackWrPick = function openBatch99StackWrPick(pr){
  const cards=pr.candidates||[];
  el('pick-ttl').textContent=promptDisplayTitle(pr, 'Stack Member');
  el('pick-msg').textContent=promptDisplayText(pr, 'Choose a Member from your Waiting Room to stack under this Member.');
  const g=el('pick-grid'); g.innerHTML='';
  const btnOk=el('btn-pick-ok');
  const btnCancel=el('btn-pick-cancel');
  if(btnOk) btnOk.style.display='none';
  if(btnCancel) btnCancel.style.display='none';
  G.pickCtx=null;
  cards.forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{pick_id:card.instance_id});
    }));
  });
  const skipBtn=document.createElement('button');
  skipBtn.className='btn-ghost';
  skipBtn.style.width='100%'; skipBtn.style.marginTop='10px';
  skipBtn.textContent=pt('prompt.skip');
  skipBtn.onclick=()=>{
    closeM('overlay-pick');
    sendAct('resolve_prompt',{choice:'skip'});
  };
  g.appendChild(skipBtn);
  el('pick-count').textContent='';
  openM('overlay-pick');
}


global.openMemberWaitPick = function openMemberWaitPick(pr, myId){
  const max=pr.max_members||3;
  const min=pr.min_members??0;
  G.pickCtx={count:max, min, onConfirm:(ids)=>sendAct('resolve_prompt',{member_ids:ids})};
  G.pickMarked.clear();
  el('pick-ttl').textContent=promptDisplayTitle(pr, 'Wait Members');
  el('pick-msg').textContent=promptDisplayText(pr, `Choose up to ${max} Member(s) to put into Wait.`);
  const g=el('pick-grid'); g.innerHTML='';
  (pr.stage_members||[]).forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      if(G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
      else {
        if(G.pickMarked.size>=max){ toast(t('prompt.selectAtMost', { n: max })); return; }
        G.pickMarked.add(card.instance_id);
        sfxCardPick();
      }
      [...g.children].forEach(c=>c.classList.toggle('sel',G.pickMarked.has(c.dataset.id)));
      el('pick-count').textContent=formatSelectedCount(G.pickMarked.size, max);
    }));
  });
  el('pick-count').textContent=formatSelectedCount(0, max);
  syncPickOverlayButtons();
  const btn=el('pick-confirm');
  if(btn){
    btn.onclick=()=>{
      if(G.pickMarked.size<min){ toast(t('prompt.selectAtLeast', { n: min })); return; }
      closeM('overlay-pick');
      G.pickCtx.onConfirm([...G.pickMarked]);
    };
  }
  openM('overlay-pick');
}


function mkHiddenHandPickEl(slot, onClick){
  const d=document.createElement('div');
  d.className='pickcard hidden-hand-pick';
  d.dataset.id=slot.instance_id;
  d.title='Face-down hand card';
  d.onclick=()=>{
    onClick(slot);
  };
  return d;
}


global.openHiddenHandPick = function openHiddenHandPick(pr){
  el('pick-ttl').textContent=promptDisplayTitle(pr, 'Opponent hand');
  el('pick-msg').textContent=promptDisplayText(pr, 'Choose 1 card without looking.');
  const g=el('pick-grid'); g.innerHTML='';
  (pr.hand_slots||[]).forEach(slot=>{
    const elCard=mkHiddenHandPickEl(slot, ()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{card_id:slot.instance_id});
    });
    g.appendChild(elCard);
  });
  el('pick-count').textContent='';
  openM('overlay-pick');
}


global.openOppActiveMemberPick = function openOppActiveMemberPick(pr){
  const cards=pr.stage_members||[];
  el('pick-ttl').textContent=promptDisplayTitle(pr, pt('prompt.chooseMemberTitle'));
  el('pick-msg').textContent=promptDisplayText(pr, 'Choose 1 active Member on your Stage to put into Wait.');
  const g=el('pick-grid'); g.innerHTML='';
  cards.forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{member_id:card.instance_id});
    }));
  });
  el('pick-count').textContent='';
  openM('overlay-pick');
}


global.openPickMemberReturnEnergy = function openPickMemberReturnEnergy(pr){
  const members=pr.members||[];
  el('pick-ttl').textContent=promptDisplayTitle(pr, 'Return Energy');
  el('pick-msg').textContent=promptDisplayText(pr, 'Choose a Member with stacked Energy to return.');
  const g=el('pick-grid'); g.innerHTML='';
  members.forEach(m=>{
    const card={instance_id:m.instance_id,name:m.name,name_en:m.name,cost:m.stacked_count};
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{member_id:m.instance_id,count:m.stacked_count||1});
    }));
  });
  el('pick-count').textContent='';
  openM('overlay-pick');
}

global.openHandPick = function openHandPick({hand,count,title,msg,onConfirm,onCancel,min,allowCancel=true,confirmLabel,forceConfirm=false,promptKey=null}){
  if (global.G?.isSpectator || (typeof global.isReplayViewing === 'function' && global.isReplayViewing())) return;
  const need=count;
  const minPick=min??count;
  const singleTap=!forceConfirm&&need===1&&minPick===1;
  G.handPickCtx={count:need,min:minPick,singleTap,onConfirm,onCancel,promptKey};
  G.pickMarked.clear();
  el('hpick-ttl').textContent=title||t('prompt.chooseFromHand');
  el('hpick-msg').textContent=localizePromptDisplayText(msg||(singleTap
    ? t('prompt.discardOne')
    : t('prompt.discardMany', { count: need })));
  const fan=el('hpick-fan'); fan.innerHTML='';
  (hand||[]).forEach(card=>{
    fan.appendChild(mkPickCardEl(card,'hand-pick-card',()=>{
      const ctx=G.handPickCtx; if(!ctx) return;
      if(ctx.singleTap){
        markPromptSubmitting(G.gameState);
        closeM('overlay-hand-pick');
        G.handPickCtx=null; G.pickMarked.clear();
        ctx.onConfirm?.([card.instance_id]);
        return;
      }
      if(G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
      else {
        if(G.pickMarked.size>=ctx.count){ toast(t('prompt.selectAtMost', { n: ctx.count })); return; }
        G.pickMarked.add(card.instance_id);
        sfxCardPick();
      }
      [...fan.children].forEach(c=>c.classList.toggle('sel',G.pickMarked.has(c.dataset.id)));
      el('hpick-count').textContent=formatSelectedCount(G.pickMarked.size, ctx.count);
    }));
  });
  el('hpick-count').textContent=singleTap
    ? (confirmLabel || t('prompt.tapCardConfirm'))
    : formatSelectedCount(0, need);
  const showActions = !singleTap;
  el('hpick-actions').style.display = showActions ? 'flex' : 'none';
  el('btn-hpick-cancel').style.display = allowCancel === false ? 'none' : '';
  syncHandPickOverlayButtons();
  closeM('overlay-pick');
  openM('overlay-hand-pick');
  syncAntiSoftlockButton(G.gameState, G.playerId);
}

function findPromptSourceCard(pr, s) {
  if (!pr?.source_id || !s) return null;
  const pid = pr.owner || pr.responder;
  const p = s.players?.[pid];
  if (!p) return null;
  const id = pr.source_id;
  const zones = [
    ...(p.hand || []),
    ...(p.waiting_room || []),
    ...Object.values(p.stage || {}).filter(Boolean),
    ...(p.live_zone || []),
    ...(p.energy_zone || []),
  ];
  for (const c of zones) {
    if (c?.instance_id === id) return enrichCard(c);
  }
  return null;
}

function promptAbilityIndex(card, pr) {
  if (typeof pr?.ability_index === 'number') return pr.ability_index;
  const ab = pr?.ability;
  if (!ab || !card?.abilities?.length) return -1;
  return card.abilities.findIndex(a => a.type === ab.type && a.trigger === ab.trigger);
}

global.promptSourceDisplayName = function promptSourceDisplayName(pr, s) {
  const card = findPromptSourceCard(pr, s);
  if (card) return cardLocaleName(card);
  return pr?.source_name || t('prompt.respond');
}

function isLocalizedPromptLocale(loc) {
  return loc === 'ja' || loc === 'es' || loc === 'ko' || loc === 'zh' || loc === 'th' || loc === 'pt';
}

/** Normalize card/prompt copy for matching tagged EN vs server prose. */
function ruleTextComparable(s) {
  if (typeof normalizeRuleTextForCompare === 'function') {
    return normalizeRuleTextForCompare(s);
  }
  if (typeof stripRuleInlineTags === 'function') {
    return stripRuleInlineTags(s).replace(/\s+/g, ' ').trim().toLowerCase();
  }
  return String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function promptTextMatchesCardLine(raw, cardLine) {
  const r = ruleTextComparable(raw);
  const line = ruleTextComparable(cardLine);
  if (!r || !line) return false;
  if (r === line) return true;
  const prefix = line.slice(0, Math.min(24, line.length));
  if (prefix.length >= 6 && r.includes(prefix)) return true;
  const linePrefix = line.slice(0, Math.min(40, line.length));
  if (linePrefix.length >= 6 && r.replace(/\?$/, '').startsWith(linePrefix)) return true;
  return false;
}

/** Prefer printed ability text in the active locale when the server sent English effect/prompt. */
function localizedAbilityLineForPrompt(text, pr, s) {
  const card = findPromptSourceCard(pr, s);
  const idx = card ? promptAbilityIndex(card, pr) : -1;
  if (!(card && idx >= 0)) {
    if (card && text && ruleTextComparable(text) === ruleTextComparable(card.text || '')) {
      return cardRulesDisplayText(card);
    }
    return null;
  }
  const fromAbility = abilityRulesTextFor(card, idx);
  if (!fromAbility) return null;
  const raw = String(text || '').trim();
  const effectEn = (pr?.effect_text || '').trim();
  const abPrompt = String(pr?.ability?.prompt || card.abilities?.[idx]?.prompt || '').trim();
  const enLine = (card.text || '').split(/\n/).map(l => l.trim()).find(l => l && promptTextMatchesCardLine(raw, l));
  const promptMatch = !!abPrompt && (
    ruleTextComparable(raw) === ruleTextComparable(abPrompt)
    || ruleTextComparable(raw) === ruleTextComparable(`${abPrompt}?`)
    || promptTextMatchesCardLine(raw, abPrompt)
  );
  if (!raw || ruleTextComparable(raw) === ruleTextComparable(effectEn) || promptMatch || enLine) {
    if (raw.endsWith('?') && !/[?？]$/.test(fromAbility)) {
      return fromAbility.replace(/[。．.]$/, '') + (getLocale() === 'zh' || getLocale() === 'ja' ? '？' : '?');
    }
    return fromAbility;
  }
  return null;
}

global.localizePromptDisplayText = function localizePromptDisplayText(text, pr, s) {
  if (!text) return text;
  const loc = getLocale();
  if (!isLocalizedPromptLocale(loc)) return localizeSubunitText(text);
  const fromAbility = localizedAbilityLineForPrompt(text, pr, s);
  if (fromAbility) return fromAbility;
  if (window.LLTCG_LOG_I18N?.localizePromptText) {
    return LLTCG_LOG_I18N.localizePromptText(text, G.allCards);
  }
  if (window.LLTCG_LOG_I18N?.localizeLogMessage) {
    return LLTCG_LOG_I18N.localizeLogMessage(text, G.allCards);
  }
  return localizeSubunitText(text);
}

/**
 * Localize text shown by an interactive prompt. Server prompt copy remains the
 * primary source, except the shared Waiting Room → hand wording, which has a
 * stable chrome translation independent of the originating card.
 */
global.promptDisplayText = function promptDisplayText(pr, fallbackKeyOrText, s) {
  const state = s || G.gameState;
  const raw = String(pr?.prompt || '').trim();
  const genericWrToHand = /^(?:choose|pick)\s+(?:a|1)\s+(?:matching\s+)?(?:member\s+)?card\s+from\s+(?:your\s+)?waiting room\s+to\s+add\s+to\s+(?:your\s+)?hand\.?$/i;
  if (raw) {
    if ((pr?.type === 'pick_wr_to_hand' || pr?.type === 'pick_wr_leave_stage_add'
        || /^pick_wr/.test(pr?.type || '')) && genericWrToHand.test(raw)) {
      return pt('prompt.wrPickMsg');
    }
    return localizePromptDisplayText(raw, pr, state);
  }
  if (typeof fallbackKeyOrText === 'string' && fallbackKeyOrText.startsWith('prompt.')) {
    return pt(fallbackKeyOrText);
  }
  return localizePromptDisplayText(fallbackKeyOrText || '', pr, state);
}

global.promptDisplayTitle = function promptDisplayTitle(pr, fallbackText, s, titleText) {
  return localizePromptDisplayText(
    titleText || pr?.source_name || fallbackText || '',
    pr,
    s || G.gameState
  );
}

global.localizePromptEffectText = function localizePromptEffectText(pr, s) {
  const fromAbility = localizedAbilityLineForPrompt(pr?.effect_text || pr?.prompt || '', pr, s);
  if (fromAbility) return fromAbility.replace(/[?？]$/, '');
  const card = findPromptSourceCard(pr, s);
  if (card && !pr?.effect_text) return cardRulesDisplayText(card);
  return localizePromptDisplayText(pr?.effect_text || '', pr, s);
}

global.isYesNoPromptChoices = function isYesNoPromptChoices(choices) {
  if (!choices || choices.length !== 2) return false;
  const a = String(choices[0]).toLowerCase();
  const b = String(choices[1]).toLowerCase();
  return (a === 'yes' && b === 'no') || (a === 'no' && b === 'yes');
}

global.promptChoiceLabel = function promptChoiceLabel(key, i, pr) {
  const k = String(key).toLowerCase();
  if (k === 'yes') return t('prompt.yes');
  if (k === 'no') return t('prompt.noSkip');
  if (k === 'skip') return t('prompt.skip');
  const raw = pr?.choice_labels?.[i];
  if (raw && isLocalizedPromptLocale(getLocale())) {
    return localizePromptDisplayText(raw, pr, G.gameState);
  }
  return raw || key;
}

const HEART_COLOR_CHOICE_KEYS = new Set([
  'pink', 'red', 'yellow', 'green', 'blue', 'purple', 'gray', 'grey', 'any',
]);

const HEART_COLOR_CHOICE_TYPES = new Set([
  'choose_heart_per_success',
  'choose_heart_mus_member',
  'choose_heart_modifier',
  'choose_heart_other_member',
  'waive_required_heart_color',
  'choose_required_heart_pair_gray',
  'choose_replace_member_hearts',
  'wait_self_choose_heart',
  'pl_muse_stack_heart_choice',
  'maki_reveal5_choose_color',
]);

global.isHeartColorChoiceKey = function isHeartColorChoiceKey(key) {
  return HEART_COLOR_CHOICE_KEYS.has(String(key || '').toLowerCase());
}

global.isHeartColorChoicePrompt = function isHeartColorChoicePrompt(pr) {
  if (!pr) return false;
  if (HEART_COLOR_CHOICE_TYPES.has(pr.type)) return true;
  const choices = Array.isArray(pr.choices) ? pr.choices : [];
  return choices.length > 0 && choices.every((c) => isHeartColorChoiceKey(c));
}

global.heartColorChoiceDisplayName = function heartColorChoiceDisplayName(color) {
  const c = String(color || '').toLowerCase().replace('grey', 'gray');
  const keys = {
    yellow: 'heart.yellow',
    pink: 'heart.pink',
    purple: 'heart.purple',
    red: 'heart.red',
    green: 'heart.green',
    blue: 'heart.blue',
  };
  if (keys[c]) return t(keys[c]);
  if (c === 'gray' || c === 'any') return c === 'any' ? pt('heart.any') : pt('heart.gray');
  return c ? c.charAt(0).toUpperCase() + c.slice(1) : '';
}

/** Fill a prompt choice button/label with colored heart icons instead of ♡. */
global.fillHeartColorChoiceContent = function fillHeartColorChoiceContent(el, key, pr) {
  if (!el) return false;
  const color = String(key || '').toLowerCase().replace('grey', 'gray');
  if (typeof mkHeartIcon !== 'function' || !isHeartColorChoiceKey(color)) return false;
  el.textContent = '';
  el.classList.add('prompt-choice-hearts');
  if (pr?.type === 'choose_required_heart_pair_gray') {
    el.appendChild(mkHeartIcon(color, true));
    el.appendChild(mkHeartIcon(color, true));
    el.appendChild(mkHeartIcon('gray', true));
    const caption = document.createElement('span');
    caption.className = 'prompt-choice-heart-caption';
    caption.textContent = pt('heart.twicePlusGray', { color: heartColorChoiceDisplayName(color) });
    el.appendChild(caption);
    return true;
  }
  el.appendChild(mkHeartIcon(color, true));
  const name = document.createElement('span');
  name.className = 'prompt-choice-heart-caption';
  name.textContent = heartColorChoiceDisplayName(color);
  el.appendChild(name);
  if (pr?.type === 'waive_required_heart_color') {
    const suffix = document.createElement('span');
    suffix.className = 'prompt-choice-heart-suffix';
    suffix.textContent = pt('heart.waivedSuffix');
    el.appendChild(suffix);
  }
  return true;
}

global.promptQuestionText = function promptQuestionText(pr, effectDisplay, s) {
  const raw = (pr?.prompt || '').trim();
  const effect = (pr?.effect_text || '').trim();
  if (!raw || raw === effect || raw === effectDisplay) {
    return pr?.type === 'optional_live_start' ? t('prompt.useLiveStart') : t('prompt.useEffect');
  }
  return localizePromptDisplayText(raw, pr, s);
}

global.renderPromptEffectText = function renderPromptEffectText(text, pr, s){
  const box=el('prompt-effect');
  if(!box) return;
  const display = text
    ? (pr
      ? localizePromptDisplayText(text, pr, s)
      : (isLocalizedPromptLocale(getLocale()) && window.LLTCG_LOG_I18N?.localizePromptText
        ? LLTCG_LOG_I18N.localizePromptText(text, G.allCards)
        : text))
    : '';
  if(!display){
    box.hidden=true;
    box.innerHTML='';
    return;
  }
  box.hidden=false;
  renderCardRulesText(display, box);
}

global.isSelfActivationPrompt = function isSelfActivationPrompt(pr){
  if(isBranchChoicePrompt(pr)) return false;
  if(!pr?.effect_text) return false;
  if(pr.responder!==pr.owner) return false;
  const choices=Array.isArray(pr.choices)?pr.choices:[];
  if(choices.length&&!isYesNoPromptChoices(choices)) return false;
  return true;
}

global.ensurePromptChoices = function ensurePromptChoices(pr){
  if(!pr) return pr;
  const choices=Array.isArray(pr.choices)?pr.choices:[];
  const type=pr.type||'';
  const step=pr.step||'';
  // Card-pick steps already cleared choices on the server — do not reinject Yes/No
  // (that softlocks Ginko / PB1 multi-step WR picks behind an empty choice dialog).
  if(/^pick_wr/.test(step) || step==='pick_live' || step==='pick_member'
      || step==='pick_hand' || step==='pick_slot' || step==='pick'
      || step==='pick_wait' || step==='pick_wait_member' || step==='pick_dest' || step==='assign'){
    return pr;
  }
  const optionalType=isSelfActivationPrompt(pr)
    ||type==='optional_live_start'
    ||type==='optional_discard_prompt'
    ||type.startsWith('optional_');
  if(choices.length){
    if(isYesNoPromptChoices(choices)){
      return {...pr, choice_labels:[t('prompt.yes'), t('prompt.noSkip')]};
    }
    return pr;
  }
  if(Array.isArray(pr.heart_choices)&&pr.heart_choices.length){
    return {...pr, choices:[...pr.heart_choices]};
  }
  if(!optionalType) return pr;
  return {
    ...pr,
    choices:['yes','no'],
    choice_labels:[t('prompt.yes'), t('prompt.noSkip')],
    prompt:pr.prompt||(type==='optional_live_start'
      ? t('prompt.useLiveStart')
      : t('prompt.useEffect')),
  };
}

global.isBranchChoicePrompt = function isBranchChoicePrompt(pr){
  if(!pr?.choices?.length) return false;
  const branchTypes=new Set([
    'player_choice','opponent_choice',
    'live_start_center_cost_choice','player_choice_wr_live_deck_bottom_draw',
    'player_choice_wr_members_deck_bottom','choice_energy_or_wr_lives_deck_top',
    'live_success_pick_energy_or_member','live_success_pay_choice_wr_add',
    'live_success_choose_draw_or_energy_wait',
    'live_start_unless_discard_return_energy',
    'live_start_edel_choice',
    'sbp5_aqours_blade_or_position','sbp6_live_wr_deck_position','sbp6_hand_deck_position',
    'ssd1_reveal_group_deck','opp_pick_wr_live_offer','spbp5_wr_pay_add_hand',
    'spbp2_discard_liella_choice'
  ]);
  if(branchTypes.has(pr.type)){
    if(pr.type==='live_start_center_cost_choice'&&pr.step&&pr.step!=='pick_mode') return false;
    if(pr.type==='player_choice_wr_live_deck_bottom_draw'&&pr.step==='pick_wr_live') return false;
    // Do! Do! Do! CL: confirm is Yes/No pay; only step=pick is the mode branch.
    if(pr.type==='live_success_pay_choice_wr_add'&&pr.step!=='pick') return false;
    return true;
  }
  const labels=(pr.choice_labels||[]).map(l=>String(l).trim().toLowerCase());
  if(labels.length<2) return false;
  const yesNoOnly=labels.every(l=>/^(yes|no)\b/.test(l)||l==='skip'||l==='both');
  return !yesNoOnly;
}

function surveilFindSlotIndex(id) {
  return G.surveil.slots.findIndex(x => x === id);
}

function surveilClearSelection() {
  G.surveil.selId = null;
}

function surveilTopIds() {
  return G.surveil.slots.filter(Boolean);
}

function mkSurveilCardEl(card, opts = {}) {
  const d = document.createElement('div');
  d.className = 'surveilcard ' + menuCardClasses(card);
  d.dataset.id = card.instance_id;
  if (G.surveil.selId === card.instance_id) d.classList.add('sel');
  if (G.surveil.drag?.id === card.instance_id) d.classList.add('dragging');
  appendCardFace(d, card);
  bindPickerCardHover(d, card, global.G?.gameState, global.G?.playerId);
  d.addEventListener('click', (ev) => {
    if (d._suppressMenuTap) {
      d._suppressMenuTap = false;
      return;
    }
    ev.stopPropagation();
    onSurveilCardTap(card.instance_id, opts);
  });
  bindSurveilCardDrag(d, card, card.instance_id);
  return d;
}

function bindSurveilCardDrag(node, card, id) {
  node.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0) return;
    const drag = {
      id,
      startX: ev.clientX,
      startY: ev.clientY,
      moved: false,
      longFired: false,
      longPressTimer: setTimeout(() => {
        const d = G.surveil.drag;
        if (!d || d.id !== id || d.moved) return;
        d.longFired = true;
        const c = card || G.surveil?.byId?.[id];
        if (c) showPickMenuCard(c);
      }, LONG_PRESS_MS),
    };
    G.surveil.drag = drag;
    node.setPointerCapture?.(ev.pointerId);
  });
  node.addEventListener('contextmenu', (ev) => {
    ev.preventDefault();
    const c = card || G.surveil?.byId?.[id];
    if (c) showPickMenuCard(c);
  });
  node.addEventListener('pointermove', (ev) => {
    const d = G.surveil.drag;
    if (!d || d.id !== id) return;
    if (!d.moved && (Math.abs(ev.clientX - d.startX) > 6 || Math.abs(ev.clientY - d.startY) > 6)) {
      d.moved = true;
      sfxCardPick();
      if (d.longPressTimer) {
        clearTimeout(d.longPressTimer);
        d.longPressTimer = null;
      }
      renderSurveilZones();
    }
  });
  node.addEventListener('pointerup', (ev) => {
    const d = G.surveil.drag;
    if (!d || d.id !== id) return;
    if (d.longPressTimer) {
      clearTimeout(d.longPressTimer);
      d.longPressTimer = null;
    }
    if (d.longFired) node._suppressMenuTap = true;
    G.surveil.drag = null;
    if (d.moved) {
      const target = document.elementFromPoint(ev.clientX, ev.clientY);
      const slotEl = target?.closest?.('.surveil-slot');
      const wrEl = target?.closest?.('#surveil-wr');
      if (slotEl) {
        const idx = parseInt(slotEl.dataset.slot, 10);
        if (!Number.isNaN(idx)) surveilDropOnSlot(idx, id);
      } else if (wrEl && !G.surveil?.returnAll) {
        surveilMoveToWr(id);
      }
      renderSurveilZones();
    }
    try { node.releasePointerCapture?.(ev.pointerId); } catch (_) {}
  });
  node.addEventListener('pointercancel', () => {
    const d = G.surveil.drag;
    if (d?.id === id && d.longPressTimer) clearTimeout(d.longPressTimer);
    if (G.surveil.drag?.id === id) G.surveil.drag = null;
    renderSurveilZones();
  });
}

function onSurveilCardTap(id, opts) {
  if (G.surveil.drag?.moved) return;
  const sel = G.surveil.selId;
  const inWr = G.surveil.wr.includes(id);
  const slotIdx = surveilFindSlotIndex(id);

  if (!sel) {
    G.surveil.selId = id;
    renderSurveilZones();
    return;
  }
  if (sel === id) {
    surveilClearSelection();
    renderSurveilZones();
    return;
  }

  const selWr = G.surveil.wr.includes(sel);
  const selSlot = surveilFindSlotIndex(sel);

  if (!selWr && slotIdx >= 0 && !inWr) {
    surveilSwapDeckPositions(sel, id);
  } else if (!selWr && inWr) {
    surveilMoveToWr(sel);
  } else if (selWr && slotIdx >= 0) {
    surveilPlaceInSlot(slotIdx, sel);
  } else if (selWr && inWr) {
    G.surveil.selId = id;
    renderSurveilZones();
    return;
  }
  surveilClearSelection();
  renderSurveilZones();
}

function surveilSwapDeckPositions(idA, idB) {
  const i = surveilFindSlotIndex(idA);
  const j = surveilFindSlotIndex(idB);
  if (i < 0 || j < 0) return;
  G.surveil.slots[i] = idB;
  G.surveil.slots[j] = idA;
}

function surveilPlaceInSlot(slotIdx, id) {
  if (slotIdx < 0 || slotIdx >= G.surveil.slots.length) return;
  const existing = G.surveil.slots[slotIdx];
  G.surveil.wr = G.surveil.wr.filter(x => x !== id);
  if (existing && existing !== id) {
    if (!G.surveil.wr.includes(existing)) G.surveil.wr.push(existing);
  }
  G.surveil.slots[slotIdx] = id;
}

function surveilDropOnSlot(slotIdx, id) {
  if (G.surveil.wr.includes(id)) {
    surveilPlaceInSlot(slotIdx, id);
    return;
  }
  const from = surveilFindSlotIndex(id);
  if (from < 0) return;
  const existing = G.surveil.slots[slotIdx];
  if (!existing) {
    G.surveil.slots[slotIdx] = id;
    G.surveil.slots[from] = null;
  } else if (existing !== id) {
    G.surveil.slots[from] = existing;
    G.surveil.slots[slotIdx] = id;
  }
}

function surveilMoveToWr(id) {
  if (G.surveil?.returnAll) return;
  const idx = surveilFindSlotIndex(id);
  if (idx >= 0) {
    G.surveil.slots[idx] = null;
    if (!G.surveil.wr.includes(id)) G.surveil.wr.push(id);
  } else if (G.surveil.wr.includes(id)) {
    // already in WR
  }
}

function surveilMoveToDeck(id) {
  if (!G.surveil.wr.includes(id)) return;
  G.surveil.wr = G.surveil.wr.filter(x => x !== id);
  const empty = G.surveil.slots.findIndex(x => !x);
  if (empty >= 0) G.surveil.slots[empty] = id;
  else G.surveil.slots.push(id);
}

global.renderSurveilOverlay = function renderSurveilOverlay(pr){
  const ovl=el('overlay-surveil');
  const returnAll = !!pr.return_all;
  ovl?.classList.toggle('return-all', returnAll);
  el('surveil-ttl').textContent=promptDisplayTitle(pr, 'Look at deck');
  const n = (pr.looked_cards || []).length;
  el('surveil-msg').textContent=promptDisplayText(pr, (
    returnAll
      ? (n === 1
        ? 'Look at the top card of your deck and put it back on top.'
        : `Look at the top ${n} cards of your deck and put them back on top in any order.`)
      : (n === 1
        ? 'Look at the top card of your deck. You may put it on top of your deck or put it into the Waiting Room.'
        : `Look at the top ${n} cards of your deck. You may put any number of them on top of your deck in any order and put the rest into the Waiting Room.`)
  ));
  const hint = ovl?.querySelector('.surveil-hint');
  if (hint && typeof t === 'function') {
    const hintKey = returnAll ? 'prompt.surveilHintReturnAll' : 'prompt.surveilHint';
    hint.setAttribute('data-i18n', hintKey);
    hint.textContent = t(hintKey);
  }
  const cards = pr.looked_cards || [];
  G.surveil = {
    slots: cards.map(c => c.instance_id),
    wr: [],
    byId: {},
    selId: null,
    drag: null,
    returnAll,
  };
  cards.forEach(c => { G.surveil.byId[c.instance_id] = c; });
  renderSurveilZones();
  ovl.classList.add('open');
  bumpAntiSoftlockButton();
}

global.renderSurveilZones = function renderSurveilZones(){
  const beforeByKey = captureShiftRectsByKey('.surveilcard', 'data-id');
  const slotsEl = el('surveil-deck-slots');
  const wrEl = el('surveil-wr');
  if (!slotsEl || !wrEl) return;
  slotsEl.innerHTML = '';
  wrEl.innerHTML = '';

  G.surveil.slots.forEach((id, idx) => {
    const slot = document.createElement('div');
    slot.className = 'surveil-slot' + (id ? ' has-card' : '');
    slot.dataset.slot = String(idx);
    const num = document.createElement('span');
    num.className = 'surveil-slot-num';
    num.textContent = String(idx + 1);
    const drop = document.createElement('div');
    drop.className = 'surveil-slot-drop';
    slot.appendChild(num);
    slot.appendChild(drop);
    if (id) {
      const card = G.surveil.byId[id];
      if (card) slot.appendChild(mkSurveilCardEl(card, { slot: idx }));
    }
    slot.addEventListener('click', (ev) => {
      if (ev.target.closest('.surveilcard')) return;
      const sel = G.surveil.selId;
      if (sel) {
        if (G.surveil.wr.includes(sel)) surveilPlaceInSlot(idx, sel);
        else surveilDropOnSlot(idx, sel);
        surveilClearSelection();
        renderSurveilZones();
      }
    });
    slotsEl.appendChild(slot);
  });

  G.surveil.wr.forEach(id => {
    const card = G.surveil.byId[id];
    if (card) wrEl.appendChild(mkSurveilCardEl(card, { wr: true }));
  });

  wrEl.onclick = (ev) => {
    if (G.surveil?.returnAll) return;
    if (ev.target.closest('.surveilcard')) return;
    const sel = G.surveil.selId;
    if (sel && !G.surveil.wr.includes(sel)) {
      surveilMoveToWr(sel);
      surveilClearSelection();
      renderSurveilZones();
    }
  };
  playSurveilShiftAnimation(beforeByKey);
}

global.confirmSurveil = async function confirmSurveil(){
  const all = Object.keys(G.surveil.byId);
  if (G.surveil.returnAll) {
    if (G.surveil.wr.length || all.some(id => !surveilTopIds().includes(id))) {
      toast(pt('prompt.surveilReturnAll'));
      return;
    }
  }
  const assigned = new Set([...surveilTopIds(), ...G.surveil.wr]);
  if (all.some(id => !assigned.has(id))) { toast(pt('prompt.surveilAssignAll')); return; }
  const btn = el('btn-surveil-ok');
  if (btn?.disabled) return;
  if (btn) btn.disabled = true;
  try {
    await sendAct('resolve_prompt',{choice:'confirm',top_ids:surveilTopIds(),wr_ids:[...G.surveil.wr]});
    clearDeferredPromptState();
    closeM('overlay-surveil');
    if (G.gameState && G.playerId) {
      updatePhaseActionButton(G.gameState, G.playerId);
      renderPrompt(G.gameState, G.playerId);
    }
  } finally {
    if (btn) btn.disabled = false;
  }
}

function cardMatchesNamedHand(c, names, includeSelf, sourceId) {
  const label = c?.name_en || c?.name || '';
  for (const n of (names || [])) {
    if (label === n || label.includes(n)) return true;
    if (label.includes('&') || label.includes('＆')) {
      for (const part of label.split(/[&＆]/)) {
        if (part.trim() === n) return true;
      }
    }
  }
  return !!(includeSelf && sourceId && c?.instance_id === sourceId);
}
global.cardMatchesNamedHand = cardMatchesNamedHand;


function optionalLiveStartDiscardHand(pr, s, myId) {
  const me = s.players?.[myId];
  const ab = pr.ability || {};
  let pickHand = me?.hand || [];
  if (ab.type === 'optional_discard_named') {
    const names = ab.names || [];
    pickHand = pickHand.filter(c => cardMatchesNamedHand(c, names, ab.include_self, pr.source_id));
    return pickHand;
  }
  // Discard-cost group only — never ab.group / then.group (those target WR/add effects,
  // e.g. Umi PL!-bp3-004 optional_discard_add_from_wr group μ's + filter live).
  const grp = ab.discard_group || '';
  if (grp) {
    pickHand = pickHand.filter(c => c.card_type === 'メンバー' && (c.group || '') === grp);
  }
  const sub = ab.discard_subunit || ab.requires_subunit_in_hand || pr.subunit || '';
  if (sub) {
    pickHand = pickHand.filter(c =>
      (typeof cardMatchesSubunit === 'function')
        ? cardMatchesSubunit(c, sub)
        : String(c.subunit || '').toLowerCase().includes(String(sub).toLowerCase())
    );
  }
  // Same for filter: optional_discard_add_from_wr's filter is the WR card type, not the discard.
  if (ab.type !== 'optional_discard_add_from_wr') {
    const filter = ab.filter || '';
    if (filter === 'live') {
      pickHand = pickHand.filter(c => c.card_type === 'ライブ' || c.card_type_en === 'Live');
    } else if (filter === 'member') {
      pickHand = pickHand.filter(c => c.card_type === 'メンバー' || c.card_type_en === 'Member');
    }
  }
  return pickHand;
}


global.openOptionalLiveStartDiscardPick = function openOptionalLiveStartDiscardPick(pr, s, myId) {
  const ab = pr.ability || {};
  const discardNeed = promptDiscardCount(pr, 'yes');
  const pickHand = optionalLiveStartDiscardHand(pr, s, myId);
  const minPick = (pr.max_discard || ab.max_discard) ? 0 : discardNeed;
  if (!pickHand.length) {
    if (isReplayPromptReadOnlyState(s)) return false;
    G._promptSubmitKey = null;
    sendAct('resolve_prompt', { choice: 'no' });
    return true;
  }
  if (discardNeed <= 0) return false;
  closeM('overlay-prompt');
  openHandPick({
    hand: pickHand,
    count: discardNeed,
    min: minPick,
    title: pr.source_name || pt('prompt.discardFromHand'),
    msg: promptDisplayText(pr, minPick === 0
      ? `Choose up to ${discardNeed} cards to send to the Waiting Room.`
      : discardNeed === 1
      ? 'Choose a card to send to the Waiting Room.'
      : `Choose ${discardNeed} cards to send to the Waiting Room.`, s),
    onConfirm: (ids) => sendResolvePrompt('yes', { discard_ids: ids }),
    // Cancel = skip the optional "may" (do not re-open the same pick — #79 Proof Kosuzu).
    onCancel: () => {
      G._promptSubmitKey = null;
      sendResolvePrompt('no');
    },
  });
  return true;
}


function promptDiscardCount(pr, choice){
  if(choice!=='yes' && choice!=='discard') return 0;
  if(pr.type==='optional_live_start') {
    const ab = pr.ability || {};
    if (ab.type === 'optional_discard_named') {
      if (ab.exact_total) return ab.exact_total;
      if (pr.max_discard) return pr.max_discard;
      return 0;
    }
    return pr.discard_count||ab.discard||0;
  }
  if(pr.type==='optional_discard_blade_draw_if_live') return pr.ability?.discard||1;
  // Kanan PL!S-bp3-003 Live Start: up to N hand cards → +blade per card.
  if(pr.type==='optional_discard_blade_per_card') {
    return pr.max_discard || pr.ability?.max_discard || 2;
  }
  if(pr.type==='live_start_pay_or_discard'&&choice==='discard') return pr.discard_count||2;
  if(pr.type==='live_start_unless_discard_return_energy'&&choice==='discard') return pr.discard_count||1;
  if(pr.type==='mandatory_discard_group_branch') return pr.discard_count||pr.max_pick||1;
  if(pr.type==='optional_discard_prompt'){
    if(pr.ability?.max_discard) return pr.ability.max_discard;
    return pr.ability?.discard||1;
  }
  if(pr.type==='optional_discard_subunit_draw_buff_cost') return 1;
  if(pr.type==='discard_member_add_lower_wr_member') return 1;
  if(pr.type==='optional_discard_mill_wr_add_member') return 1;
  if(pr.type==='optional_discard_grant_heart_other_member') return 1;
  if(pr.type==='optional_discard_activate_wait_blade'||pr.type==='optional_discard_activate_wait_hearts') {
    if ((pr.step || '') === 'pick_wait') return 0;
    return 2;
  }
  if(pr.type==='wait_self_discard_add_wr_live') return pr.ability?.discard||1;
  if(pr.type==='optional_discard_look_reveal_subunit') return pr.ability?.discard||1;
  if(pr.type==='optional_discard_mill_add_wr_subunit_live') return pr.ability?.discard||1;
  if(pr.type==='optional_discard_add_cb_member_hs_live') return pr.discard||2;
  if(pr.type==='optional_wait_self_look_reveal') return pr.discard_count||pr.ability?.discard||0;
  if(pr.type==='mandatory_discard_after_draw') return pr.discard_count||1;
  if(pr.type==='opp_may_discard_or_modifier') return 1;
  if(pr.type==='reveal_live_opp_discard_or_blade') return 1;
  // PL!S-bp6-003 Kanan: Yes immediately requires discarding 1 (cost), then stage/WR picks.
  if(pr.type==='sbp6_swap_stage_wr_member') return pr.ability?.discard || pr.discard_count || 1;
  return 0;
}


function hidePromptEffectText(){
  const box=el('prompt-effect');
  if(!box) return;
  box.hidden=true;
  box.innerHTML='';
}

function hidePromptLookCards(){
  const wrap=el('prompt-look-cards');
  if(!wrap) return;
  wrap.hidden=true;
  wrap.innerHTML='';
}

function renderPromptLookCards(pr){
  const wrap=el('prompt-look-cards');
  if(!wrap) return;
  const cards=pr?.looked_cards||pr?.look_cards||[];
  if(!cards.length){
    hidePromptLookCards();
    return;
  }
  wrap.innerHTML='';
  wrap.hidden=false;
  cards.forEach((raw)=>{
    if(!raw) return;
    const card=(typeof enrichCard==='function')?enrichCard(raw):raw;
    wrap.appendChild(mkPickCardEl(card,'prompt-look-card pickcard',null));
  });
  if(!wrap.childElementCount) hidePromptLookCards();
}


global.sendResolvePrompt = function sendResolvePrompt(choice, extra={}){
  if (isReplayPromptReadOnlyState(G.gameState)) return;
  markPromptSubmitting(G.gameState);
  G._lastSurfacedPromptKey = null;
  sendAct('resolve_prompt',{choice,...extra});
}


global.renderSelfActivationPrompt = function renderSelfActivationPrompt(pr, s, myId, box, branch){
  pr=ensurePromptChoices(pr);
  el('prompt-ttl').textContent=promptSourceDisplayName(pr, s);
  const effectDisplay=localizePromptEffectText(pr, s);
  renderPromptEffectText(effectDisplay, pr, s);
  const msgEl=el('prompt-msg');
  msgEl.textContent=promptQuestionText(pr, effectDisplay, s);
  msgEl.className='prompt-cost-question';
  renderPromptLookCards(pr);
  const subEl=el('prompt-sub');
  subEl.hidden=false;
  subEl.textContent=t('prompt.activateSub');
  box.className='prompt-choice-list';
  box.innerHTML='';
  (pr.choices||[]).forEach((key,i)=>{
    const b=document.createElement('button');
    b.className='btn-grad';
    b.textContent=promptChoiceLabel(key, i, pr);
    b.onclick=()=> handlePromptChoice(pr,key,s,myId);
    box.appendChild(b);
  });
}


function submitTextAnswerPrompt(pr, myId){
  if (isReplayPromptReadOnlyState(G.gameState)) return;
  const input=el('prompt-text-input');
  const text=(input?.value||'').trim();
  if(!text){ toast(t('prompt.typeAnswer')); input?.focus(); return; }
  closeM('overlay-prompt');
  sendAct('resolve_prompt',{answer_text:text});
}


global.renderTextAnswerPrompt = function renderTextAnswerPrompt(pr){
  const wrap=el('prompt-text-wrap');
  const input=el('prompt-text-input');
  const hintsEl=el('prompt-outcome-hints');
  const box=el('prompt-btns');
  wrap.hidden=false;
  box.innerHTML='';
  box.className='';
  el('prompt-sub').hidden=false;
  el('prompt-sub').textContent=t('prompt.typeAnswerHint');
  hintsEl.innerHTML='';
  (pr.outcome_hints||[]).forEach(line=>{
    const li=document.createElement('li');
    li.textContent=line;
    hintsEl.appendChild(li);
  });
  input.value='';
  const submit=el('prompt-text-submit');
  submit.textContent=t('prompt.answer');
  submit.onclick=()=> submitTextAnswerPrompt(pr);
  input.onkeydown=(e)=>{
    if(e.key==='Enter'){ e.preventDefault(); submitTextAnswerPrompt(pr); }
  };
  setTimeout(()=> input.focus(), 80);
}


global.hideTextAnswerPrompt = function hideTextAnswerPrompt(){
  const wrap=el('prompt-text-wrap');
  if(wrap) wrap.hidden=true;
  const input=el('prompt-text-input');
  if(input) input.onkeydown=null;
}


global.renderBranchChoiceButtons = function renderBranchChoiceButtons(pr, s, myId, box){
  box.innerHTML='';
  const heartPick = isHeartColorChoicePrompt(pr);
  (pr.choices||[]).forEach((key,i)=>{
    const label=promptChoiceLabel(key, i, pr);
    const b=document.createElement('button');
    b.type='button';
    b.className='prompt-choice-btn';
    const num=document.createElement('span');
    num.className='prompt-choice-num';
    num.textContent=String(i+1);
    const text=document.createElement('span');
    text.className='prompt-choice-text';
    if (!(heartPick && fillHeartColorChoiceContent(text, key, pr))) {
      text.textContent=label;
    }
    b.appendChild(num);
    b.appendChild(text);
    b.onclick=()=> handlePromptChoice(pr,key,s,myId);
    box.appendChild(b);
  });
}


global.handlePromptChoice = function handlePromptChoice(pr, choice, s, myId){
  if (isReplayPromptReadOnlyState(s)) return;
  if (choice === 'no' && pr?.type === 'optional_swap_area_on_enter') choice = 'skip';
  const me=s.players[myId];
  const discardNeed=promptDiscardCount(pr,choice);
  const needsPay=(choice==='yes'&&!!pr.needs_pay)
    ||(pr.type==='optional_pay_energy_on_enter'&&choice==='yes')
    ||(pr.type==='optional_pay_energy_add_from_wr'&&choice==='yes')
    ||(pr.type==='live_start_pay_or_discard'&&choice==='pay');
  if(needsPay){
    const ae=(me.energy_zone||[]).filter(energyChipActive).length;
    const cost=pr.pay_cost||pr.ability?.cost||0;
    if(ae<cost){ toast(`Need ${energyCostHtml(cost)} active (have ${energyCostHtml(ae)})`, 2500, true); return; }
  }
  if(pr.type==='sbp6_hand_deck_position'&&(choice==='top'||choice==='bottom')){
    closeM('overlay-prompt');
    openHandPick({
      hand:me.hand||[], count:1, min:1,
      title:pr.source_name||'Deck position',
      msg:`Choose 1 card to put on deck ${choice}.`,
      onConfirm:(ids)=>sendAct('resolve_prompt',{discard_ids:ids, position:choice}),
      onCancel:()=>{ if(G.gameState) renderPrompt(G.gameState,myId); }
    });
    return;
  }
  if((pr.type==='sbp6_live_start_pay_member_score'||pr.type==='sbp6_swap_stage_wr_member')&&pr.step==='pay'&&choice==='discard'){
    closeM('overlay-prompt');
    const need=pr.type==='sbp6_swap_stage_wr_member'?1:2;
    openHandPick({
      hand:me.hand||[], count:need, min:need,
      title:pr.source_name||'Discard',
      msg:promptDisplayText(pr, `Discard ${need} card(s).`, s),
      onConfirm:(ids)=>sendAct('resolve_prompt',{choice:'discard', discard_ids:ids}),
      onCancel:()=>{ if(G.gameState) renderPrompt(G.gameState,myId); }
    });
    return;
  }
  if(pr.type==='optional_wr_member_reenter'&&choice==='yes'){
    closeM('overlay-prompt');
    openStageSlotPick({...pr, step:'pick_named', candidates:pr.candidates||[]});
    return;
  }
  if(pr.type==='reveal_hand_named_stack_under'&&choice==='yes'){
    closeM('overlay-prompt');
    const ids=new Set((pr.candidates||[]).map(c=>c.instance_id));
    const hand=(me?.hand||[]).filter(c=>ids.has(c.instance_id));
    openHandPick({
      hand,
      count: 1,
      forceConfirm: true,
      title: pr.source_name||'Reveal Member',
      msg: promptDisplayText(pr, 'Choose a matching Member from your hand to stack under this Member.', s),
      onConfirm: (picked)=> sendAct('resolve_prompt',{choice:'yes',card_id:picked[0]}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr.type==='play_stacked_member_from_under'&&choice==='yes'){
    closeM('overlay-prompt');
    const cards=pr.stack_cards||pr.candidates||[];
    openHandPick({
      hand: cards,
      count: 1,
      forceConfirm: true,
      title: pr.source_name||'Play stacked Member',
      msg: 'Choose 1 stacked Member to put onto an empty Stage area.',
      onConfirm: (picked)=>{
        const empty=pr.empty_slots||[];
        if(!empty.length){
          sendAct('resolve_prompt',{choice:'no'});
          return;
        }
        if(empty.length===1){
          sendAct('resolve_prompt',{choice:'yes',card_id:picked[0],slot:empty[0]});
          return;
        }
        el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Play stacked Member', s);
        el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.chooseEmptyStage', s);
        const box=el('prompt-btns'); box.innerHTML='';
        empty.forEach(slot=>{
          const b=document.createElement('button');
          b.className='btn-grad';
          b.textContent=slotLabel(slot);
          b.onclick=()=>{
            closeM('overlay-prompt');
            sendAct('resolve_prompt',{choice:'yes',card_id:picked[0],slot});
          };
          box.appendChild(b);
        });
        const skip=document.createElement('button');
        skip.className='btn-ghost';
        skip.textContent=t('prompt.noSkip');
        skip.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:'no'}); };
        box.appendChild(skip);
        el('overlay-prompt').classList.add('open');
      },
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr.type==='optional_pos_change_subunit_blade'&&choice==='yes'){
    closeM('overlay-prompt');
    const slots=pr.target_slots||[];
    openStageSlotPick({
      ...pr,
      candidates: slots.map(slot=>({slot, name_en: slotLabel(slot)})),
      prompt: 'Choose a Mira-Cra Park! Member area to Position Change with.'
    });
    return;
  }
  if(pr.type==='optional_wr_to_deck_top'&&choice==='yes'){
    closeM('overlay-prompt');
    const cards=(pr.candidates||[]).filter(c=>c&&c.instance_id);
    if(!cards.length){
      sendAct('resolve_prompt',{choice:'yes'});
      return;
    }
    openHandPick({
      hand: cards,
      count: 1,
      min: 1,
      title: pr.source_name||pt('prompt.wrPickTitle')||'Waiting Room',
      msg: promptDisplayText(pr, 'Choose 1 card from your Waiting Room to put on top of your deck.', s),
      onConfirm: (picked)=> sendAct('resolve_prompt',{choice:'yes',card_id:picked[0]}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr.type==='optional_wr_to_deck_top'&&(choice==='no'||choice==='skip')){
    closeM('overlay-prompt');
    sendAct('resolve_prompt',{choice:'no'});
    return;
  }
  if((pr.type==='optional_wr_live_deck_bottom'||pr.type==='live_success_yell_live_deck_bottom'
    ||pr.type==='live_success_pick_yell_deck_top')&&choice==='pick'){
    closeM('overlay-prompt');
    if (pr.type === 'live_success_pick_yell_deck_top') {
      openHandPick({
        hand: pr.candidates || [],
        count: 1,
        min: 1,
        title: pr.source_name || pt('prompt.deckTopTitle'),
        msg: promptDisplayText(pr, 'prompt.deckTopMsg', s),
        onConfirm: (picked) => sendAct('resolve_prompt', { card_id: picked[0] }),
        onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
      });
      return;
    }
    if (pr.type === 'live_success_yell_live_deck_bottom') {
      const yellLives = yellRevealPickCards(pr, G.gameState, G.playerId);
      if (!yellLives.length) {
        sendAct('resolve_prompt', { choice: 'skip' });
        return;
      }
      openYellRevealPick(pr, {
        state: G.gameState,
        myId: G.playerId,
        onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
      });
    } else {
      openWrLivePick(pr, {
        onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
      });
    }
    return;
  }
  if((pr.type==='optional_wr_live_deck_bottom'||pr.type==='live_success_yell_live_deck_bottom'
    ||pr.type==='live_success_pick_yell_deck_top')&&(choice==='skip'||choice==='no')){
    closeM('overlay-prompt');
    sendAct('resolve_prompt', { choice: 'skip' });
    return;
  }
  if(discardNeed>0){
    closeM('overlay-prompt');
    const minPick=(pr.max_discard||pr.ability?.max_discard)?0:discardNeed;
    let pickHand=me.hand||[];
    if(pr.type==='optional_live_start'
      ||(pr.type==='optional_discard_prompt'&&(pr.live_start||s.phase==='live_start_effects'))
      ||pr.type==='optional_discard_subunit_draw_buff_cost'){
      pickHand=optionalLiveStartDiscardHand(pr,s,myId);
    }
    if(pr.type==='optional_discard_prompt' && pr.ability?.filter==='live'){
      pickHand=pickHand.filter(c=>c && (c.card_type==='ライブ' || c.card_type_en==='Live'));
      if(!pickHand.length){ toast(pt('prompt.noLiveInHand')); return; }
    }
    if(pr.type==='optional_discard_prompt' && pr.ability?.filter==='member'){
      pickHand=pickHand.filter(c=>c && (c.card_type==='メンバー' || c.card_type_en==='Member'));
      if(!pickHand.length){ toast(pt('prompt.noMemberInHand')); return; }
    }
    if(pr.type==='opp_may_discard_or_modifier'){
      pickHand=pickHand.filter(c=>c.card_type==='ライブ');
      if(!pickHand.length){ toast(pt('prompt.noLiveInHand')); return; }
    }
    if(pr.type==='live_start_pay_or_discard'&&choice==='discard'){
      pickHand=me.hand||[];
    }
    if(!pickHand.length && minPick===0){
      sendResolvePrompt(choice,{discard_ids:[],pay:needsPay});
      return;
    }
    openHandPick({
      hand:pickHand, count:discardNeed, min:minPick,
      title:pr.source_name||'Discard from hand',
    msg:promptDisplayText(pr, minPick===0
        ? `Choose up to ${discardNeed} cards to send to the Waiting Room.`
        : discardNeed===1
        ? 'Choose a card to send to the Waiting Room.'
      : `Choose ${discardNeed} cards to send to the Waiting Room.`, s),
      onConfirm:(ids)=>sendResolvePrompt(choice,{discard_ids:ids,pay:needsPay}),
      onCancel:()=>{
        G._promptSubmitKey=null;
        closeM('overlay-hand-pick');
        if(G.gameState) renderPrompt(G.gameState,myId);
      }
    });
    return;
  }
  closeM('overlay-prompt');
  sendResolvePrompt(choice, needsPay?{pay:true}:{});
}

/**
 * BP07 (Mellow Moment) picks. The server emits two generic shapes so new cards do
 * not each need bespoke UI: `bp7_pick_cards` (candidate list + pick_min/pick_max)
 * and `bp7_pick_stage_member` (candidates carry `slot`). Yes/No (`bp7_confirm`) and
 * labelled option lists (`bp7_pick_slot`, `bp7_choose_player`) fall through to the
 * shared branch-choice renderer.
 * @returns {boolean} true when this renderer took over the prompt.
 */
global.renderPromptBp7Pick = function renderPromptBp7Pick(s, myId, pr) {
  if (!pr || pr.responder !== myId) return false;
  if (pr.type !== 'bp7_pick_cards' && pr.type !== 'bp7_pick_stage_member') return false;
  const ovl = el('overlay-prompt');
  ovl?.classList.remove('open');
  const cands = (pr.candidates || []).filter((c) => c && c.instance_id);

  if (pr.type === 'bp7_pick_stage_member') {
    if (!cands.length) {
      sendAct('resolve_prompt', { choice: 'skip' });
      return true;
    }
    openStageMemberPickById(pr);
    return true;
  }

  const min = Math.max(0, Number(pr.pick_min ?? 0));
  const max = Math.max(1, Number(pr.pick_max ?? 1));
  if (!cands.length) {
    sendAct('resolve_prompt', { card_ids: [] });
    return true;
  }
  const enrich = (c) => (typeof enrichCard === 'function' ? enrichCard(c) : c);
  const cards = cands.map(enrich);
  el('pick-ttl').textContent = promptDisplayTitle(pr, 'Choose card(s)', s);
  el('pick-msg').textContent = promptDisplayText(pr, max === 1
    ? 'Choose 1 card.'
    : `Choose ${min > 0 ? '' : 'up to '}${max} card(s).`, s);
  const g = el('pick-grid');
  g.innerHTML = '';
  const btnOk = el('btn-pick-ok');
  const btnCancel = el('btn-pick-cancel');
  const single = max === 1 && min === 1;
  if (btnOk) btnOk.style.display = single ? 'none' : '';
  if (btnCancel) btnCancel.style.display = single ? 'none' : '';
  G.pickMarked.clear();
  G.pickCtx = single ? null : {
    count: max,
    min,
    onConfirm: (ids) => sendAct('resolve_prompt', { card_ids: ids }),
    onCancel: () => sendAct('resolve_prompt', { card_ids: [], choice: 'skip' }),
  };
  cards.forEach((card) => {
    g.appendChild(mkPickCardEl(card, 'pickcard', () => {
      if (single) {
        closeM('overlay-pick');
        sendAct('resolve_prompt', { card_id: card.instance_id, card_ids: [card.instance_id] });
        return;
      }
      if (G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
      else {
        if (G.pickMarked.size >= max) { toast(t('prompt.selectAtMost', { n: max })); return; }
        G.pickMarked.add(card.instance_id);
        sfxCardPick();
      }
      [...g.children].forEach((c) => c.classList.toggle('sel', G.pickMarked.has(c.dataset.id)));
      el('pick-count').textContent = formatSelectedCount(G.pickMarked.size, max);
    }));
  });
  el('pick-count').textContent = single ? '' : formatSelectedCount(0, max);
  if (!single) syncPickOverlayButtons();
  openM('overlay-pick');
  return true;
};

global.renderPromptSurveilBranch = function renderPromptSurveilBranch(s, myId, pr) {
  const ovl = el('overlay-prompt');
  ovl.classList.remove('open');
  renderSurveilOverlay(pr);
};

global.renderPromptDiscardHandBranch = function renderPromptDiscardHandBranch(s, myId, pr) {
  const ovl = el('overlay-prompt');
  if (typeof isPromptSubmitting === 'function' && isPromptSubmitting(s)) return;
  if (G._deferredHandDrawIids?.size && isLiveSuccessDiscardPrompt(s)) {
    clearLiveSuccessHandDeferral(s);
    // skipPrompt: avoid recursive renderPrompt → openHandPick while rebuilding hand.
    renderGame(s, { skipLog: true, skipPrompt: true });
  }
  ovl.classList.remove('open');
  const me = s.players?.[myId];
  const need = pr.count || 1;
  const forceConfirm = s.phase === 'live_success_effects' || need > 1;
  const promptKey = typeof promptIdentityKey === 'function' ? promptIdentityKey(s) : null;
  openHandPick({
    hand: me?.hand || [],
    count: need,
    min: need,
    title: pr.source_name || t('prompt.discardFromHand'),
    msg: promptDisplayText(pr, (need) === 1
      ? t('prompt.discardOne')
      : t('prompt.discardMany', { count: need }), s),
    allowCancel: false,
    forceConfirm,
    promptKey,
    confirmLabel: forceConfirm && need > 1 ? t('prompt.selectThenConfirm') : undefined,
    onConfirm: (ids) => {
      const payload = (pr.pick_mode === 'deck_top')
        ? { card_ids: ids }
        : { discard_ids: ids };
      sendAct('resolve_prompt', payload);
    },
  });
};

function skillRevealViewerId(myId) {
  const g = global.G;
  return myId || g?.playerId || g?.gameState?.my_id || null;
}

/**
 * Public Reveal popup is opponent-only (spectators still see it).
 * The revealing player already saw the cards in the look/hand prompt and the log.
 */
function shouldShowPublicSkillRevealPopup(rev, myId) {
  if (!rev?.cards?.length) return false;
  if (global.G?.isSpectator) return true;
  const viewer = skillRevealViewerId(myId);
  if (rev.pid && viewer && String(rev.pid) === String(viewer)) return false;
  return true;
}
global.shouldShowPublicSkillRevealPopup = shouldShowPublicSkillRevealPopup;

global.showPublicSkillRevealOverlay = function showPublicSkillRevealOverlay(rev, myId){
  if (!shouldShowPublicSkillRevealPopup(rev, myId)) return;
  const ovl = el('overlay-skill-reveal');
  const grid = el('skill-reveal-grid');
  const ttl = el('skill-reveal-ttl');
  const msg = el('skill-reveal-msg');
  if (!ovl || !grid) return;
  const who = (rev.pid && myId && rev.pid !== myId) ? 'Opponent revealed' : 'Revealed';
  if (ttl) ttl.textContent = who;
  if (msg) {
    const src = rev.source_name ? ` (${rev.source_name})` : '';
    msg.textContent = `${rev.cards.length} card${rev.cards.length === 1 ? '' : 's'}${src}`;
  }
  grid.innerHTML = '';
  rev.cards.forEach((raw) => {
    const card = (typeof enrichCard === 'function') ? enrichCard(raw) : raw;
    if (typeof mkPickCardEl === 'function') {
      grid.appendChild(mkPickCardEl(card, 'prompt-look-card pickcard', null));
    }
  });
  ovl.classList.add('open');
  ovl.setAttribute('aria-hidden', 'false');
  const hold = 1800 + Math.min(4, rev.cards.length) * 400;
  clearTimeout(G._skillRevealTimer);
  G._skillRevealTimer = setTimeout(() => {
    ovl.classList.remove('open');
    ovl.setAttribute('aria-hidden', 'true');
  }, hold);
};

global.showPublicSkillRevealFromState = function showPublicSkillRevealFromState(s, myId){
  const rev = s?.skill_reveals;
  if (!rev?.cards?.length) return;
  if (s?.spectate_stream_waiting) return;
  if (rev.turn != null && s?.turn != null && Number(rev.turn) !== Number(s.turn)) return;
  const ids = (rev.cards || []).map(c => c.instance_id).join(',');
  const key = `${rev.seq}:${rev.pid}:${ids}`;
  const idKey = `${rev.pid}:${ids}`;
  if (!G._shownSkillRevealKeys) G._shownSkillRevealKeys = new Set();
  if (G._lastSkillRevealKey === key || G._shownSkillRevealKeys.has(key) || G._shownSkillRevealKeys.has(idKey)) {
    return;
  }
  G._lastSkillRevealKey = key;
  G._shownSkillRevealKeys.add(key);
  G._shownSkillRevealKeys.add(idKey);
  showPublicSkillRevealOverlay(rev, myId);
};

global.renderPrompt = function renderPrompt(s, myId){
  if (typeof showPublicSkillRevealFromState === 'function') {
    showPublicSkillRevealFromState(s, myId);
  }
  let pr=s.pending_prompt;
  const viewerId = myId;
  const replayViewing = typeof global.isReplayViewing === 'function' && global.isReplayViewing();
  const ovl=el('overlay-prompt');
  if (global.G?.isSpectator) {
    ovl?.classList.remove('open');
    hideTextAnswerPrompt();
    hidePromptEffectText();
    hidePromptLookCards();
    closeM('overlay-hand-pick');
    closeM('overlay-pick');
    closeM('overlay-heart');
    closeM('overlay-surveil');
    return;
  }
  if (replayViewing && pr) {
    global.G._promptSubmitKey = null;
    if (pr.responder !== viewerId) {
      ovl?.classList.remove('open');
      hideTextAnswerPrompt();
      hidePromptEffectText();
      hidePromptLookCards();
      closeM('overlay-hand-pick');
      closeM('overlay-pick');
      closeM('overlay-heart');
      closeM('overlay-surveil');
      global.syncReplayPromptReadOnlyUi?.(false);
      return;
    }
    scheduleReplayPromptReadOnlyUi(true);
  } else {
    global.syncReplayPromptReadOnlyUi?.(false);
  }
  const replayReadOnly = isReplayPromptReadOnlyState(s);
  syncAntiSoftlockButton(s, viewerId);
  if (!replayReadOnly) syncPromptSubmitState(s);
  if (!replayReadOnly && isPromptSubmitting(s)) {
    const submittingSurveil = s.pending_prompt?.type === 'surveil_arrange'
      && el('overlay-surveil')?.classList.contains('open');
    if (!submittingSurveil) suppressPromptOverlaysWhileSubmitting();
    return;
  }
  // Force-apply / dismiss clears submit latch but keeps logical lastResolved —
  // never reopen the skill the player already answered this turn.
  if (!replayReadOnly && typeof isPromptAlreadyResolved === 'function' && isPromptAlreadyResolved(s)) {
    suppressPromptOverlaysWhileSubmitting();
    return;
  }
  if(pr) pr=ensurePromptChoices(pr);
  if (pr) TCG_DEBUG.log('prompt', pr.type, { responder: pr.responder, me: viewerId, seq: s.seq, step: pr.step });
  if (!replayReadOnly && pr?.responder === viewerId && shouldDeferPromptForLivePresentation(s, viewerId)) {
    ovl?.classList.remove('open');
    if (pr.type === 'effect_discard_hand') closeM('overlay-hand-pick');
    return;
  }
  const incomingPromptKey = pr ? promptIdentityKey(s) : null;
  const openHandPromptKey = G.handPickCtx?.promptKey || null;
  if (openHandPromptKey && el('overlay-hand-pick')?.classList.contains('open')) {
    if (incomingPromptKey === openHandPromptKey) {
      // A poll re-rendered the same prompt while its card picker was open.
      // Preserve the picker and selection instead of stacking the branch dialog over it.
      ovl?.classList.remove('open');
      return;
    }
    // The server advanced to a genuinely different prompt. Drop only the stale
    // client picker; never invoke its Cancel callback as a gameplay decision.
    closeM('overlay-hand-pick');
    G.handPickCtx = null;
    G.pickMarked.clear();
  }
  if(pr?.type==='surveil_arrange'&&pr.responder===myId){
    renderPromptSurveilBranch(s, myId, pr);
    return;
  }
  if ((pr?.type === 'live_success_order_sources' || pr?.type === 'live_start_order_sources')
      && pr.responder === myId) {
    openLiveSuccessOrderPick(pr);
    return;
  }
  closeM('overlay-surveil');
  if (renderPromptBp7Pick(s, myId, pr)) return;
  if ((pr?.type === 'optional_discard_activate_wait_blade' || pr?.type === 'optional_discard_activate_wait_hearts')
      && pr.step === 'pick_wait' && pr.responder === myId) {
    ovl.classList.remove('open');
    const members = (pr.candidates || []).filter(c => c && c.slot);
    if (!members.length) {
      sendAct('resolve_prompt', { choice: (pr.wait_slots || [])[0] || 'skip' });
      return;
    }
    openStageSlotPick({
      ...pr,
      candidates: members,
      prompt: pt('prompt.activateWaitMember'),
    });
    return;
  }
  if (pr?.type === 'wait_other_group_draw' && pr.responder === myId) {
    ovl.classList.remove('open');
    const members = pr.stage_members || [];
    if (!members.length) {
      sendAct('resolve_prompt', { choice: 'skip' });
      return;
    }
    openOppActiveMemberPick({
      ...pr,
      stage_members: members,
      prompt: promptDisplayText(pr, `Choose 1 other Active ${pr.group || 'Nijigasaki'} Member to put into Wait.`, s),
    });
    return;
  }
  if (pr?.type === 'optional_wait_group_member_draw_discard' && pr.step === 'pick_member' && pr.responder === myId) {
    ovl.classList.remove('open');
    const members = pr.stage_members || [];
    if (!members.length) {
      sendAct('resolve_prompt', { choice: 'no' });
      return;
    }
    openOppActiveMemberPick({
      ...pr,
      stage_members: members,
      prompt: promptDisplayText(pr, `Choose 1 ${pr.group || 'Nijigasaki'} Member to put into Wait.`, s),
    });
    return;
  }
  if (pr?.type === 'optional_wait_group_member_blade' && pr.responder === myId) {
    if (pr.step === 'pick_member') {
      ovl.classList.remove('open');
      const members = pr.stage_members || [];
      if (!members.length) {
        sendAct('resolve_prompt', { choice: 'no' });
        return;
      }
      openOppActiveMemberPick({
        ...pr,
        stage_members: members,
        prompt: promptDisplayText(pr, `Choose 1 ${pr.group || 'Nijigasaki'} Member to put into Wait.`, s),
      });
      return;
    }
  }
  if (pr?.type === 'optional_wait_up_to_group_live_score' && pr.step === 'pick_members' && pr.responder === myId) {
    ovl.classList.remove('open');
    openMemberWaitPick({
      ...pr,
      max_members: pr.max_wait || pr.ability?.max_wait || 3,
      min_members: 0,
      stage_members: pr.stage_members || [],
    }, myId);
    return;
  }
  if (pr?.type === 'activate_members_pick' && pr.responder === myId) {
    ovl.classList.remove('open');
    const cands = pr.candidates || [];
    if (!cands.length) {
      sendAct('resolve_prompt', { choice: 'skip' });
      return;
    }
    openStageMemberPickById(pr);
    return;
  }
  if (pr?.type === 'auto_on_ally_wait_activate_blade' && pr.responder === myId) {
    if (pr.step === 'discard') {
      ovl.classList.remove('open');
      const me = s.players?.[myId];
      const need = pr.discard_count || 1;
      openHandPick({
        hand: me?.hand || [],
        count: need,
        title: pr.source_name || 'Discard',
        msg: promptDisplayText(pr, need === 1
          ? 'Choose 1 card to put into the Waiting Room.'
          : `Choose ${need} cards to put into the Waiting Room.`, s),
        onConfirm: (ids) => sendAct('resolve_prompt', { discard_ids: ids }),
      });
      return;
    }
  }
  if(pr?.type==='effect_discard_hand'&&pr.responder===myId){
    renderPromptDiscardHandBranch(s, myId, pr);
    return;
  }
  if(pr?.type==='blade_per_discarded_pick_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    const cands=pr.candidates||[];
    if(!cands.length){
      sendAct('resolve_prompt',{choice:'skip'});
      return;
    }
    openStageMemberPickById(pr);
    return;
  }
  if(pr?.type==='live_cost_from_subunit_pick'&&pr.responder===myId){
    // Aurora Kosuzu (HS-bp5-005): after DOLLCHESTRA discard, pick Stage subunit Member.
    ovl.classList.remove('open');
    const cands=pr.candidates||[];
    if(!cands.length){
      sendAct('resolve_prompt',{choice:'skip'});
      return;
    }
    openStageSlotPick(pr);
    return;
  }
  if(pr?.type==='pick_member_grant_hearts'&&pr.responder===myId){
    // Stellar Stream (+ other grant-hearts picks): Stage Member select.
    ovl.classList.remove('open');
    const cands=pr.candidates||[];
    if(!cands.length){
      sendAct('resolve_prompt',{choice:'skip'});
      return;
    }
    openStageMemberPickById(pr);
    return;
  }
  if(pr?.type==='wait_pick_member_grant_live_score'&&pr.responder===myId){
    // Chika (PL!S-bp3-001-R＋): Wait 1 Stage Member → that Member gains +1 Live total score.
    ovl.classList.remove('open');
    const raw=pr.candidates||[];
    const candidates=raw.map(c=>{
      if(c&&c.summary&&typeof c.summary==='object'){
        return {...c.summary, slot:c.slot, instance_id:c.instance_id||c.summary.instance_id};
      }
      return c;
    }).filter(c=>c&&c.slot);
    if(!candidates.length){
      if(typeof toast==='function'){
        toast(pt('prompt.noValidTargets')||'No valid Member on Stage.', 2800);
      }
      sendAct('resolve_prompt',{choice:'cancel'});
      return;
    }
    openStageSlotPick({...pr, candidates});
    return;
  }
  if(pr?.type==='buff_member_matching_discarded_group'&&pr.responder===myId){
    // Rurino (HS-bp5-003): after Live Start discard, pick matching-group Member for ♡.
    ovl.classList.remove('open');
    const cands=pr.candidates||[];
    if(!cands.length){
      sendAct('resolve_prompt',{choice:'skip'});
      return;
    }
    openStageSlotPick(pr);
    return;
  }
  if(pr?.type==='pick_same_name_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    openMemberWaitPick({...pr, max_members:1, min_members:1}, myId);
    return;
  }
  if(pr?.type==='pick_member_return_energy'&&pr.responder===myId){
    ovl.classList.remove('open');
    openPickMemberReturnEnergy(pr);
    return;
  }
  if(pr?.type==='wait_members_pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    openMemberWaitPick(pr, myId);
    return;
  }
  if(pr?.type==='wait_subunit_member_pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    openMemberWaitPick({...pr, min_members:1}, myId);
    return;
  }
  if(pr?.type==='opp_pick_hidden_hand'&&pr.responder===myId){
    ovl.classList.remove('open');
    openHiddenHandPick(pr);
    return;
  }
  if(pr?.type==='opp_pick_stage_active'&&pr.responder===myId){
    ovl.classList.remove('open');
    openOppActiveMemberPick(pr);
    return;
  }
  if((pr?.type==='surveil_pick_one_deck_top'||pr?.type==='surveil_pick_one_hand_rest_top'
    ||pr?.type==='surveil_pick_one'||pr?.type==='surveil_pick_one_hand_rest_wr')&&pr.responder===myId){
    ovl.classList.remove('open');
    openSurveilPickOne(pr);
    return;
  }
  if(pr?.type==='surveil2_mus_ability_choice'&&pr.responder===myId){
    ovl.classList.remove('open');
    const looked = pr.look_cards || [];
    const eligible = (pr.candidates || []).map(c => c.instance_id).filter(Boolean);
    if (!looked.length || !eligible.length) {
      sendAct('resolve_prompt', { choice: 'skip' });
      return;
    }
    openLookedDeckPick({
      ...pr,
      candidates: looked,
      pick_count: 1,
      optional: true,
      eligible_ids: eligible,
      prompt: promptDisplayText(pr, 'Look at the top 2 cards. You may add 1 μ\'s card to your hand, or send both to the Waiting Room.', s),
    });
    return;
  }
  if(pr?.type==='optional_leave_mus_score_add_wr_live'&&pr.step==='pick_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    openStageMemberPickById(pr);
    return;
  }
  if((pr?.type==='pick_named_member_blade'||pr?.type==='pick_member_cost_bonus')&&pr.responder===myId){
    ovl.classList.remove('open');
    openHandPick({
      hand: pr.candidates||[],
      count: 1,
      min: 1,
      title: pr.source_name||pt('prompt.chooseMemberTitle'),
      msg: promptDisplayText(pr, 'Choose 1 Member on your Stage.', s),
      onConfirm: (picked)=>{
        const c=(pr.candidates||[]).find(x=>x.instance_id===picked[0]);
        sendAct('resolve_prompt',{slot:c?.slot||'center'});
      },
    });
    return;
  }
  if(pr?.type==='sbp6_leave_play_wr_slot'&&pr.step==='pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const ids=new Set((pr.candidates||[]).map(c=>c.instance_id));
    const pool=(me?.waiting_room||[]).filter(c=>ids.has(c.instance_id));
    openActivateWrMemberPick({
      ...pr,
      candidates: pool.length ? pool.map(enrichCard) : (pr.candidates||[]),
      prompt: promptDisplayText(pr, 'Choose 1 Aqours Member from your Waiting Room.', s),
    });
    return;
  }
  if(pr?.type==='hs_pick_wr_live_to_zone'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrLivePick(pr, { state:s, myId });
    return;
  }
  if(pr?.type==='optional_named_live_zone_from_hand'&&pr.responder===myId){
    if(pr.step==='pick_hand'){
      ovl.classList.remove('open');
      const ids=new Set((pr.candidates||[]).map(c=>c.instance_id));
      const me=s.players?.[myId];
      openHandPick({
        hand:(me?.hand||[]).filter(c=>ids.has(c.instance_id)),
        count:1,
        forceConfirm:true,
        title:pr.source_name||'Live',
        msg:promptDisplayText(pr, 'Choose 1 matching Live card from your hand to place face-up.', s),
        onConfirm:(picked)=> sendAct('resolve_prompt',{card_id:picked[0]}),
      });
      return;
    }
  }
  if(pr?.type==='pick_group_member_blade_faceup'&&pr.responder===myId){
    ovl.classList.remove('open');
    const cands=pr.candidates||[];
    if(!cands.length){
      sendAct('resolve_prompt',{choice:'skip'});
      return;
    }
    openStageSlotPick({...pr, candidates:cands});
    return;
  }
  if(pr?.type==='hs_leave_play_wr_slot'&&pr.step==='pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const ids=new Set((pr.candidates||[]).map(c=>c.instance_id));
    const pool=(me?.waiting_room||[]).filter(c=>ids.has(c.instance_id));
    openActivateWrMemberPick({
      ...pr,
      candidates: pool.length ? pool.map(enrichCard) : (pr.candidates||[]),
      prompt: promptDisplayText(pr, 'Choose 1 Member from your Waiting Room.', s),
    });
    return;
  }
  if(pr?.type==='sbp6_pick_members_live_score'&&pr.responder===myId){
    ovl.classList.remove('open');
    const max=pr.max_pick||2;
    openHandPick({
      hand: pr.candidates||[],
      count: max,
      min: 1,
      title: pr.source_name||pt('prompt.chooseMembersTitle'),
      msg: promptDisplayText(pr, `Choose up to ${max} Member(s).`, s),
      onConfirm: (picked)=> sendAct('resolve_prompt',{card_ids:picked}),
    });
    return;
  }
  if(pr?.type==='sbp5_pick_yell_members'&&pr.responder===myId){
    ovl.classList.remove('open');
    const max=pr.max_pick||2;
    openHandPick({
      hand: pr.candidates||[],
      count: max,
      min: 1,
      title: pr.source_name||pt('prompt.chooseMembersTitle'),
      msg: promptDisplayText(pr, `Choose up to ${max} Yell Member(s).`, s),
      onConfirm: (picked)=> sendAct('resolve_prompt',{card_ids:picked}),
    });
    return;
  }
  if(pr?.type==='pick_wr_live_deck_top'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrLivePick(pr);
    return;
  }
  if(pr?.type==='pick_judge_success_live'&&pr.responder===myId){
    ovl.classList.remove('open');
    openJudgeSuccessLivePick({
      ...pr,
      prompt: promptDisplayText(pr, 'prompt.successLivePickMsg', s),
      source_name: pr.source_name || pt('prompt.successLivePickTitle'),
    }, { state: s, myId });
    return;
  }
  if(pr?.type==='replace_success_with_wr_live'&&pr.responder===myId){
    const step=pr.step||'confirm';
    if(step==='pick_wr'){
      ovl.classList.remove('open');
      openWrLivePick({
        ...pr,
        prompt: promptDisplayText(pr, 'Choose 1 Live from Waiting Room for Success.', s),
      }, { state: s, myId });
      return;
    }
    // confirm: fall through to yes/no choice buttons
  }
  if((pr?.type==='pick_wr_to_hand'||pr?.type==='pick_wr_leave_stage_add')&&pr.responder===myId){
    ovl.classList.remove('open');
    const filter = wrPickCfgFromPrompt(pr).filter;
    const pickOpts = { state: s, myId };
    if(filter==='live') openWrLivePick(pr, pickOpts);
    else openActivateWrMemberPick(pr, pickOpts);
    return;
  }
  if((pr?.type==='hsbp6_pick_wr_live_and_member'||pr?.type==='pl_muse_wr_pick_sequence')&&pr.responder===myId){
    ovl.classList.remove('open');
    const filter = pr.wr_pick_cfg?.filter || (pr.step==='pick_live' ? 'live' : 'member');
    const pickOpts = {
      state: s,
      myId,
      onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
    };
    const wrapped = {
      ...pr,
      wr_pick_cfg: { ...(pr.wr_pick_cfg || {}), filter },
      pick_count: pr.pick_count == null ? 1 : pr.pick_count,
    };
    if(filter==='live') openWrLivePick(wrapped, pickOpts);
    else openActivateWrMemberPick(wrapped, pickOpts);
    return;
  }
  if(pr?.type==='shuffle_named_from_waiting_pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    const max=pr.max_pick||pr.ability?.max_total||6;
    openHandPick({
      hand: pr.candidates||[],
      count: max,
      min: 1,
      title: pr.source_name||'Waiting Room',
      msg: promptDisplayText(pr, `Choose up to ${max} matching Member card(s) from your Waiting Room.`, s),
      onConfirm: (ids)=>sendAct('resolve_prompt',{card_ids:ids}),
      onCancel: ()=>{
        G._promptSubmitKey=null;
        if(G.gameState) renderPrompt(G.gameState,myId);
      },
      forceConfirm: true,
    });
    return;
  }
  if(pr?.type==='pick_wr_members_deck_top'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrMembersDeckTopPick(pr);
    return;
  }
  if(pr?.responder===myId
      && (pr.type==='optional_discard_mill_add_wr_subunit_live'
        || pr.type==='both_shuffle_wr_members_deck_bottom_threshold'
        || pr.type==='optional_discard_add_cb_member_hs_live')
      && pr.step==='pick_wr_live'){
    ovl.classList.remove('open');
    closeM('overlay-hand-pick');
    G._promptSubmitKey = null;
    G._resolvePromptSentKey = null;
    G._lastResolvedPromptKey = null;
    openWrLivePick(pr, { state:s, myId });
    return;
  }
  if(pr?.type==='optional_discard_add_cb_member_hs_live'
      && pr.responder===myId&&pr.step==='pick_wr_member'){
    ovl.classList.remove('open');
    openActivateWrMemberPick(pr, { state:s, myId });
    return;
  }
  if(pr?.type==='pick_live_match_success_heart'&&pr.responder===myId){
    ovl.classList.remove('open');
    openLiveZonePick(pr, { state:s, myId });
    return;
  }
  if(pr?.type==='ssd1_play_wr_empty'&&pr.step==='pick_slot'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Play Member', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.chooseArea', s);
    const box=el('prompt-btns'); box.innerHTML='';
    (pr.slots||[]).forEach(slot=>{
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=slotLabel(slot);
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{slot}); };
      box.appendChild(b);
    });
    ovl.classList.add('open');
    return;
  }
  if(pr?.type==='both_wr_member_to_empty_stage'&&pr.step==='pick_slot'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Waiting Room Member', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.chooseEmptyStage', s);
    const box=el('prompt-btns'); box.innerHTML='';
    (pr.slots||[]).forEach(slot=>{
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=slotLabel(slot);
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{slot}); };
      box.appendChild(b);
    });
    ovl.classList.add('open');
    return;
  }
  if(pr?.type==='optional_pay_play_hand_member'&&pr.responder===myId){
    if(pr.step==='pick_slot'){
      ovl.classList.remove('open');
      el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Play Member', s);
      el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.chooseArea', s);
      const box=el('prompt-btns'); box.innerHTML='';
      (pr.slots||[]).forEach(slot=>{
        const b=document.createElement('button');
        b.className='btn-grad';
        b.textContent=slotLabel(slot);
        b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{slot}); };
        box.appendChild(b);
      });
      ovl.classList.add('open');
      return;
    }
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const ab=pr.ability||{};
    const names=ab.names||[];
    const grp=ab.group||'Nijigasaki';
    const maxCost=ab.max_cost??4;
    const anyGroup=!!ab.any_group;
    const hand=global.handMembersMatchingPlayAbilityClient(me?.hand||[], ab, pr.candidates||[]);
    // No legal target (e.g. no Ayumu ≤4 in hand): decline — empty single-tap
    // overlay hides Cancel and softlocks the game.
    if(!hand.length){
      sendAct('resolve_prompt',{choice:'no'});
      return;
    }
    const label=names.length?names.join('/'):(anyGroup?'Member':grp);
    openHandPick({
      hand,
      count: 1,
      title: pr.source_name||'Play Member',
      msg: promptDisplayText(pr, `Choose a ${label} Member (cost ≤${maxCost}) from hand.`, s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{choice:'yes',card_id:ids[0]}),
      onCancel: ()=> sendAct('resolve_prompt',{choice:'no'})
    });
    return;
  }
  if(pr?.type==='pick_yell_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    const yellCards = yellRevealPickCards(pr, s, myId);
    if (!yellCards.length) {
      sendAct('resolve_prompt', { choice: 'skip' });
      return;
    }
    openYellRevealPick(pr, {
      state: s,
      myId,
      onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
    });
    return;
  }
  if(pr?.type==='auto_yell_mill_extra_yell'&&pr.responder===myId){
    ovl.classList.remove('open');
    const owner = pr.owner || myId;
    const cands = pr.candidates || [];
    const idSet = new Set(cands.map(c => c.instance_id).filter(Boolean));
    // Prefer live board Yell, but never treat a missing yell_reveal (refresh / filter
    // race) as decline — server already listed eligible cards on the prompt.
    const boardYell = s.yell_reveal?.[owner]
      || s._yell_reveal_snapshot?.[owner]
      || s.players?.[owner]?.yell_cards
      || [];
    let yellPool = boardYell.filter(c => idSet.has(c.instance_id));
    if (!yellPool.length && cands.length) {
      yellPool = cands
        .map(c => (typeof enrichCard === 'function' ? enrichCard(c) : c))
        .filter(c => c?.instance_id);
    }
    if (!yellPool.length) {
      // No candidates from server either — nothing to mill; skip without auto-spam.
      if (!cands.length) {
        sendAct('resolve_prompt', { choice: 'no' });
      } else {
        toast(pt('prompt.noYellMill'), 3200);
      }
      return;
    }
    const max = pr.max_pick || yellPool.length;
    el('prompt-ttl').textContent = promptDisplayTitle(pr, 'Yell mill', s);
    el('prompt-msg').textContent = promptDisplayText(pr,
      `Put up to ${max} non-Blade-heart Hasunosora Yell card(s) into the Waiting Room for extra Yell?`, s);
    const box = el('prompt-btns');
    box.innerHTML = '';
    const labels = pr.choice_labels || ['Yes', 'No — Skip'];
    labels.forEach((label, i) => {
      const b = document.createElement('button');
      b.className = 'btn-grad';
      b.textContent = label;
      b.onclick = () => {
        closeM('overlay-prompt');
        if (i !== 0) {
          sendAct('resolve_prompt', { choice: 'no' });
          return;
        }
        openHandPick({
          hand: yellPool.map(enrichCard),
          count: max,
          min: 1,
          promptKey: promptIdentityKey(s),
          title: pr.source_name || 'Yell mill',
          msg: promptDisplayText(pr, `Choose up to ${max} Yell card(s) to mill.`, s),
          onConfirm: (ids) => {
            if (ids.length) sendAct('resolve_prompt', { choice: 'yes', card_ids: ids });
          },
          // Cancel only returns to Kurage's explicit Yes/No branch. It must not
          // make the irreversible "No — Skip" choice on the player's behalf.
          onCancel: () => {
            G._promptSubmitKey = null;
            if (G.gameState) renderPrompt(G.gameState, myId);
          },
          forceConfirm: true,
        });
      };
      box.appendChild(b);
    });
    ovl.classList.add('open');
    return;
  }
  if(pr?.type==='pick_wr_distinct_lives_opp_choice'&&pr.responder===myId){
    ovl.classList.remove('open');
    const need=pr.pick_count||2;
    openHandPick({
      hand: pr.candidates||[],
      count: need,
      min: need,
      title: pr.source_name||'Waiting Room',
      msg: promptDisplayText(pr, `Choose ${need} Live cards with different names from your Waiting Room.`, s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{card_ids:ids}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState,myId); },
      forceConfirm: true,
    });
    return;
  }
  if(pr?.type==='stack_energy_zone_pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    const need=Math.max(1, Number(pr.energy_count||pr.max_pick||1));
    const hand=(pr.candidates&&pr.candidates.length)
      ? pr.candidates
      : (s.players?.[myId]?.energy_zone||[]);
    openHandPick({
      hand,
      count: need,
      min: need,
      title: pr.source_name||'Energy',
      msg: promptDisplayText(pr, `Choose ${need} Energy from your Energy Zone to place under this Member.`, s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{energy_ids:ids, card_ids:ids}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState,myId); },
      forceConfirm: true,
    });
    return;
  }
  if(pr?.type==='activated_discard_trigger_on_enter'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const maxCost=Number(pr.max_cost??4);
    const grp=pr.group||'Superstar';
    const hand=(pr.candidates&&pr.candidates.length)
      ? pr.candidates
      : (me?.hand||[]).filter(c=>{
          if((c.group||'')!==grp) return false;
          if(c.card_type!=='メンバー'&&c.card_type_en!=='Member') return false;
          return Number(c.cost||0)<=maxCost;
        });
    openHandPick({
      hand,
      count: 1,
      min: 1,
      title: pr.source_name||'Discard',
      msg: promptDisplayText(pr, `Put 1 Member with cost ${maxCost} or less from your hand into the Waiting Room.`, s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{card_id:ids[0]}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState,myId); },
      forceConfirm: true,
    });
    return;
  }
  if(pr?.type==='spbp2_discard_liella_choice'&&pr.responder===myId){
    if(pr.step==='pick_hand'){
      ovl.classList.remove('open');
      const me=s.players?.[myId];
      const grp=pr.group||'Superstar';
      openHandPick({
        hand: (me?.hand||[]).filter(c=>(c.group||'')===grp),
        count: 1,
        min: 1,
        title: pr.source_name||'Discard',
        msg: promptDisplayText(pr, `Put 1 ${grp} card from your hand into the Waiting Room.`, s),
        onConfirm: (ids)=> sendAct('resolve_prompt',{card_id:ids[0]}),
        onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState,myId); },
      });
      return;
    }
    if(pr.step==='pick_member'){
      ovl.classList.remove('open');
      openHandPick({
        hand: pr.candidates||[],
        count: 1,
        min: 1,
        title: pr.source_name||pt('prompt.chooseMemberTitle'),
        msg: promptDisplayText(pr, 'Choose 1 Member on your Stage.', s),
        onConfirm: (picked)=>{
          const c=(pr.candidates||[]).find(x=>x.instance_id===picked[0]);
          sendAct('resolve_prompt',{slot:c?.slot||'center'});
        },
      });
      return;
    }
  }
  if(pr?.type==='pick_looked_deck_hand'&&pr.responder===myId){
    ovl.classList.remove('open');
    closeM('overlay-hand-pick');
    G._promptSubmitKey = null;
    openLookedDeckPick(pr);
    return;
  }
  if(pr?.type==='pay_energy_reveal_live_wr_superset'&&pr.responder===myId){
    ovl.classList.remove('open');
    if(pr.step==='pick_wr_live'){
      openWrLivePick(pr);
      return;
    }
    const lives=(pr.candidates||[]).filter(c=>c.card_type==='ライブ');
    if(!lives.length){
      sendAct('resolve_prompt',{choice:'no'});
      return;
    }
    openHandPick({
      hand: lives,
      count: 1,
      min: 1,
      title: pr.source_name||'Reveal Live',
      msg: promptDisplayText(pr, 'Choose 1 Live card from your hand to reveal.', s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{card_id: ids[0]}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); },
    });
    return;
  }
  if(pr?.type==='live_success_pick_yell_live'&&pr.responder===myId){
    ovl.classList.remove('open');
    const yellLives = yellRevealPickCards(pr, s, myId);
    if (!yellLives.length) {
      sendAct('resolve_prompt', { choice: 'skip' });
      return;
    }
    openYellRevealPick(pr, {
      state: s,
      myId,
      onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
    });
    return;
  }
  if(pr?.type==='live_success_pick_yell_deck_top'&&pr.responder===myId){
    ovl.classList.remove('open');
    const yellCards = yellRevealPickCards(pr, s, myId);
    if (!yellCards.length) {
      const cands = (pr.candidates || []).filter(c => c?.instance_id);
      if (!cands.length) {
        sendAct('resolve_prompt', { choice: 'skip' });
        return;
      }
      openHandPick({
        hand: cands,
        count: 1,
        min: 1,
        title: pr.source_name || pt('prompt.deckTopTitle'),
        msg: promptDisplayText(pr, 'prompt.deckTopMsg', s),
        onConfirm: (picked) => sendAct('resolve_prompt', { card_id: picked[0] }),
        onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
      });
      return;
    }
    openYellRevealPick(pr, {
      state: s,
      myId,
      onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
    });
    return;
  }
  if(pr?.type==='play_wr_members_combined_cost'&&pr.responder===myId){
    ovl.classList.remove('open');
    const maxCount = pr.max_count || 2;
    const maxCombined = pr.max_combined_cost || 4;
    openHandPick({
      hand: pr.candidates || [],
      count: maxCount,
      min: 0,
      title: pr.source_name || pt('prompt.wrPickTitle'),
      msg: promptDisplayText(pr, `Choose up to ${maxCount} Member(s) (combined cost ≤${maxCombined}).`, s),
      allowCancel: true,
      forceConfirm: maxCount > 1,
      onConfirm: (ids) => {
        const picked = (ids || []).map(id => (pr.candidates || []).find(c => c.instance_id === id)).filter(Boolean);
        const total = picked.reduce((sum, c) => sum + Number(c.cost || 0), 0);
        if (total > maxCombined) {
          toast(pt('prompt.combinedCostMax', { n: maxCombined }), 2800, true);
          return;
        }
        if (!ids?.length) {
          sendAct('resolve_prompt', { choice: 'skip' });
          return;
        }
        sendAct('resolve_prompt', { card_ids: ids });
      },
      onCancel: () => sendAct('resolve_prompt', { choice: 'skip' }),
    });
    return;
  }
  if(pr?.type==='mandatory_discard_look_reveal'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    openHandPick({
      hand: me?.hand||[],
      count: pr.discard_count||1,
      title: pr.source_name||'Discard',
      msg: promptDisplayText(pr, 'Choose a card to send to the Waiting Room.', s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{discard_ids:ids}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr?.type==='mandatory_discard_group_branch'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const need=pr.discard_count||pr.max_pick||1;
    openHandPick({
      hand: me?.hand||[],
      count: need,
      min: need,
      title: pr.source_name||'Discard',
      msg: promptDisplayText(pr, `Choose ${need} card(s) to send to the Waiting Room.`, s),
      allowCancel: false,
      onConfirm: (ids)=> sendAct('resolve_prompt',{discard_ids:ids}),
    });
    return;
  }
  if(pr?.type==='mandatory_discard_color_threshold_reveal5'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const need=pr.discard_count||pr.max_pick||1;
    openHandPick({
      hand: me?.hand||[],
      count: need,
      min: need,
      title: pr.source_name||'Discard',
      msg: promptDisplayText(pr, `Choose ${need} card(s) to send to the Waiting Room, then choose a heart color.`, s),
      allowCancel: false,
      onConfirm: (ids)=> sendAct('resolve_prompt',{discard_ids:ids}),
    });
    return;
  }
  if(pr?.type==='maki_reveal5_pick_mus'&&pr.responder===myId){
    ovl.classList.remove('open');
    const cards=pr.candidates||[];
    if(!cards.length){
      sendAct('resolve_prompt',{choice:'skip'});
      return;
    }
    openLookedDeckPick({
      ...pr,
      candidates: cards,
      pick_count: 1,
      eligible_ids: cards.map(c=>c.instance_id).filter(Boolean),
    });
    return;
  }
  if(pr?.type==='optional_wait_self_look_reveal'&&pr.step==='discard'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const need=pr.discard_count||pr.ability?.discard||1;
    openHandPick({
      hand: me?.hand||[],
      count: need,
      min: need,
      title: pr.source_name||'Discard',
      msg: promptDisplayText(pr, `Discard ${need} card(s) from your hand to look at your deck.`, s),
      allowCancel: false,
      onConfirm: (ids)=> sendAct('resolve_prompt',{discard_ids:ids}),
    });
    return;
  }
  if(pr?.type==='reveal_hand_member_cost_live_score'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    openHandPick({
      hand: (me?.hand||[]).filter(c=>c.card_type==='メンバー'),
      count: (me?.hand||[]).length,
      min: 0,
      title: pr.source_name||'Reveal Members',
      msg: promptDisplayText(pr, 'Select Member cards to reveal from hand.', s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{card_ids:ids}),
      onCancel: ()=> sendAct('resolve_prompt',{card_ids:[]})
    });
    return;
  }
  if(pr?.type==='on_enter_draw_swap_area'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Move Member', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.chooseArea', s);
    const box=el('prompt-btns'); box.innerHTML='';
    (pr.slots||[]).forEach(slot=>{
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=slotLabel(slot);
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:slot}); };
      box.appendChild(b);
    });
    ovl.classList.add('open');
    return;
  }
  if(pr?.type==='optional_wr_member_reenter'&&pr.responder===myId&&pr.step==='pick_stage'){
    ovl.classList.remove('open');
    openStageSlotPick({...pr, candidates: pr.candidates});
    return;
  }
  if(pr?.type==='activate_energy_up_to'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Activate Energy', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.howManyEnergyActivate', s);
    const box=el('prompt-btns'); box.innerHTML='';
    const max=pr.max||6;
    for(let i=0;i<=max;i++){
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=String(i);
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:String(i)}); };
      box.appendChild(b);
    }
    ovl.classList.add('open');
    return;
  }
  if(pr?.type==='pick_baton_entered_member_heart'&&pr.responder===myId){
    ovl.classList.remove('open');
    openStageSlotPick(pr);
    return;
  }
  if((pr?.type==='pick_named_members_grant_hearts'||pr?.type==='pick_named_members_grant_blade')&&pr.responder===myId){
    ovl.classList.remove('open');
    openStageSlotPick(pr);
    return;
  }
  if(pr?.type==='optional_reveal_live_deck_bottom_surveil'&&pr.step==='pick_hand_live'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    openHandPick({
      hand: (me?.hand||[]).filter(c=>c.card_type==='ライブ'),
      count: 1,
      title: pr.source_name||'Reveal Live',
      msg: promptDisplayText(pr, 'Choose 1 Live card from your hand.', s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{card_id:ids[0]}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr?.type==='optional_wr_to_deck_top'&&pr.step==='pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    const cards=(pr.candidates||[]).filter(c=>c&&c.instance_id);
    if(!cards.length){
      sendAct('resolve_prompt',{choice:'no'});
      return;
    }
    openHandPick({
      hand: cards,
      count: 1,
      min: 1,
      title: pr.source_name||pt('prompt.wrPickTitle')||'Waiting Room',
      msg: promptDisplayText(pr, 'Choose 1 card from your Waiting Room to put on top of your deck.', s),
      onConfirm: (picked)=> sendAct('resolve_prompt',{choice:'yes',card_id:picked[0]}),
      onCancel: ()=> sendAct('resolve_prompt',{choice:'no'}),
    });
    return;
  }
  if(pr?.type==='optional_wr_member_deck_top_blade'&&pr.step==='pick_wr_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrLivePick(pr);
    return;
  }
  if(pr?.type==='pos_change_opp_front_pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    openStageSlotPick(pr);
    return;
  }
  if((pr?.type==='optional_wr_member_deck_top_blade'||pr?.type==='live_start_center_cost_choice'||pr?.type==='wait_opponent_stage_pick')
    &&(pr.step==='pick_stage_blade'||pr.step==='pick_opp_wait')&&pr.responder===myId){
    ovl.classList.remove('open');
    // Discard→wait chains latch submit keys on the prior optional_discard_prompt step.
    G._promptSubmitKey = null;
    G._resolvePromptSentKey = null;
    G._lastResolvedPromptKey = null;
    openStageSlotPick(pr);
    return;
  }
  if(pr?.type==='live_start_edel_play_wr'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrLivePick(pr, { state:s, myId });
    return;
  }
  if(pr?.type==='player_choice_wr_live_deck_bottom_draw'&&pr.step==='pick_wr_live'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrLivePick(pr, { state: s, myId });
    return;
  }
  if(pr?.type==='wait_swap_wr_member_center'&&pr.responder===myId){
    ovl.classList.remove('open');
    if(pr.step==='discard_hand'){
      const me=s.players?.[myId];
      openHandPick({
        hand: me?.hand||[],
        count: 1,
        title: pr.source_name||'Discard',
        msg: promptDisplayText(pr, 'Choose 1 card to send to the Waiting Room.', s),
        onConfirm: (ids)=> sendAct('resolve_prompt',{discard_ids:ids}),
        onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
      });
      return;
    }
    if(pr.step==='pick_stage_member'){
      openStageSlotPick(pr);
      return;
    }
    if(pr.step==='pick_wr_member'){
      openWrLivePick(pr);
      return;
    }
  }
  if(pr?.type==='optional_success_wr_live_swap'&&pr.responder===myId){
    // confirm: fall through to Yes/No + effect-text UI (do not open empty "Respond" overlay).
    if(pr.step!=='confirm'){
      ovl.classList.remove('open');
      if(pr.step==='pick_success_live'){
        openSuccessLiveAreaPick(pr, { state:s, myId });
      }else{
        openWrLivePick(pr, { state:s, myId });
      }
      return;
    }
  }
  if(pr?.type==='optional_success_live_swap'&&pr.responder===myId){
    // confirm: fall through so title/msg/buttons are filled (Maki PL!-sd1-006-SD).
    if(pr.step!=='confirm'){
      ovl.classList.remove('open');
      const me=s.players?.[myId];
      if(pr.step==='pick_hand_live'){
        openHandPick({
          hand: (me?.hand||[]).filter(c=>c.card_type==='ライブ'),
          count: 1,
          title: pr.source_name||'Maki Nishikino',
          msg: promptDisplayText(pr, 'Choose 1 Live card from your hand to reveal.', s),
          onConfirm: (ids)=> sendAct('resolve_prompt',{card_id:ids[0]}),
          onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
        });
        return;
      }
      if(pr.step==='pick_success_live'){
        openSuccessLiveAreaPick(pr, { state: s, myId });
        return;
      }
    }
  }
  if(pr?.type==='mandatory_discard_after_draw'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    openHandPick({
      hand: me?.hand||[],
      count: pr.discard_count||1,
      title: pr.source_name||'Discard',
      msg: promptDisplayText(pr, 'Choose card(s) to send to the Waiting Room.', s),
      onConfirm: (ids)=> sendAct('resolve_prompt',{discard_ids:ids}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr?.type==='reveal_hand_named_stack_under'&&pr.responder===myId){
    // Kotori-style optional: Yes/No first. Older mandatory stack prompts open hand pick directly.
    const choices=pr.choices||[];
    if(!isYesNoPromptChoices(choices)){
      ovl.classList.remove('open');
      const ids=new Set((pr.candidates||[]).map(c=>c.instance_id));
      const me=s.players?.[myId];
      openHandPick({
        hand: (me?.hand||[]).filter(c=>ids.has(c.instance_id)),
        count: 1,
        forceConfirm: true,
        title: pr.source_name||'Reveal Member',
        msg: promptDisplayText(pr, 'Choose a matching Member from your hand to stack under this Member.', s),
        onConfirm: (picked)=> sendAct('resolve_prompt',{card_id:picked[0]}),
        onCancel: ()=> sendAct('resolve_prompt',{choice:'no'})
      });
      return;
    }
  }
  if(pr?.type==='activate_wr_member_pick'&&pr.responder===myId){
    if(pr.step==='pick_member'){
      ovl.classList.remove('open');
      openActivateWrMemberPick(pr);
      return;
    }
    if(pr.step==='pick_ability'){
      ovl.classList.remove('open');
      el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Choose ability', s, pr.wr_member_name||pr.source_name);
      el('prompt-msg').textContent=promptDisplayText(pr, 'Choose 1 ability to activate.', s);
      const box=el('prompt-btns'); box.innerHTML='';
      const choices=pr.choices||[];
      const labels=pr.choice_labels||choices;
      choices.forEach((key,i)=>{
        const b=document.createElement('button');
        b.className='btn-grad';
        b.textContent=labels[i]||('Ability '+(i+1));
        b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:String(key)}); };
        box.appendChild(b);
      });
      ovl.classList.add('open');
      return;
    }
    if(pr.step==='pick_discard'){
      ovl.classList.remove('open');
      const me=s.players?.[myId];
      const need=pr.discard_count||1;
      openHandPick({
        hand: me?.hand||[],
        count: need,
        min: need,
        title: pr.wr_member_name||pr.source_name||'Discard',
        msg: promptDisplayText(pr, `Choose ${need} card(s) to send to the Waiting Room.`, s),
        allowCancel: false,
        onConfirm: (picked)=> sendAct('resolve_prompt',{discard_ids:picked}),
      });
      return;
    }
  }
  if(pr?.type==='batch99_stack_wr_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    openBatch99StackWrPick(pr);
    return;
  }
  if(pr?.type==='spbp2_stack_wr_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    openBatch99StackWrPick(pr);
    return;
  }
  if(pr?.type==='spbp2_wait_self_opp_heart_gap'&&pr.responder===myId){
    ovl.classList.remove('open');
    if(pr.step==='confirm'){
      el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Wait chain', s);
      el('prompt-msg').textContent=promptDisplayText(pr, 'Optional Wait effect', s);
      const box=el('prompt-btns'); box.innerHTML='';
      (pr.choice_labels||['Yes','No']).forEach((label,i)=>{
        const b=document.createElement('button');
        b.className='btn-grad';
        b.textContent=label;
        b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:i===0?'yes':'no'}); };
        box.appendChild(b);
      });
      ovl.classList.add('open');
      return;
    }
    openStageSlotPick(pr);
    return;
  }
  if((pr?.type==='spbp2_center_move_choose'||pr?.type==='spbp2_center_move_position')&&pr.responder===myId){
    ovl.classList.remove('open');
    if(pr.type==='spbp2_center_move_position'&&pr.choices?.includes('yes')){
      el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Position change', s);
      el('prompt-msg').textContent=promptDisplayText(pr, 'Position-change this Member?', s);
      const box=el('prompt-btns'); box.innerHTML='';
      ['Yes — Position change','No — Done'].forEach((label,i)=>{
        const b=document.createElement('button');
        b.className='btn-grad';
        b.textContent=label;
        b.onclick=()=>{
          closeM('overlay-prompt');
          if(i===0&&pr.target_slots?.length){
            openStageSlotPick({...pr, candidates:pr.target_slots.map(s=>({slot:s,name_en:slotLabel(s)}))});
          } else {
            sendAct('resolve_prompt',{choice:'no'});
          }
        };
        box.appendChild(b);
      });
      ovl.classList.add('open');
      return;
    }
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Center moved', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'Choose one effect', s);
    const box=el('prompt-btns'); box.innerHTML='';
    const choices=pr.choices||['heart','wait_opp','draw'];
    const labels=pr.choice_labels||choices;
    choices.forEach((c,i)=>{
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=labels[i]||c;
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:c}); };
      box.appendChild(b);
    });
    ovl.classList.add('open');
    return;
  }
  if(pr?.type==='discard_subunit_hand_draw'&&pr.responder===myId){
    ovl.classList.remove('open');
    const ids=new Set((pr.candidates||[]).map(c=>c.instance_id));
    const me=s.players?.[myId];
    const pool=(me?.hand||[]).filter(c=>ids.has(c.instance_id));
    openHandPick({
      hand: pool,
      count: pool.length,
      min: 0,
      title: pr.source_name||'Discard subunit',
      msg: promptDisplayText(pr, 'Choose any number of subunit Members to discard, then draw that many +1.', s),
      onConfirm: (picked)=> sendAct('resolve_prompt',{discard_ids:picked}),
      onCancel: ()=> sendAct('resolve_prompt',{discard_ids:[]})
    });
    return;
  }
  if(pr?.type==='pick_number_reveal_deck_top'&&pr.responder===myId){
    ovl.classList.remove('open');
    if((pr.step||'pick_number')==='resolve_reveal'){
      el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Deck top revealed', s);
      const rev=pr.revealed||{};
      const n=pr.chosen_number;
      el('prompt-msg').textContent=promptDisplayText(pr, `Revealed: ${rev.name_en||rev.name||'card'} (cost ${rev.cost??'?'}). Chosen number: ${n}.`, s);
      const box=el('prompt-btns'); box.innerHTML='';
      if(rev.image||rev.instance_id){
        try{
          const wrap=document.createElement('div');
          wrap.style.cssText='display:flex;justify-content:center;margin:8px 0 12px';
          const img=document.createElement('img');
          img.src=rev.image||'';
          img.alt=rev.name_en||rev.name||'';
          img.style.cssText='max-width:160px;border-radius:8px';
          if(rev.image) wrap.appendChild(img);
          box.appendChild(wrap);
        }catch(_){}
      }
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=pt('prompt.confirm');
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:'confirm'}); };
      box.appendChild(b);
      ovl.classList.add('open');
      return;
    }
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Pick a number', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'Choose a number (0 or higher), then reveal your deck top.', s);
    const box=el('prompt-btns'); box.innerHTML='';
    box.style.cssText='display:flex;flex-wrap:wrap;gap:6px;justify-content:center;max-width:420px';
    (pr.numbers||[0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30]).forEach(num=>{
      const b=document.createElement('button');
      b.className='btn-grad';
      b.style.cssText='min-width:42px;padding:8px 10px';
      b.textContent=String(num);
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:String(num), number:num}); };
      box.appendChild(b);
    });
    if(pr.allow_custom!==false){
      const row=document.createElement('div');
      row.style.cssText='width:100%;display:flex;gap:8px;justify-content:center;margin-top:8px;align-items:center';
      const inp=document.createElement('input');
      inp.type='number';
      inp.min=String(pr.min_number??0);
      inp.max=String(pr.max_number??99);
      inp.placeholder='Custom (e.g. 25)';
      inp.style.cssText='width:140px;padding:8px';
      const go=document.createElement('button');
      go.className='btn-grad';
      go.textContent='Use number';
      go.onclick=()=>{
        const n=Number(inp.value);
        if(!Number.isFinite(n)||n<0||n>99){ toast(pt('prompt.enterNumber0to99')); return; }
        closeM('overlay-prompt');
        sendAct('resolve_prompt',{choice:String(Math.floor(n)), number:Math.floor(n)});
      };
      row.appendChild(inp);
      row.appendChild(go);
      box.appendChild(row);
    }
    ovl.classList.add('open');
    return;
  }
  if((pr?.type==='pick_other_blade_member_bonus'
    ||pr?.type==='pick_other_heart_member_bonus'
    ||pr?.type==='live_start_wr_group_member_count_pick_heart'
    ||pr?.type==='live_start_activate_stage_live_start_ability'
    ||pr?.type==='live_start_edel_note_dual_pick_buff'
    ||pr?.type==='treat_pick_group_member_hearts_as'
    ||pr?.type==='cl1_pick_stage_member_blade'
    ||pr?.type==='score_if_stage_member_hearts'
    ||pr?.type==='opp_member_match_heart_blade')&&pr.responder===myId){
    ovl.classList.remove('open');
    const raw=pr.candidates||[];
    // Back-compat: older nested {slot, summary} shape from Mia compare prompt.
    const candidates=raw.map(c=>{
      if(c&&c.summary&&typeof c.summary==='object'){
        return {...c.summary, slot:c.slot, instance_id:c.instance_id||c.summary.instance_id};
      }
      return c;
    });
    if(!candidates.length){
      sendAct('resolve_prompt',{choice:'skip'});
      return;
    }
    openStageSlotPick({...pr, candidates});
    return;
  }
  if(pr?.type==='optional_pos_change_subunit_blade'&&pr.responder===myId&&pr.step==='pick_target'){
    ovl.classList.remove('open');
    openStageSlotPick({
      ...pr,
      candidates: (pr.target_slots||[]).map(slot=>({slot, name_en: slotLabel(slot)}))
    });
    return;
  }
  if(pr?.type==='optional_activate_wait_subunit_add_live_wr'&&pr.responder===myId&&pr.step==='pick_wait_member'){
    ovl.classList.remove('open');
    if(!(pr.candidates||[]).length){
      sendAct('resolve_prompt',{choice:'no'});
      return;
    }
    openStageMemberPickById({
      ...pr,
      prompt: promptDisplayText(pr, 'Choose 1 Member in Wait to activate.', s),
    });
    return;
  }
  if(pr?.type==='optional_stage_reposition'&&pr.responder===myId&&pr.step==='pick_member'){
    ovl.classList.remove('open');
    if(!(pr.candidates||[]).length){
      sendAct('resolve_prompt',{choice:'no'});
      return;
    }
    openStageMemberPickById({
      ...pr,
      prompt: promptDisplayText(pr, 'Choose 1 Member to Position Change.', s),
    });
    return;
  }
  if((pr?.type==='sbp5_pick_stage_member_blade'||pr?.type==='sbp5_pick_saint_snow_position')&&pr.responder===myId){
    ovl.classList.remove('open');
    if(!(pr.candidates||[]).length){
      sendAct('resolve_prompt',{choice:'skip'});
      return;
    }
    openStageMemberPickById({
      ...pr,
      prompt: promptDisplayText(pr, pr.type==='sbp5_pick_saint_snow_position'
        ? 'Choose 1 Saint Snow Member to Position Change.'
        : 'Choose 1 Aqours Member for +Blade until Live ends.', s),
    });
    return;
  }
  if(pr?.type==='sbp5_position_change_slot'&&pr.responder===myId){
    ovl.classList.remove('open');
    openStageSlotPick({
      ...pr,
      candidates: (pr.target_slots||[]).map(slot=>({slot, name_en: typeof slotLabel==='function'?slotLabel(slot):slot})),
      prompt: promptDisplayText(pr, 'Choose an area to Position Change into.', s),
    });
    return;
  }
  if(pr?.type==='optional_stage_reposition'&&pr.responder===myId&&pr.step==='pick_dest'){
    ovl.classList.remove('open');
    openStageSlotPick({
      ...pr,
      candidates: (pr.target_slots||[]).map(slot=>({slot, name_en: typeof slotLabel==='function'?slotLabel(slot):slot})),
      prompt: promptDisplayText(pr, 'Choose an area to Position Change into.', s),
    });
    return;
  }
  if(pr?.type==='optional_formation_change_group'&&pr.responder===myId&&pr.step==='assign'){
    ovl.classList.remove('open');
    const slots=pr.target_slots||['left','center','right'];
    if(!slots.length){
      sendAct('resolve_prompt',{choice:'no'});
      return;
    }
    openStageSlotPick({
      ...pr,
      candidates: slots.map(slot=>({slot, name_en: typeof slotLabel==='function'?slotLabel(slot):slot})),
      prompt: promptDisplayText(pr, 'Choose an area for this Member.', s),
    });
    return;
  }
  if((pr?.type==='bp5_wr_live_deck_position'||pr?.type==='bp5_pick_kasumi_reveal'||pr?.type==='bp5_discard_pay_wr_live_score'&&pr.step==='pick_live'||pr?.type==='sbp5_pick_revealed_member'||pr?.type==='sbp5_pick_yell_members'||pr?.type==='sbp5_wr_lives_deck_top'||pr?.type==='sbp6_pick_revealed_member'||pr?.type==='sbp6_swap_pick_wr_member'||pr?.type==='sbp6_live_zone_deck_top_hearts'||pr?.type==='sbp6_swap_pick_stage_member'||pr?.type==='ssd1_play_wr_empty'&&pr.step==='pick_wr'||pr?.type==='both_wr_member_to_empty_stage'&&pr.step==='pick_wr'||pr?.type==='ssd1_reveal_group_deck'&&pr.step==='pick_hand'||pr?.type==='spbp5_distinct_groups'||pr?.type==='spbp5_subunit_blade_pick'||pr?.type==='spbp5_pick_wr_live'||pr?.type==='spbp5_wait_discard_surveil'&&pr.step==='pick')&&pr.responder===myId){
    ovl.classList.remove('open');
    const mandatoryBothWr = pr.type === 'both_wr_member_to_empty_stage';
    openHandPick({
      hand: pr.candidates||[],
      count: 1,
      min: 1,
      title: pr.source_name||'Choose card',
      msg: promptDisplayText(pr, 'Choose a card.', s),
      allowCancel: !mandatoryBothWr,
      onConfirm: (picked)=> sendAct('resolve_prompt',{card_id:picked[0]}),
      onCancel: ()=> {
        if (mandatoryBothWr) return;
        sendAct('resolve_prompt',{choice:'no'});
      }
    });
    return;
  }
  if(pr?.type==='sbp5_draw_deck_bottom'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const need=pr.bottom_count||1;
    openHandPick({
      hand: me?.hand||[],
      count: need,
      min: need,
      title: pr.source_name||'Deck bottom',
      msg: promptDisplayText(pr, `Choose ${need} card(s) to put on the bottom of your deck.`, s),
      confirmLabel: need===1?'Tap a card to put it on the bottom of your deck.':undefined,
      allowCancel: false,
      onConfirm: (picked)=> sendAct('resolve_prompt',{discard_ids:picked}),
    });
    return;
  }
  if(pr?.type==='sbp6_discard_after_draw'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    const need=pr.discard_count||1;
    openHandPick({
      hand: me?.hand||[],
      count: need,
      min: need,
      title: pr.source_name||'Discard',
      msg: promptDisplayText(pr, `Discard ${need} card(s).`, s),
      allowCancel: false,
      onConfirm: (picked)=> sendAct('resolve_prompt',{discard_ids:picked}),
    });
    return;
  }
  if((pr?.type==='bp5_wait_discard_look_reveal'||pr?.type==='bp5_discard_pay_wr_live_score'||pr?.type==='sbp5_discard_bladeless_wr_live'||pr?.type==='sbp5_live_start_discard_heart'||pr?.type==='spbp5_wait_draw_discard'||pr?.type==='spbp5_wait_discard_surveil'||pr?.type==='spbp5_wait_or_discard_activate')&&pr.step==='discard'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    openHandPick({
      hand: me?.hand||[],
      count: pr.discard_count||1,
      min: pr.discard_count||1,
      title: pr.source_name||'Discard',
      msg: promptDisplayText(pr, 'Discard from hand.', s),
      onConfirm: (picked)=> sendAct('resolve_prompt',{discard_ids:picked}),
    });
    return;
  }
  if(pr?.type==='bp5_wait_discard_look_reveal'&&pr.step==='pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    openHandPick({
      hand: pr.candidates||[],
      count: 1,
      min: 0,
      title: pr.source_name||'Reveal Member',
      msg: promptDisplayText(pr, 'Choose a matching Member to add to your hand, or skip.', s),
      onConfirm: (picked)=> sendAct('resolve_prompt', picked.length ? { card_id: picked[0] } : { choice: 'skip' }),
      onCancel: ()=> sendAct('resolve_prompt',{choice:'skip'}),
    });
    return;
  }
  if(pr?.type==='spbp5_mill_swap_pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Position change', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.chooseArea', s);
    const box=el('prompt-btns'); box.innerHTML='';
    (pr.choices||[]).forEach((slot,i)=>{
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=(pr.choice_labels&&pr.choice_labels[i])||slot;
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:slot}); };
      box.appendChild(b);
    });
    ovl.classList.add('open');
    return;
  }
  if(pr?.type==='spbp5_pay_energy_score'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Pay Energy', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.howMuchEnergy', s);
    const box=el('prompt-btns'); box.innerHTML='';
    const me=s.players?.[myId];
    const max=(me?.energy_zone||[]).filter(energyChipActive).length;
    for(let i=0;i<=max;i++){
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=String(i);
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:'pay',energy_count:i}); };
      box.appendChild(b);
    }
    const skip=document.createElement('button');
    skip.className='btn-grad';
    skip.textContent=pt('prompt.skip');
    skip.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:'skip'}); };
    box.appendChild(skip);
    ovl.classList.add('open');
    return;
  }
  if(!pr||pr.responder!==myId){
    ovl.classList.remove('open');
    hideTextAnswerPrompt();
    hidePromptEffectText();
    hidePromptLookCards();
    closeM('overlay-hand-pick');
    closeM('overlay-pick');
    closeM('overlay-heart');
    return;
  }
  if(pr.type==='opponent_text_answer'){
    el('prompt-ttl').textContent=promptDisplayTitle(pr, 'Live Start', s);
    el('prompt-msg').textContent=promptDisplayText(pr, 'What do you like?', s);
    renderTextAnswerPrompt(pr);
    ovl.classList.add('open');
    return;
  }
  hideTextAnswerPrompt();
  // Live Start optional_discard_prompt: show Yes / No — Skip first (#79 Proof Kosuzu).
  // Hand pick opens only after Yes (handlePromptChoice → discardNeed path).
  if(isSelfActivationPrompt(pr)){
    const box=el('prompt-btns');
    renderSelfActivationPrompt(pr,s,myId,box,false);
    ovl.classList.add('open');
    return;
  }
  hidePromptEffectText();
  renderPromptLookCards(pr);
  const branch=isBranchChoicePrompt(pr);
  const subEl=el('prompt-sub');
  el('prompt-ttl').textContent=promptSourceDisplayName(pr, s);
  el('prompt-msg').textContent=promptDisplayText(pr, 'prompt.tapOption', s);
  el('prompt-msg').className='prompt-branch-msg';
  if(branch){
    subEl.hidden=false;
    subEl.textContent=t('prompt.tapOption');
  } else {
    subEl.hidden=true;
    subEl.textContent='';
  }
  const box=el('prompt-btns');
  box.className=branch?'prompt-choice-list':'';
  box.innerHTML='';
  if(branch){
    renderBranchChoiceButtons(pr,s,myId,box);
  } else {
    const heartPick = isHeartColorChoicePrompt(pr);
    (pr.choices||[]).forEach((key,i)=>{
      const b=document.createElement('button');
      b.className='btn-grad';
      if (!(heartPick && fillHeartColorChoiceContent(b, key, pr))) {
        b.textContent=promptChoiceLabel(key, i, pr);
      }
      b.onclick=()=> handlePromptChoice(pr,key,s,myId);
      box.appendChild(b);
    });
  }
  ovl.classList.add('open');
  if (replayReadOnly) syncReplayPromptReadOnlyUi(true);
  bumpAntiSoftlockButton();
}

  /** In-game skill / resolve overlays that dim the board. */
  const BOARD_PEEK_PROMPT_IDS = [
    'overlay-prompt',
    'overlay-pick',
    'overlay-hand-pick',
    'overlay-heart',
    'overlay-surveil',
  ];

  function openBoardPeekPromptOverlay() {
    const get = global.el || ((id) => document.getElementById(id));
    for (const id of BOARD_PEEK_PROMPT_IDS) {
      const ov = get(id);
      if (ov?.classList.contains('open')) return ov;
    }
    return null;
  }

  function peekBlockedByOtherUi() {
    if (document.body.classList.contains('perf-spectacle-active')) return true;
    const get = global.el || ((id) => document.getElementById(id));
    if (get('modal-card')?.classList.contains('open')) return true;
    if (get('overlay-win')?.classList.contains('open')) return true;
    if (get('overlay-coin')?.classList.contains('open')) return true;
    if (get('overlay-mull')?.classList.contains('open')) return true;
    return false;
  }

  function isPcSkillBoardPeek() {
    const root = document.documentElement;
    if (root.classList.contains('tcg-portrait-play')) return false;
    if (root.classList.contains('tcg-mobile-viewport')) return false;
    try {
      if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return false;
    } catch (_) {
      if ('ontouchstart' in window && !window.matchMedia('(pointer: fine)').matches) return false;
    }
    return true;
  }

  function ensureBoardPeekRail() {
    let rail = document.getElementById('prompt-board-peek-rail');
    if (rail) return rail;
    rail = document.createElement('div');
    rail.id = 'prompt-board-peek-rail';
    rail.className = 'prompt-board-peek-rail';
    rail.setAttribute('aria-hidden', 'true');
    const lab = document.createElement('span');
    lab.className = 'prompt-board-peek-rail-label';
    lab.textContent = 'Board';
    rail.appendChild(lab);
    document.body.appendChild(rail);
    return rail;
  }

  /**
   * PC: hover the far-left rail during a skill prompt to hide the prompt + dim
   * and see the playmat. Mouse away restores the prompt.
   */
  global.initPromptBoardPeek = function initPromptBoardPeek() {
    if (global._promptBoardPeekBound) return;
    global._promptBoardPeekBound = true;

    const rail = ensureBoardPeekRail();
    let peekActive = false;

    function endPeek() {
      peekActive = false;
      document.body.classList.remove('prompt-board-peek', 'prompt-board-peek-holding');
    }

    function startPeek() {
      peekActive = true;
      document.body.classList.add('prompt-board-peek', 'prompt-board-peek-holding');
    }

    function syncRail() {
      const show = !!(openBoardPeekPromptOverlay() && isPcSkillBoardPeek() && !peekBlockedByOtherUi());
      document.body.classList.toggle('prompt-board-peek-rail-on', show);
      rail.setAttribute('aria-hidden', show ? 'false' : 'true');
      if (!show && peekActive) endPeek();
    }

    rail.addEventListener('pointerenter', () => {
      if (!document.body.classList.contains('prompt-board-peek-rail-on')) return;
      startPeek();
    });
    rail.addEventListener('pointerleave', () => {
      endPeek();
    });
    rail.addEventListener('mouseenter', () => {
      if (!document.body.classList.contains('prompt-board-peek-rail-on')) return;
      startPeek();
    });
    rail.addEventListener('mouseleave', () => {
      endPeek();
    });

    window.addEventListener('blur', () => {
      endPeek();
      syncRail();
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') endPeek();
    });
    try {
      window.matchMedia('(hover: hover) and (pointer: fine)').addEventListener('change', syncRail);
    } catch (_) { /* ignore */ }

    const mo = new MutationObserver(syncRail);
    BOARD_PEEK_PROMPT_IDS.forEach((id) => {
      const ov = document.getElementById(id);
      if (ov) mo.observe(ov, { attributes: true, attributeFilter: ['class'] });
    });
    const modal = document.getElementById('modal-card');
    if (modal) mo.observe(modal, { attributes: true, attributeFilter: ['class'] });
    syncRail();
  };

})(window);
