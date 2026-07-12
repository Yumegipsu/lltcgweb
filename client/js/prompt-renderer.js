/**
 * Prompt UI: submit guards, pick openers, renderPrompt, choice handler.
 */
(function (global) {
  'use strict';

  function pt(key, vars) {
    var i18n = global.LLTCG_I18N;
    return i18n && typeof i18n.t === 'function' ? i18n.t(key, vars) : key;
  }

  global.promptIdentityKey = function promptIdentityKey(s) {
    const pr = s?.pending_prompt;
    if (!pr || !s) return null;
    const src = pr.source_id || pr.card_instance_id || pr.source_instance_id || '';
    const abIdx = pr.ability_index ?? '';
    return `${s.seq}:${pr.type}:${pr.step ?? ''}:${pr.responder ?? ''}:${src}:${abIdx}`;
  };

  global.promptSubmitKey = function promptSubmitKey(s) {
    return global.promptIdentityKey(s);
  };

  global.markPromptSubmitting = function markPromptSubmitting(s) {
    const key = global.promptSubmitKey(s || global.G.gameState);
    global.G._promptSubmitKey = key;
    // Remember answered identity so ensurePendingPromptSurfaced cannot reopen it
    // after the overlay closes (stale gate-entry snapshots).
    if (key) global.G._lastResolvedPromptKey = key;
  };

  global.syncPromptSubmitState = function syncPromptSubmitState(s) {
    const pr = s?.pending_prompt;
    if (!pr) {
      if (global.G._promptSubmitKey) {
        global.G._lastResolvedPromptKey = global.G._promptSubmitKey;
      }
      global.G._promptSubmitKey = null;
      global.G._lastSurfacedPromptKey = null;
      if (global.G._deferredPromptState?.pending_prompt) global.clearDeferredPromptState();
      return;
    }
    const idKey = global.promptIdentityKey(s);
    if (!global.G._promptSubmitKey) return;
    if (idKey !== global.G._promptSubmitKey) {
      global.G._promptSubmitKey = null;
      global.G._lastSurfacedPromptKey = null;
    }
  };

  global.isPromptSubmitting = function isPromptSubmitting(s) {
    if (!global.G._promptSubmitKey) return false;
    const key = global.promptSubmitKey(s);
    return !!key && key === global.G._promptSubmitKey;
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
    el('pick-ttl').textContent = pr.source_name || 'Choose destination';
    el('pick-msg').textContent = pr.prompt || 'Add to hand or play to an empty Stage area?';
    const g = el('pick-grid');
    g.innerHTML = '';
    const handBtn = document.createElement('button');
    handBtn.className = 'btn-grad';
    handBtn.style.width = '100%';
    handBtn.textContent = 'Add to hand';
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
      b.textContent = 'Play to ' + slotLabel(slot);
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
  const singleTap=need===1;
  el('pick-ttl').textContent=pr.source_name||'Choose from deck';
  el('pick-msg').textContent=pr.prompt||'Choose card(s) to add to your hand.';
  const g=el('pick-grid'); g.innerHTML='';
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
      skipBtn.className='btn-ghost';
      skipBtn.style.width='100%'; skipBtn.style.marginTop='10px';
      skipBtn.textContent='Skip — put all in Waiting Room';
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
          if(G.pickMarked.size>=need){ toast(`Select at most ${need}`); return; }
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
      skipBtn.className='btn-ghost';
      skipBtn.style.width='100%'; skipBtn.style.marginTop='10px';
      skipBtn.textContent='Skip — put all in Waiting Room';
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
  el('pick-ttl').textContent=pr.source_name||'Choose Member';
  el('pick-msg').textContent=pr.prompt||'Choose 1 Member on your Stage.';
  const g=el('pick-grid'); g.innerHTML='';
  cards.forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      closeM('overlay-pick');
      sendAct('resolve_prompt',{card_id:card.instance_id});
    }));
  });
  el('pick-count').textContent='';
  openM('overlay-pick');
}


global.openStageSlotPick = function openStageSlotPick(pr){
  const step=pr.step||'pick_slot';
  const cards=(pr.candidates||[]).filter(c=>{
    if(step==='pick_named') return !!c.named;
    if(step==='pick_other') return c&&!c.named;
    return !!(c.slot);
  });
  el('pick-ttl').textContent=pr.source_name||'Choose Member';
  el('pick-msg').textContent=pr.prompt||'Choose a Member on your Stage.';
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
  const cfg = wrPickCfgFromPrompt(pr);
  let cards = wrToHandPickCards(pr, s, myId).filter(c => cardMatchesWrPickClient(c, cfg));
  if (!cards.length && (pr.candidates || []).length) {
    cards = (pr.candidates || []).map(c => enrichCard(c)).filter(c => c?.instance_id && cardMatchesWrPickClient(c, cfg));
  }
  if (!cards.length) {
    toast(pt('prompt.wrNoMatch') || pt('prompt.wrEmpty'), 3200);
    return;
  }
  if (!cards.some(c => cardMatchesWrPickClient(c, cfg)) && (pr.candidates || []).length) {
    cards = wrToHandPickCards({ ...pr, candidates: pr.candidates }, s, myId);
  }
  el('pick-ttl').textContent = pr.source_name || pt('prompt.wrPickTitle');
  el('pick-msg').textContent = pr.prompt || pt('prompt.wrPickMsg');
  const g = el('pick-grid');
  g.innerHTML = '';
  const onCancel = opts.onCancel;
  G.pickCtx = onCancel ? { onCancel } : null;
  const btnOk = el('btn-pick-ok');
  const btnCancel = el('btn-pick-cancel');
  if (btnOk) btnOk.style.display = 'none';
  if (btnCancel) btnCancel.style.display = onCancel ? '' : 'none';
  const serverIds = new Set((pr.candidates || []).map(c => c.instance_id).filter(Boolean));
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
  el('pick-ttl').textContent = pr.source_name || pt('prompt.yellPickTitle');
  el('pick-msg').textContent = pr.prompt || pt('prompt.yellPickMsg');
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
  const cards = judgeSuccessLivePickCards(pr, s, myId);
  if (!cards.length) {
    toast(pt('prompt.noLiveSuccess'), 3200);
    return;
  }
  el('pick-ttl').textContent = pr.source_name || pt('prompt.successLivePickTitle');
  el('pick-msg').textContent = pr.prompt || pt('prompt.successLivePickMsg');
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
    toast('No cards in Success Live area', 3200);
    return;
  }
  el('pick-ttl').textContent = pr.source_name || pt('prompt.successLiveHandTitle');
  el('pick-msg').textContent = pr.prompt || pt('prompt.successLiveHandMsg');
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
  G.pickCtx={count:need, min:need, onConfirm:(ids)=>sendAct('resolve_prompt',{card_ids:ids})};
  G.pickMarked.clear();
  el('pick-ttl').textContent=pr.source_name||'Choose Members';
  el('pick-msg').textContent=pr.prompt||`Choose ${need} Member card(s) from your Waiting Room (order = deck top).`;
  const g=el('pick-grid'); g.innerHTML='';
  cards.forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      if(G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
      else {
        if(G.pickMarked.size>=need){ toast(`Select at most ${need}`); return; }
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
  el('pick-ttl').textContent=pr.source_name||'Stack Member';
  el('pick-msg').textContent=pr.prompt||'Choose a Member from your Waiting Room to stack under this Member.';
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
  skipBtn.textContent='Skip';
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
  el('pick-ttl').textContent=pr.source_name||'Wait Members';
  el('pick-msg').textContent=pr.prompt||`Choose up to ${max} Member(s) to put into Wait.`;
  const g=el('pick-grid'); g.innerHTML='';
  (pr.stage_members||[]).forEach(card=>{
    g.appendChild(mkPickCardEl(card,'pickcard',()=>{
      if(G.pickMarked.has(card.instance_id)) G.pickMarked.delete(card.instance_id);
      else {
        if(G.pickMarked.size>=max){ toast(`Select at most ${max}`); return; }
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
      if(G.pickMarked.size<min){ toast(`Select at least ${min}`); return; }
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
  el('pick-ttl').textContent=pr.source_name||'Opponent hand';
  el('pick-msg').textContent=pr.prompt||'Choose 1 card without looking.';
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
  el('pick-ttl').textContent=pr.source_name||'Choose Member';
  el('pick-msg').textContent=pr.prompt||'Choose 1 active Member on your Stage to put into Wait.';
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
  el('pick-ttl').textContent=pr.source_name||'Return Energy';
  el('pick-msg').textContent=pr.prompt||'Choose a Member with stacked Energy to return.';
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

global.openHandPick = function openHandPick({hand,count,title,msg,onConfirm,onCancel,min,allowCancel=true,confirmLabel,forceConfirm=false}){
  if (global.G?.isSpectator || (typeof global.isReplayViewing === 'function' && global.isReplayViewing())) return;
  const need=count;
  const minPick=min??count;
  const singleTap=!forceConfirm&&need===1&&minPick===1;
  G.handPickCtx={count:need,min:minPick,singleTap,onConfirm,onCancel};
  G.pickMarked.clear();
  el('hpick-ttl').textContent=title||t('prompt.chooseFromHand');
  el('hpick-msg').textContent=localizeSubunitText(msg||(singleTap
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
        if(G.pickMarked.size>=ctx.count){ toast(`Select at most ${ctx.count}`); return; }
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

global.localizePromptDisplayText = function localizePromptDisplayText(text, pr, s) {
  if (!text) return text;
  if (getLocale() !== 'ja') return localizeSubunitText(text);
  const card = findPromptSourceCard(pr, s);
  const idx = card ? promptAbilityIndex(card, pr) : -1;
  if (card && idx >= 0) {
    const fromAbility = abilityRulesTextFor(card, idx);
    const raw = String(text).trim();
    const enLine = (card.text || '').split(/\n/).map(l => l.trim()).find(l => l && raw.includes(l.slice(0, Math.min(24, l.length))));
    if (fromAbility && (!raw || raw === (pr?.effect_text || '').trim() || enLine)) return fromAbility;
  }
  if (card && text === (card.text || '').trim()) return cardRulesDisplayText(card);
  if (window.LLTCG_LOG_I18N?.localizePromptText) {
    return LLTCG_LOG_I18N.localizePromptText(text, G.allCards);
  }
  if (window.LLTCG_LOG_I18N?.localizeLogMessage) {
    return LLTCG_LOG_I18N.localizeLogMessage(text, G.allCards);
  }
  return text;
}

global.localizePromptEffectText = function localizePromptEffectText(pr, s) {
  const card = findPromptSourceCard(pr, s);
  const idx = card ? promptAbilityIndex(card, pr) : -1;
  if (card && idx >= 0) {
    const fromAbility = abilityRulesTextFor(card, idx);
    if (fromAbility) return fromAbility;
  }
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
  if (raw && getLocale() === 'ja') return localizePromptDisplayText(raw, pr, G.gameState);
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
  if (c === 'gray' || c === 'any') return c === 'any' ? 'Any' : 'Gray';
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
    caption.textContent = `2× ${heartColorChoiceDisplayName(color)} + Gray`;
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
    suffix.textContent = ' — waived';
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
    ? (pr ? localizePromptDisplayText(text, pr, s) : (getLocale() === 'ja' && window.LLTCG_LOG_I18N?.localizePromptText
      ? LLTCG_LOG_I18N.localizePromptText(text, G.allCards) : text))
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
    'sbp5_aqours_blade_or_position','sbp6_live_wr_deck_position','sbp6_hand_deck_position',
    'ssd1_reveal_group_deck','opp_pick_wr_live_offer','spbp5_wr_pay_add_hand',
    'spbp2_discard_liella_choice'
  ]);
  if(branchTypes.has(pr.type)){
    if(pr.type==='live_start_center_cost_choice'&&pr.step&&pr.step!=='pick_mode') return false;
    if(pr.type==='player_choice_wr_live_deck_bottom_draw'&&pr.step==='pick_wr_live') return false;
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
      } else if (wrEl) {
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
  el('surveil-ttl').textContent=pr.source_name||'Look at deck';
  const n = (pr.looked_cards || []).length;
  el('surveil-msg').textContent=localizeSubunitText(pr.prompt||(
    n === 1
      ? 'Look at the top card of your deck. You may put it on top of your deck or put it into the Waiting Room.'
      : `Look at the top ${n} cards of your deck. You may put any number of them on top of your deck in any order and put the rest into the Waiting Room.`
  ));
  const cards = pr.looked_cards || [];
  G.surveil = {
    slots: cards.map(c => c.instance_id),
    wr: [],
    byId: {},
    selId: null,
    drag: null,
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
  const assigned = new Set([...surveilTopIds(), ...G.surveil.wr]);
  if (all.some(id => !assigned.has(id))) { toast('Assign every card to a deck spot or Waiting Room'); return; }
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
  const label = c.name_en || c.name || '';
  for (const n of (names || [])) {
    if (label === n || label.includes(n)) return true;
    if (label.includes('&') || label.includes('＆')) {
      for (const part of label.split(/[&＆]/)) {
        if (part.trim() === n) return true;
      }
    }
  }
  return !!(includeSelf && sourceId && c.instance_id === sourceId);
}


function optionalLiveStartDiscardHand(pr, s, myId) {
  const me = s.players?.[myId];
  const ab = pr.ability || {};
  let pickHand = me?.hand || [];
  const grp = ab.then?.group || ab.group || '';
  if (ab.type === 'optional_discard_named') {
    const names = ab.names || [];
    pickHand = pickHand.filter(c => cardMatchesNamedHand(c, names, ab.include_self, pr.source_id));
  } else if (grp) {
    pickHand = pickHand.filter(c => c.card_type === 'メンバー' && (c.group || '') === grp);
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
    msg: pr.prompt || (minPick === 0
      ? `Choose up to ${discardNeed} cards to send to the Waiting Room.`
      : discardNeed === 1
      ? 'Choose a card to send to the Waiting Room.'
      : `Choose ${discardNeed} cards to send to the Waiting Room.`),
    onConfirm: (ids) => sendResolvePrompt('yes', { discard_ids: ids }),
    onCancel: () => {
      G._promptSubmitKey = null;
      if (G.gameState) renderPrompt(G.gameState, myId);
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
  if(pr.type==='live_start_pay_or_discard'&&choice==='discard') return pr.discard_count||2;
  if(pr.type==='optional_discard_prompt'){
    if(pr.ability?.max_discard) return pr.ability.max_discard;
    return pr.ability?.discard||1;
  }
  if(pr.type==='discard_member_add_lower_wr_member') return 1;
  if(pr.type==='optional_discard_mill_wr_add_member') return 1;
  if(pr.type==='optional_discard_grant_heart_other_member') return 1;
  if(pr.type==='optional_discard_activate_wait_blade'||pr.type==='optional_discard_activate_wait_hearts') return 2;
  if(pr.type==='wait_self_discard_add_wr_live') return pr.ability?.discard||1;
  if(pr.type==='optional_discard_look_reveal_subunit') return pr.ability?.discard||1;
  if(pr.type==='optional_discard_mill_add_wr_subunit_live') return pr.ability?.discard||1;
  if(pr.type==='optional_discard_add_cb_member_hs_live') return pr.discard||2;
  if(pr.type==='optional_wait_self_look_reveal') return pr.discard_count||pr.ability?.discard||0;
  if(pr.type==='mandatory_discard_after_draw') return pr.discard_count||1;
  if(pr.type==='opp_may_discard_or_modifier') return 1;
  if(pr.type==='reveal_live_opp_discard_or_blade') return 1;
  return 0;
}


function hidePromptEffectText(){
  const box=el('prompt-effect');
  if(!box) return;
  box.hidden=true;
  box.innerHTML='';
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
      msg:pr.prompt||`Discard ${need} card(s).`,
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
  if((pr.type==='optional_wr_live_deck_bottom'||pr.type==='live_success_yell_live_deck_bottom'
    ||pr.type==='live_success_pick_yell_deck_top')&&choice==='pick'){
    closeM('overlay-prompt');
    if (pr.type === 'live_success_pick_yell_deck_top') {
      openHandPick({
        hand: pr.candidates || [],
        count: 1,
        min: 1,
        title: pr.source_name || pt('prompt.deckTopTitle'),
        msg: pr.prompt || pt('prompt.deckTopMsg'),
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
      ||(pr.type==='optional_discard_prompt'&&(pr.live_start||s.phase==='live_start_effects'))){
      pickHand=optionalLiveStartDiscardHand(pr,s,myId);
    }
    if(pr.type==='opp_may_discard_or_modifier'){
      pickHand=pickHand.filter(c=>c.card_type==='ライブ');
      if(!pickHand.length){ toast('No Live card in hand'); return; }
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
      msg:pr.prompt||(minPick===0
        ? `Choose up to ${discardNeed} cards to send to the Waiting Room.`
        : discardNeed===1
        ? 'Choose a card to send to the Waiting Room.'
        : `Choose ${discardNeed} cards to send to the Waiting Room.`),
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
    renderGame(s, { skipLog: true });
  }
  ovl.classList.remove('open');
  const me = s.players?.[myId];
  const need = pr.count || 1;
  const forceConfirm = s.phase === 'live_success_effects' || need > 1;
  openHandPick({
    hand: me?.hand || [],
    count: need,
    min: need,
    title: pr.source_name || t('prompt.discardFromHand'),
    msg: pr.prompt || ((need) === 1
      ? t('prompt.discardOne')
      : t('prompt.discardMany', { count: need })),
    allowCancel: false,
    forceConfirm,
    confirmLabel: forceConfirm && need > 1 ? t('prompt.selectThenConfirm') : undefined,
    onConfirm: (ids) => {
      const payload = (pr.pick_mode === 'deck_top')
        ? { card_ids: ids }
        : { discard_ids: ids };
      sendAct('resolve_prompt', payload);
    },
  });
};

global.renderPrompt = function renderPrompt(s, myId){
  let pr=s.pending_prompt;
  const viewerId = myId;
  const replayViewing = typeof global.isReplayViewing === 'function' && global.isReplayViewing();
  const ovl=el('overlay-prompt');
  if (global.G?.isSpectator) {
    ovl?.classList.remove('open');
    hideTextAnswerPrompt();
    hidePromptEffectText();
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
  if(pr) pr=ensurePromptChoices(pr);
  if (pr) TCG_DEBUG.log('prompt', pr.type, { responder: pr.responder, me: viewerId, seq: s.seq, step: pr.step });
  if (!replayReadOnly && pr?.responder === viewerId && shouldDeferPromptForLivePresentation(s, viewerId)) {
    ovl?.classList.remove('open');
    if (pr.type === 'effect_discard_hand') closeM('overlay-hand-pick');
    return;
  }
  if(pr?.type==='surveil_arrange'&&pr.responder===myId){
    renderPromptSurveilBranch(s, myId, pr);
    return;
  }
  closeM('overlay-surveil');
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
      prompt: pr.prompt || `Choose 1 ${pr.group || 'Nijigasaki'} Member to put into Wait.`,
    });
    return;
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
      prompt: pr.prompt || 'Look at the top 2 cards. You may add 1 μ\'s card to your hand, or send both to the Waiting Room.',
    });
    return;
  }
  if(pr?.type==='optional_leave_mus_score_add_wr_live'&&pr.step==='pick_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    openStageMemberPickById(pr);
    return;
  }
  if(pr?.type==='pick_named_member_blade'||pr?.type==='pick_member_cost_bonus'){
    ovl.classList.remove('open');
    openHandPick({
      hand: pr.candidates||[],
      count: 1,
      min: 1,
      title: pr.source_name||'Choose Member',
      msg: pr.prompt||'Choose 1 Member on your Stage.',
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
      prompt: pr.prompt||'Choose 1 Aqours Member from your Waiting Room.',
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
      title: pr.source_name||'Choose Members',
      msg: pr.prompt||`Choose up to ${max} Member(s).`,
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
      title: pr.source_name||'Choose Members',
      msg: pr.prompt||`Choose up to ${max} Yell Member(s).`,
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
      prompt: pr.prompt || pt('prompt.successLivePickMsg'),
      source_name: pr.source_name || pt('prompt.successLivePickTitle'),
    }, { state: s, myId });
    return;
  }
  if((pr?.type==='pick_wr_to_hand'||pr?.type==='pick_wr_leave_stage_add')&&pr.responder===myId){
    ovl.classList.remove('open');
    const filter = wrPickCfgFromPrompt(pr).filter;
    const pickOpts = { state: s, myId };
    if(filter==='live') openWrLivePick(pr, pickOpts);
    else openActivateWrMemberPick(pr, pickOpts);
    return;
  }
  if(pr?.type==='hsbp6_pick_wr_live_and_member'&&pr.responder===myId){
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
      msg: pr.prompt||`Choose up to ${max} matching Member card(s) from your Waiting Room.`,
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
  if(pr?.type==='pick_live_match_success_heart'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrLivePick(pr);
    return;
  }
  if(pr?.type==='ssd1_play_wr_empty'&&pr.step==='pick_slot'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=pr.source_name||'Play Member';
    el('prompt-msg').textContent=pr.prompt||'Choose an area:';
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
      el('prompt-ttl').textContent=pr.source_name||'Play Member';
      el('prompt-msg').textContent=pr.prompt||'Choose an area:';
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
    const grp=pr.ability?.group||'Nijigasaki';
    const maxCost=pr.ability?.max_cost??4;
    openHandPick({
      hand: (me?.hand||[]).filter(c=>c.card_type==='メンバー'&&(c.group||'')===grp&&(c.cost||0)<=maxCost),
      count: 1,
      title: pr.source_name||'Play Member',
      msg: pr.prompt||`Choose a ${grp} Member (cost ≤${maxCost}) from hand.`,
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
    const idSet = new Set((pr.candidates || []).map(c => c.instance_id).filter(Boolean));
    const yellPool = (s.yell_reveal?.[owner] || s.players?.[owner]?.yell_cards || [])
      .filter(c => idSet.has(c.instance_id));
    if (!yellPool.length) {
      sendAct('resolve_prompt', { choice: 'no' });
      return;
    }
    const max = pr.max_pick || yellPool.length;
    el('prompt-ttl').textContent = pr.source_name || 'Yell mill';
    el('prompt-msg').textContent = pr.prompt
      || `Put up to ${max} non-Blade-heart Hasunosora Yell card(s) into the Waiting Room for extra Yell?`;
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
          title: pr.source_name || 'Yell mill',
          msg: pr.prompt || `Choose up to ${max} Yell card(s) to mill.`,
          onConfirm: (ids) => {
            if (!ids.length) sendAct('resolve_prompt', { choice: 'no' });
            else sendAct('resolve_prompt', { choice: 'yes', card_ids: ids });
          },
          onCancel: () => sendAct('resolve_prompt', { choice: 'no' }),
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
      msg: pr.prompt||`Choose ${need} Live cards with different names from your Waiting Room.`,
      onConfirm: (ids)=> sendAct('resolve_prompt',{card_ids:ids}),
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
        msg: pr.prompt||`Put 1 ${grp} card from your hand into the Waiting Room.`,
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
        title: pr.source_name||'Choose Member',
        msg: pr.prompt||'Choose 1 Member on your Stage.',
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
      msg: pr.prompt||'Choose 1 Live card from your hand to reveal.',
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
        msg: pr.prompt || pt('prompt.deckTopMsg'),
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
      msg: pr.prompt || `Choose up to ${maxCount} Member(s) (combined cost ≤${maxCombined}).`,
      allowCancel: true,
      forceConfirm: maxCount > 1,
      onConfirm: (ids) => {
        const picked = (ids || []).map(id => (pr.candidates || []).find(c => c.instance_id === id)).filter(Boolean);
        const total = picked.reduce((sum, c) => sum + Number(c.cost || 0), 0);
        if (total > maxCombined) {
          toast(`Combined cost must be ≤${maxCombined}`, 2800, true);
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
      msg: pr.prompt||'Choose a card to send to the Waiting Room.',
      onConfirm: (ids)=> sendAct('resolve_prompt',{discard_ids:ids}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
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
      msg: pr.prompt||`Discard ${need} card(s) from your hand to look at your deck.`,
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
      msg: pr.prompt||'Select Member cards to reveal from hand.',
      onConfirm: (ids)=> sendAct('resolve_prompt',{card_ids:ids}),
      onCancel: ()=> sendAct('resolve_prompt',{card_ids:[]})
    });
    return;
  }
  if(pr?.type==='on_enter_draw_swap_area'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=pr.source_name||'Move Member';
    el('prompt-msg').textContent=pr.prompt||'Choose an area:';
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
    el('prompt-ttl').textContent=pr.source_name||'Activate Energy';
    el('prompt-msg').textContent=pr.prompt||'How many Energy to activate?';
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
  if(pr?.type==='pick_named_members_grant_hearts'&&pr.responder===myId){
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
      msg: pr.prompt||'Choose 1 Live card from your hand.',
      onConfirm: (ids)=> sendAct('resolve_prompt',{card_id:ids[0]}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr?.type==='optional_wr_member_deck_top_blade'&&pr.step==='pick_wr_member'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrLivePick(pr);
    return;
  }
  if((pr?.type==='optional_wr_member_deck_top_blade'||pr?.type==='live_start_center_cost_choice'||pr?.type==='wait_opponent_stage_pick')
    &&(pr.step==='pick_stage_blade'||pr.step==='pick_opp_wait')&&pr.responder===myId){
    ovl.classList.remove('open');
    openStageSlotPick(pr);
    return;
  }
  if(pr?.type==='player_choice_wr_live_deck_bottom_draw'&&pr.step==='pick_wr_live'&&pr.responder===myId){
    ovl.classList.remove('open');
    openWrLivePick(pr);
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
        msg: pr.prompt||'Choose 1 card to send to the Waiting Room.',
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
    if(pr.step==='confirm'){
      ovl.classList.add('open');
      return;
    }
    ovl.classList.remove('open');
    openWrLivePick(pr);
    return;
  }
  if(pr?.type==='optional_success_live_swap'&&pr.responder===myId){
    if(pr.step==='confirm'){
      ovl.classList.add('open');
      return;
    }
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    if(pr.step==='pick_hand_live'){
      openHandPick({
        hand: (me?.hand||[]).filter(c=>c.card_type==='ライブ'),
        count: 1,
        title: pr.source_name||'Maki Nishikino',
        msg: pr.prompt||'Choose 1 Live card from your hand to reveal.',
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
  if(pr?.type==='mandatory_discard_after_draw'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    openHandPick({
      hand: me?.hand||[],
      count: pr.discard_count||1,
      title: pr.source_name||'Discard',
      msg: pr.prompt||'Choose card(s) to send to the Waiting Room.',
      onConfirm: (ids)=> sendAct('resolve_prompt',{discard_ids:ids}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr?.type==='reveal_hand_named_stack_under'&&pr.responder===myId){
    ovl.classList.remove('open');
    const ids=new Set((pr.candidates||[]).map(c=>c.instance_id));
    const me=s.players?.[myId];
    openHandPick({
      hand: (me?.hand||[]).filter(c=>ids.has(c.instance_id)),
      count: 1,
      title: pr.source_name||'Reveal Member',
      msg: pr.prompt||'Choose a matching Member from your hand to stack under this Member.',
      onConfirm: (picked)=> sendAct('resolve_prompt',{card_id:picked[0]}),
      onCancel: ()=> { if(G.gameState) renderPrompt(G.gameState, myId); }
    });
    return;
  }
  if(pr?.type==='activate_wr_member_pick'&&pr.responder===myId){
    if(pr.step==='pick_member'){
      ovl.classList.remove('open');
      openActivateWrMemberPick(pr);
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
        msg: pr.prompt||(`Choose ${need} card(s) to send to the Waiting Room.`),
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
      el('prompt-ttl').textContent=pr.source_name||'Wait chain';
      el('prompt-msg').textContent=pr.prompt||'Optional Wait effect';
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
      el('prompt-ttl').textContent=pr.source_name||'Position change';
      el('prompt-msg').textContent=pr.prompt||'Position-change this Member?';
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
    el('prompt-ttl').textContent=pr.source_name||'Center moved';
    el('prompt-msg').textContent=pr.prompt||'Choose one effect';
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
      msg: pr.prompt||'Choose any number of subunit Members to discard, then draw that many +1.',
      onConfirm: (picked)=> sendAct('resolve_prompt',{discard_ids:picked}),
      onCancel: ()=> sendAct('resolve_prompt',{discard_ids:[]})
    });
    return;
  }
  if(pr?.type==='pick_number_reveal_deck_top'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=pr.source_name||'Pick a number';
    el('prompt-msg').textContent=pr.prompt||'Choose a number, then reveal your deck top.';
    const box=el('prompt-btns'); box.innerHTML='';
    (pr.numbers||[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15]).forEach(num=>{
      const b=document.createElement('button');
      b.className='btn-grad';
      b.textContent=String(num);
      b.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:String(num)}); };
      box.appendChild(b);
    });
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
  if((pr?.type==='bp5_wr_live_deck_position'||pr?.type==='bp5_pick_kasumi_reveal'||pr?.type==='sbp5_pick_revealed_member'||pr?.type==='sbp5_pick_yell_members'||pr?.type==='sbp5_wr_lives_deck_top'||pr?.type==='sbp6_pick_revealed_member'||pr?.type==='sbp6_swap_pick_wr_member'||pr?.type==='sbp6_live_zone_deck_top_hearts'||pr?.type==='sbp6_swap_pick_stage_member'||pr?.type==='ssd1_play_wr_empty'&&pr.step==='pick_wr'||pr?.type==='ssd1_reveal_group_deck'&&pr.step==='pick_hand'||pr?.type==='spbp5_distinct_groups'||pr?.type==='spbp5_subunit_blade_pick'||pr?.type==='spbp5_pick_wr_live'||pr?.type==='spbp5_wait_discard_surveil'&&pr.step==='pick')&&pr.responder===myId){
    ovl.classList.remove('open');
    openHandPick({
      hand: pr.candidates||[],
      count: 1,
      min: 1,
      title: pr.source_name||'Choose card',
      msg: pr.prompt||'Choose a card.',
      onConfirm: (picked)=> sendAct('resolve_prompt',{card_id:picked[0]}),
      onCancel: ()=> sendAct('resolve_prompt',{choice:'no'})
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
      msg: pr.prompt||`Choose ${need} card(s) to put on the bottom of your deck.`,
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
      msg: pr.prompt||`Discard ${need} card(s).`,
      allowCancel: false,
      onConfirm: (picked)=> sendAct('resolve_prompt',{discard_ids:picked}),
    });
    return;
  }
  if((pr?.type==='bp5_wait_discard_look_reveal'||pr?.type==='sbp5_discard_bladeless_wr_live'||pr?.type==='sbp5_live_start_discard_heart'||pr?.type==='spbp5_wait_draw_discard'||pr?.type==='spbp5_wait_discard_surveil'||pr?.type==='spbp5_wait_or_discard_activate')&&pr.step==='discard'&&pr.responder===myId){
    ovl.classList.remove('open');
    const me=s.players?.[myId];
    openHandPick({
      hand: me?.hand||[],
      count: pr.discard_count||1,
      min: pr.discard_count||1,
      title: pr.source_name||'Discard',
      msg: pr.prompt||'Discard from hand.',
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
      msg: pr.prompt||'Choose a matching Member to add to your hand, or skip.',
      onConfirm: (picked)=> sendAct('resolve_prompt', picked.length ? { card_id: picked[0] } : { choice: 'skip' }),
      onCancel: ()=> sendAct('resolve_prompt',{choice:'skip'}),
    });
    return;
  }
  if(pr?.type==='spbp5_mill_swap_pick'&&pr.responder===myId){
    ovl.classList.remove('open');
    el('prompt-ttl').textContent=pr.source_name||'Position change';
    el('prompt-msg').textContent=pr.prompt||'Choose an area:';
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
    el('prompt-ttl').textContent=pr.source_name||'Pay Energy';
    el('prompt-msg').textContent=pr.prompt||'How much Energy to pay?';
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
    skip.textContent='Skip';
    skip.onclick=()=>{ closeM('overlay-prompt'); sendAct('resolve_prompt',{choice:'skip'}); };
    box.appendChild(skip);
    ovl.classList.add('open');
    return;
  }
  if(!pr||pr.responder!==myId){
    ovl.classList.remove('open');
    hideTextAnswerPrompt();
    hidePromptEffectText();
    closeM('overlay-hand-pick');
    return;
  }
  if(pr.type==='opponent_text_answer'){
    el('prompt-ttl').textContent=pr.source_name||'Live Start';
    el('prompt-msg').textContent=localizeSubunitText(pr.prompt||'What do you like?');
    renderTextAnswerPrompt(pr);
    ovl.classList.add('open');
    return;
  }
  hideTextAnswerPrompt();
  if(pr?.type==='optional_discard_prompt'
    &&(pr.live_start||s.phase==='live_start_effects')&&pr.responder===myId){
    ovl.classList.remove('open');
    if(openOptionalLiveStartDiscardPick(pr,s,myId)) return;
  }
  if(isSelfActivationPrompt(pr)){
    const box=el('prompt-btns');
    renderSelfActivationPrompt(pr,s,myId,box,false);
    ovl.classList.add('open');
    return;
  }
  hidePromptEffectText();
  const branch=isBranchChoicePrompt(pr);
  const subEl=el('prompt-sub');
  el('prompt-ttl').textContent=promptSourceDisplayName(pr, s);
  el('prompt-msg').textContent=localizePromptDisplayText(pr.prompt||t('prompt.tapOption'), pr, s);
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


})(window);
