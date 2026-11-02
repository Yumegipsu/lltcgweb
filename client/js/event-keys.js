/**
 * Key Event UI: wallet (Event Keys / Event Tokens), milestone list with reward icons, the prize wheel and the
 * "+keys" toast shown after normal matches. Rendered into the Events screen by admin-events.js.
 */
(function (global) {
  'use strict';

  const IMG = 'assets/events/';
  const COIN_ICON = 'assets/ui/llsifas-gold-coin.webp';
  const TAU = Math.PI * 2;

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    return typeof fn === 'function' ? fn(key, fallback, vars || {}) : (fallback || key);
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function tokenIcon(tier, small) {
    const t = tier === 'rare' || tier === 'uncommon' ? tier : 'common';
    return IMG + 'token_' + t + (small ? '_s' : '') + '.png';
  }

  function keyIcon(small) {
    return IMG + (small ? 'key_s.png' : 'key_l.png');
  }

  /** Tier of the coin icon for a token amount, from the event's own thresholds. */
  function tierFor(cfg, amount) {
    const t = (cfg && cfg.token_tiers) || { uncommon_from: 50, rare_from: 100 };
    if (amount >= Number(t.rare_from || 100)) return 'rare';
    if (amount >= Number(t.uncommon_from || 50)) return 'uncommon';
    return 'common';
  }

  // ------------------------------------------------------------------ prize text and icons

  function prizeLabel(type, amount) {
    const n = String(amount);
    switch (type) {
      case 'event_tokens': return tt('events.prize.tokens', 'Event Tokens ×{n}', { n });
      case 'event_keys': return tt('events.prize.keys', 'Event Keys ×{n}', { n });
      case 'coins': return tt('events.prize.coins', 'Coins ×{n}', { n });
      case 'star_gems': return tt('events.prize.gems', 'Star Gems ×{n}', { n });
      case 'scouting_ticket': return tt('events.prize.ticket', 'Scouting Ticket ×{n}', { n });
      case 'scouting_ticket_sr': return tt('events.prize.ticketSr', 'SR+ Ticket ×{n}', { n });
      case 'scouting_ticket_ur': return tt('events.prize.ticketUr', 'UR+ Ticket ×{n}', { n });
      case 'pr_pack': return tt('events.prize.pack', 'PR Pack');
      case 'wheel2': return tt('events.prize.wheel2', 'Bonus Wheel! {n} free spins', { n });
      case 'card': return tt('events.prize.card', 'Card ×{n}', { n });
      case 'sleeve': return tt('events.prize.sleeve', 'Sleeve');
      case 'playmat': return tt('events.prize.playmat', 'Playmat');
      default: return type + ' ×' + n;
    }
  }

  const COLORS = {
    event_keys: '#f2c14e', coins: '#f4a259', star_gems: '#ff7eb6', scouting_ticket: '#b08cff',
    scouting_ticket_sr: '#8d6bff', scouting_ticket_ur: '#ff5d8f', pr_pack: '#3fc7bf', wheel2: '#ffd86b',
    event_tokens_common: '#58c996', event_tokens_uncommon: '#5aa9f0', event_tokens_rare: '#c58bff',
  };

  function sliceColor(s, i) {
    const key = s.type === 'event_tokens' ? 'event_tokens_' + (s.tier || 'common') : s.type;
    return COLORS[key] || '#8aa2c8';
  }

  /** Small inline HTML icon for a reward (lists, toasts, the result popup). */
  function iconHtml(type, amount, cfg, px) {
    const size = px || 22;
    if (type === 'event_tokens') {
      return `<img class="evk-ico" src="${tokenIcon(tierFor(cfg, Number(amount)), size <= 24)}" width="${size}" height="${size}" alt="">`;
    }
    if (type === 'event_keys') {
      return `<img class="evk-ico" src="${keyIcon(size <= 24)}" width="${size}" height="${size}" alt="">`;
    }
    if (type === 'coins') {
      return `<img class="evk-ico" src="${COIN_ICON}" width="${size}" height="${size}" alt="">`;
    }
    return '';
  }

  // ------------------------------------------------------------------ wheel drawing

  const imgCache = {};
  function loadImg(src) {
    if (!imgCache[src]) {
      const im = new Image();
      im.src = src;
      imgCache[src] = im;
    }
    return imgCache[src];
  }

  /** Visual share of a slice: proportional to its weight, with a floor so rare prizes stay visible slivers. */
  function visualWeights(slices) {
    const vis = slices.map((s) => Math.max(Number(s.weight) || 1, 14));
    const sum = vis.reduce((a, b) => a + b, 0);
    return vis.map((v) => (v / sum) * TAU);
  }

  function drawGlyph(ctx, type, r) {
    ctx.save();
    ctx.fillStyle = 'rgba(255,255,255,.95)';
    ctx.strokeStyle = 'rgba(0,0,0,.35)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    if (type === 'star_gems') { // diamond
      ctx.moveTo(0, -r); ctx.lineTo(r * 0.8, -r * 0.1); ctx.lineTo(0, r); ctx.lineTo(-r * 0.8, -r * 0.1);
    } else if (type.indexOf('scouting_ticket') === 0) { // ticket
      ctx.rect(-r, -r * 0.62, r * 2, r * 1.24);
    } else if (type === 'pr_pack') { // card stack
      ctx.rect(-r * 0.62, -r, r * 1.24, r * 2);
    } else { // bonus wheel star
      for (let k = 0; k < 10; k++) {
        const rad = k % 2 ? r * 0.45 : r;
        const a = (k / 10) * TAU - Math.PI / 2;
        ctx.lineTo(Math.cos(a) * rad, Math.sin(a) * rad);
      }
    }
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.restore();
  }

  /** Draw one wheel (pointer is the page's job: slice 0 starts at 12 o'clock and runs clockwise). */
  function drawWheel(canvas, slices, cfg) {
    const size = canvas.width;
    const ctx = canvas.getContext('2d');
    const cx = size / 2;
    const R = size / 2 - 6;
    ctx.clearRect(0, 0, size, size);
    const widths = visualWeights(slices);
    let a0 = -Math.PI / 2;
    slices.forEach((s, i) => {
      const a1 = a0 + widths[i];
      ctx.beginPath();
      ctx.moveTo(cx, cx);
      ctx.arc(cx, cx, R, a0, a1);
      ctx.closePath();
      ctx.fillStyle = sliceColor(s, i);
      ctx.fill();
      ctx.strokeStyle = 'rgba(255,255,255,.75)';
      ctx.lineWidth = 2;
      ctx.stroke();
      const mid = (a0 + a1) / 2;
      const arcLen = widths[i] * R * 0.8;
      if (arcLen > 26) {
        ctx.save();
        ctx.translate(cx, cx);
        ctx.rotate(mid);
        // icon near the rim, amount towards the centre
        const iconR = R * 0.8;
        const ico = 20;
        ctx.save();
        ctx.translate(iconR, 0);
        ctx.rotate(Math.PI / 2);
        let drawn = false;
        if (s.type === 'event_tokens') {
          const im = loadImg(tokenIcon(s.tier || 'common', true));
          if (im.complete && im.naturalWidth) { ctx.drawImage(im, -ico / 2, -ico / 2, ico, ico); drawn = true; }
        } else if (s.type === 'event_keys') {
          const im = loadImg(keyIcon(true));
          if (im.complete && im.naturalWidth) { ctx.drawImage(im, -ico / 2, -ico / 2, ico, ico); drawn = true; }
        } else if (s.type === 'coins') {
          const im = loadImg(COIN_ICON);
          if (im.complete && im.naturalWidth) { ctx.drawImage(im, -ico / 2, -ico / 2, ico, ico); drawn = true; }
        }
        if (!drawn) drawGlyph(ctx, s.type, ico / 2.2);
        ctx.restore();
        if (s.amount && s.type !== 'pr_pack' && s.type !== 'wheel2' && arcLen > 34) {
          ctx.fillStyle = '#fff';
          ctx.strokeStyle = 'rgba(0,0,0,.45)';
          ctx.lineWidth = 3;
          ctx.font = '700 14px "Libre Baskerville", serif';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.translate(R * 0.52, 0);
          ctx.rotate(Math.PI / 2);
          ctx.strokeText('×' + s.amount, 0, 0);
          ctx.fillText('×' + s.amount, 0, 0);
        }
        ctx.restore();
      }
      a0 = a1;
    });
    // hub
    ctx.beginPath();
    ctx.arc(cx, cx, size * 0.05, 0, TAU);
    ctx.fillStyle = '#fff';
    ctx.fill();
    ctx.strokeStyle = 'rgba(0,0,0,.25)';
    ctx.stroke();
    // icons load lazily: repaint once they arrive
    if (!canvas.__evkRepaint) {
      canvas.__evkRepaint = true;
      setTimeout(() => { canvas.__evkRepaint = false; drawWheel(canvas, slices, cfg); }, 400);
    }
  }

  /** Centre of slice `index`, in degrees clockwise from 12 o'clock. */
  function sliceCenterDeg(slices, index) {
    const widths = visualWeights(slices);
    let a = 0;
    for (let i = 0; i < index; i++) a += widths[i];
    return ((a + widths[index] / 2) / TAU) * 360;
  }

  // ------------------------------------------------------------------ the card on the Events screen

  function timeLeft(ev) {
    const now = ev.clock ? ev.clock.virtual_now : Math.floor(Date.now() / 1000);
    const target = ev.status === 'scheduled' ? ev.starts_at : ev.ends_at;
    let s = Math.max(0, Number(target) - Number(now));
    const d = Math.floor(s / 86400); s -= d * 86400;
    const h = Math.floor(s / 3600); s -= h * 3600;
    const m = Math.floor(s / 60);
    const parts = [];
    if (d) parts.push(tt('events.dur.d', '{n}d', { n: d }));
    if (d || h) parts.push(tt('events.dur.h', '{n}h', { n: h }));
    parts.push(tt('events.dur.m', '{n}m', { n: m }));
    return parts.join(' ');
  }

  function milestoneRow(m, myPoints, cfg) {
    const hit = Number(myPoints) >= Number(m.threshold_points);
    const p = m.reward_payload || {};
    const amount = p.amount || 0;
    return `<li class="${hit ? 'is-hit' : ''}"><span class="evk-ms-at">${esc(m.threshold_points)} EP</span>`
      + `<span class="evk-ms-reward">${iconHtml(m.reward_type, amount, cfg, 20)}${esc(prizeLabel(m.reward_type, amount))}</span></li>`;
  }

  /**
   * @param {object} ev serialized key event (with wallet and config)
   * @param {Array} lbRows leaderboard rows
   * @param {{post:function, reload:function}} api
   * @returns {string} html; call bind(root, ev, api) afterwards
   */
  function cardHtml(ev, lbRows) {
    const cfg = ev.config || {};
    const w = ev.wallet || { keys: 0, tokens: 0, wheel2_left: 0, spins: 0 };
    const tier = tierFor(cfg, Math.max(1, Number(w.tokens) || 0));
    const wheel = cfg.wheel || { spin_cost: 10 };
    const cost = Number(wheel.spin_cost || 10);
    const free = Number(w.wheel2_left || 0);
    const bannerHtml = ev.banner_url ? `<img class="events-banner" src="${esc(ev.banner_url)}" alt="">` : '';
    const ms = (ev.milestones || []).map((m) => milestoneRow(m, ev.my_points, cfg)).join('') || '<li>—</li>';
    const lb = (lbRows || []).map((r) => `<li>#${esc(r.rank)} ${esc(r.username)} — ${esc(r.points)} EP</li>`).join('') || '<li>—</li>';
    const matchCost = cfg.event_match ? Number(cfg.event_match.key_cost || 0) : 0;
    const status = ev.status === 'scheduled'
      ? tt('events.startsIn', 'Starts in {t}', { t: timeLeft(ev) })
      : ev.status === 'ended' ? tt('events.ended', 'Event ended') : tt('events.endsIn', 'Ends in {t}', { t: timeLeft(ev) });
    return `<section class="events-player-card evk-card" data-event="${esc(ev.id)}">
      ${bannerHtml}
      <h3>${esc(ev.name)}${ev.test_mode ? `<span class="admin-test-badge">${esc(tt('events.testBadge', 'TEST · only you'))}</span>` : ''}</h3>
      <p class="admin-events-meta evk-time">${esc(status)}</p>
      <div class="evk-wallet">
        <span class="evk-chip" title="${esc(tt('events.keys', 'Event Keys'))}"><img src="${keyIcon(false)}" width="32" height="32" alt=""><b>${esc(w.keys)}</b><small>${esc(tt('events.keys', 'Event Keys'))}</small></span>
        <span class="evk-chip" title="${esc(tt('events.tokens', 'Event Tokens'))}"><img src="${tokenIcon(tier, false)}" width="32" height="32" alt=""><b>${esc(w.tokens)}</b><small>${esc(tt('events.tokens', 'Event Tokens'))}</small></span>
        <span class="evk-chip evk-chip--ep"><b>${esc(ev.my_points || 0)}</b><small>EP</small></span>
      </div>
      <div class="evk-match">
        <button type="button" class="btn-grad" data-evk-play disabled>${esc(tt('events.playMatch', 'Play event match'))}</button>
        <span class="admin-events-muted">${esc(matchCost ? tt('events.matchCost', 'An event match costs {n} keys', { n: matchCost }) : '')} · ${esc(tt('events.matchSoon', 'Event matches are coming soon'))}</span>
      </div>
      <h4 data-evk-wheel-title>${esc(free > 0 ? tt('events.wheelBonus', 'Bonus Wheel') : tt('events.wheelTitle', 'Prize Wheel'))}</h4>
      <div class="evk-wheel-wrap">
        <div class="evk-pointer" aria-hidden="true"></div>
        <canvas class="evk-wheel" width="420" height="420" data-evk-wheel></canvas>
        <div class="evk-result" data-evk-result hidden></div>
      </div>
      <div class="evk-spin-row">
        <button type="button" class="btn-grad" data-evk-spin>${esc(free > 0
          ? tt('events.spinFree', 'Free spin ({n} left)', { n: free })
          : tt('events.spin', 'Spin ({n} tokens)', { n: cost }))}</button>
        <span class="admin-events-muted" data-evk-msg></span>
      </div>
      <h4>${esc(tt('events.milestones', 'Milestones'))}</h4>
      <ul class="events-ms-list evk-ms">${ms}</ul>
      <h4>${esc(tt('events.leaderboard', 'Leaderboard'))}</h4>
      <ol class="events-lb-list">${lb}</ol>
    </section>`;
  }

  function currentWheel(ev) {
    const cfg = ev.config || {};
    const wheels = (cfg.wheel && cfg.wheel.wheels) || {};
    const free = Number((ev.wallet || {}).wheel2_left || 0) > 0;
    const no = free ? '2' : '1';
    return { no: Number(no), slices: wheels[no] || [] };
  }

  function bind(root, ev, api) {
    const card = root.querySelector('.evk-card[data-event="' + ev.id + '"]');
    if (!card) return;
    const canvas = card.querySelector('[data-evk-wheel]');
    const cfg = ev.config || {};
    let wheel = currentWheel(ev);
    let rotation = 0;
    const paint = () => { if (canvas) drawWheel(canvas, wheel.slices, cfg); };
    paint();
    const btn = card.querySelector('[data-evk-spin]');
    const msg = card.querySelector('[data-evk-msg]');
    const resultBox = card.querySelector('[data-evk-result]');
    const w = ev.wallet || {};
    const cost = Number((cfg.wheel || {}).spin_cost || 10);
    if (btn && Number(w.wheel2_left || 0) < 1 && Number(w.tokens || 0) < cost) btn.disabled = true;
    if (!btn) return;
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      if (msg) msg.textContent = '';
      if (resultBox) resultBox.hidden = true;
      if (msg) msg.textContent = '';
      let res;
      try {
        res = await api.post('events_wheel_spin', { event_id: ev.id });
      } catch (e) {
        if (msg) msg.textContent = (e && e.message) || tt('events.spinFailed', 'Spin failed');
        btn.disabled = false;
        return;
      }
      // The wheel that was spun may differ from the one on screen (a free Bonus Wheel spin).
      const wheels = (cfg.wheel && cfg.wheel.wheels) || {};
      const spun = wheels[String(res.wheel)] || wheel.slices;
      if (res.wheel !== wheel.no) { wheel = { no: res.wheel, slices: spun }; paint(); }
      const center = sliceCenterDeg(spun, res.slice_index);
      const widthDeg = (visualWeights(spun)[res.slice_index] / TAU) * 360;
      const jitter = (Math.random() - 0.5) * widthDeg * 0.6;
      rotation = rotation - (rotation % 360) + 360 * 6 + (360 - center) + jitter;
      canvas.style.transition = 'transform 5.2s cubic-bezier(.12,.62,.1,1)';
      canvas.style.transform = 'rotate(' + rotation + 'deg)';
      await new Promise((r) => setTimeout(r, 5400));
      const rw = res.reward || {};
      if (resultBox) {
        resultBox.innerHTML = `<div class="evk-result-inner"><small>${esc(tt('events.youWon', 'You won'))}</small>`
          + `${iconHtml(rw.type, rw.amount, cfg, 36)}<strong>${esc(prizeLabel(rw.type, rw.amount))}</strong></div>`;
        resultBox.hidden = false;
      }
      if (res.pr_pack && typeof global.queueRankedPrReward === 'function') {
        try { global.queueRankedPrReward(res.pr_pack); } catch (e) { /* pack reveal is optional */ }
      }
      // Keep the wheel where it landed: update the wallet in place instead of redrawing the card.
      const nw = res.wallet || {};
      ev.wallet = nw;
      const chips = card.querySelectorAll('.evk-chip b');
      if (chips[0]) chips[0].textContent = nw.keys;
      if (chips[1]) chips[1].textContent = nw.tokens;
      const freeLeft = Number(nw.wheel2_left || 0);
      btn.textContent = freeLeft > 0
        ? tt('events.spinFree', 'Free spin ({n} left)', { n: freeLeft })
        : tt('events.spin', 'Spin ({n} tokens)', { n: cost });
      const title = card.querySelector('h4[data-evk-wheel-title]');
      if (title) title.textContent = freeLeft > 0 ? tt('events.wheelBonus', 'Bonus Wheel') : tt('events.wheelTitle', 'Prize Wheel');
      btn.disabled = freeLeft < 1 && Number(nw.tokens || 0) < cost;
      const next = currentWheel(ev);
      if (next.no !== wheel.no) {
        // Landed on (or finished) a Bonus Wheel: swap the face once the result has been seen.
        setTimeout(() => {
          wheel = next;
          canvas.style.transition = 'none';
          canvas.style.transform = 'rotate(0deg)';
          rotation = 0;
          paint();
        }, 1800);
      }
    });
  }

  // ------------------------------------------------------------------ key toast after normal matches

  function toastKeys(n, balance) {
    n = Number(n) || 0;
    if (n <= 0) return;
    let host = document.getElementById('evk-toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'evk-toast-host';
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    const el = document.createElement('div');
    el.className = 'evk-toast';
    el.innerHTML = `<img src="${keyIcon(false)}" width="34" height="34" alt=""><span>${esc(tt('toast.eventKeysEarned', '+{n} Event Keys', { n }))}</span>`;
    host.appendChild(el);
    setTimeout(() => { el.classList.add('is-out'); setTimeout(() => el.remove(), 500); }, 3200);
  }

  global.TCGEventKeys = {
    cardHtml, bind, iconHtml, prizeLabel, tokenIcon, keyIcon, tierFor, toastKeys, drawWheel,
  };
})(typeof window !== 'undefined' ? window : globalThis);
