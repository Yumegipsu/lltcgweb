/**
 * SIFAS-style scout spotlight — canvas beams, rarity flips, card reveal.
 * Rarities: grey (N) · gold (SR) · rainbow (UR). Results are predetermined;
 * `from` is spectacle-only (e.g. gold → rainbow for UR).
 *
 * UR cards skip the spin-in and use Balatro-style mouse/touch tilt instead.
 */
(function (global) {
  'use strict';

  const TIER = {
    grey: {
      rank: 0,
      beam: ['#cddff5', '#ffffff'],
      hot: '#dbe8ff',
      frame: '#b6c8e0',
      halo: 'rgba(180,210,255,.5)',
      text: 'N',
      power: 0.62,
      game: 'n',
    },
    gold: {
      rank: 1,
      beam: ['#ffd166', '#fff3c6'],
      hot: '#ffd88a',
      frame: '#ffc84d',
      halo: 'rgba(255,190,70,.6)',
      text: 'SR',
      power: 0.95,
      game: 'sr',
    },
    rainbow: {
      rank: 2,
      beam: null,
      hot: '#ffffff',
      frame: '#ffffff',
      halo: 'rgba(190,160,255,.7)',
      text: 'UR',
      power: 1.35,
      game: 'ur',
    },
  };
  const HUES = 12;
  const RAINBOW = Array.from({ length: HUES }, (_, i) => `hsl(${(i * 360) / HUES | 0} 100% 66%)`);

  const TIMING = {
    first: 380,
    gap: 115,
    flipLead: 520,
    flipLen: 560,
    flipGap: 340,
    flourish: 420,
    flourishLen: 1150,
    cardGap: 85,
  };

  const Q = {
    dpr: Math.min(global.devicePixelRatio || 1, 2),
    motes: 40,
    burst: 34,
    sprite: [128, 560],
  };

  let reduceMotion = false;
  try {
    reduceMotion = !!global.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  } catch (_) { /* ignore */ }
  if (reduceMotion) {
    Q.motes = 8;
    Q.burst = 10;
  }

  const cache = new Map();
  let degraded = 0;

  let canvas = null;
  let ctx = null;
  let stage = null;
  let lip = null;
  let cardsEl = null;
  let flourishEl = null;
  let flourishBig = null;
  let flourishSub = null;
  let skipBtn = null;
  let hintEl = null;

  let W = 0;
  let H = 0;
  let floorY = 0;
  let diag = 0;
  let slots = [];
  let running = false;
  let raf = 0;
  let t0 = 0;
  let now = 0;
  let lastTs = 0;
  let flash = 0;
  let rings = [];
  let parts = [];
  let sweep = -1;
  let plan = null;
  let resolveDone = null;
  let onResizeBound = null;
  let onStageClick = null;
  let onSkipClick = null;
  let sfxFn = null;
  let labels = { ur: 'UR', sr: 'SR', urSub: '', srSub: '', skip: 'Skip', hint: 'Tap to continue' };

  function tint(col, a) {
    if (col[0] === '#') {
      let h = col.slice(1);
      if (h.length === 3) h = h.split('').map((x) => x + x).join('');
      const n = parseInt(h, 16);
      return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
    }
    return col.replace('hsl', 'hsla').replace(')', ` / ${a})`);
  }

  function beamSprite(color) {
    const k = 'b' + color;
    if (cache.has(k)) return cache.get(k);
    const [w, h] = Q.sprite;
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const g = c.getContext('2d');
    for (let y = 0; y < h; y += 2) {
      const t = 1 - y / h;
      const half = (w / 2) * (0.045 + 0.955 * Math.pow(1 - t, 0.62)) + 1;
      const a = Math.pow(t, 1.5) * 0.92;
      const grad = g.createLinearGradient(w / 2 - half, 0, w / 2 + half, 0);
      grad.addColorStop(0, 'rgba(0,0,0,0)');
      grad.addColorStop(0.42, color);
      grad.addColorStop(0.5, '#ffffff');
      grad.addColorStop(0.58, color);
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      g.globalAlpha = a;
      g.fillStyle = grad;
      g.fillRect(w / 2 - half, y, half * 2, 3);
    }
    cache.set(k, c);
    return c;
  }

  function glow(color, size) {
    const k = 'g' + color + size;
    if (cache.has(k)) return cache.get(k);
    const c = document.createElement('canvas');
    c.width = c.height = size;
    const g = c.getContext('2d');
    const r = size / 2;
    const gr = g.createRadialGradient(r, r, 0, r, r, r);
    gr.addColorStop(0, 'rgba(255,255,255,1)');
    gr.addColorStop(0.16, color);
    gr.addColorStop(0.42, tint(color, 0.35));
    gr.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = gr;
    g.fillRect(0, 0, size, size);
    cache.set(k, c);
    return c;
  }

  function ringSprite(color, size) {
    const k = 'r' + color + size;
    if (cache.has(k)) return cache.get(k);
    const c = document.createElement('canvas');
    c.width = c.height = size;
    const g = c.getContext('2d');
    const r = size / 2;
    const gr = g.createRadialGradient(r, r, r * 0.66, r, r, r);
    gr.addColorStop(0, 'rgba(0,0,0,0)');
    gr.addColorStop(0.6, tint(color, 0.7));
    gr.addColorStop(0.85, color);
    gr.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = gr;
    g.fillRect(0, 0, size, size);
    cache.set(k, c);
    return c;
  }

  function degrade() {
    if (Q.dpr > 1) Q.dpr = 1;
    else {
      Q.motes = (Q.motes * 0.5) | 0;
      Q.burst = (Q.burst * 0.5) | 0;
    }
    resize();
  }

  function resize() {
    if (!stage || !canvas || !ctx) return;
    W = stage.clientWidth;
    H = stage.clientHeight;
    canvas.width = Math.round(W * Q.dpr);
    canvas.height = Math.round(H * Q.dpr);
    ctx.setTransform(Q.dpr, 0, 0, Q.dpr, 0, 0);
    floorY = H * 0.76;
    diag = Math.hypot(W, H);
    if (lip) lip.style.top = floorY + 'px';
    layoutSlots();
  }

  function layoutSlots() {
    const n = slots.length;
    if (!n) return;
    const pad = n > 4 ? 0.04 : 0.24;
    slots.forEach((s, i) => {
      s.x = n === 1 ? W / 2 : W * pad + i * (W * (1 - pad * 2) / (n - 1));
      s.tilt = ((s.x - W / 2) / W) * 0.3;
      if (s.el) {
        const span = n === 1 ? W * 0.42 : (W * (1 - pad * 2) / (n - 1));
        const cw = Math.min(n === 1 ? 230 : span * 0.82, n === 1 ? 230 : n > 8 ? 72 : 104);
        s.el.style.left = s.x + 'px';
        s.el.style.top = (floorY - 10) + 'px';
        s.el.style.width = cw + 'px';
      }
    });
  }

  function mote(s) {
    parts.push({
      x: s.x + (Math.random() - 0.5) * 36,
      y: floorY - Math.random() * 20,
      vx: (Math.random() - 0.5) * 14 + s.tilt * 40,
      vy: -30 - Math.random() * 70,
      life: 1,
      decay: 0.32 + Math.random() * 0.3,
      size: 3 + Math.random() * 7,
      tier: s.tier,
      hue: (Math.random() * HUES) | 0,
    });
  }

  function burst(x, y, n, tier) {
    for (let i = 0; i < n; i++) {
      const a = Math.random() * Math.PI * 2;
      const sp = 90 + Math.random() * 380;
      parts.push({
        x,
        y,
        vx: Math.cos(a) * sp,
        vy: Math.sin(a) * sp * 0.7 - 60,
        life: 1,
        decay: 0.7 + Math.random() * 0.5,
        size: 5 + Math.random() * 11,
        tier,
        hue: (Math.random() * HUES) | 0,
      });
    }
  }

  function playSfx(id) {
    try {
      if (typeof sfxFn === 'function') sfxFn(id);
    } catch (_) { /* ignore */ }
  }

  function buildPlan(results) {
    slots = results.map((r, i) => ({
      tier: r.from || r.rarity,
      finalTier: r.rarity,
      from: r.from || null,
      name: r.name || '',
      image: r.image || null,
      ignite: TIMING.first + i * TIMING.gap,
      flipAt: 0,
      flipped: !r.from,
      i,
    }));
    const lastIgnite = TIMING.first + (slots.length - 1) * TIMING.gap;
    let cursor = lastIgnite + TIMING.flipLead;
    slots.filter((s) => s.from).forEach((s) => {
      s.flipAt = cursor;
      cursor += TIMING.flipGap;
    });
    const flipsEnd = slots.some((s) => s.from) ? cursor + TIMING.flipLen : lastIgnite + 250;
    const best = slots.reduce((m, s) => Math.max(m, TIER[s.finalTier]?.rank ?? 0), 0);
    const flourishAt = flipsEnd + TIMING.flourish;
    const hasFlourish = best >= 1;
    const cardsAt = hasFlourish ? flourishAt + TIMING.flourishLen : flipsEnd + 300;
    return {
      best,
      hasFlourish,
      flourishAt,
      cardsAt,
      endAt: cardsAt + slots.length * TIMING.cardGap + 400,
    };
  }

  function beamColors(tier, i, timeMs) {
    if (tier !== 'rainbow') return TIER[tier].beam;
    const o = Math.floor(timeMs / 85) + i * 3;
    return [RAINBOW[o % HUES], RAINBOW[(o + 4) % HUES]];
  }

  function drawSlot(s) {
    const age = now - s.ignite;
    if (age < 0) return;
    const t = TIER[s.tier];
    if (!t) return;

    const rise = Math.min(1, age / 240);
    const pop = 1 + 0.9 * Math.exp(-age / 120) * Math.sin(age / 55);
    let width = 1;
    let extra = 0;

    if (s.from && !s.flipped && now >= s.flipAt) {
      const p = (now - s.flipAt) / TIMING.flipLen;
      if (p < 0.46) {
        width = Math.max(0.06, 1 - p / 0.46);
        extra = p * 1.6;
      } else {
        if (s.tier !== s.finalTier) {
          s.tier = s.finalTier;
          flash = Math.max(flash, 0.55);
          rings.push({ x: s.x, y: floorY, r: 10, a: 0.9, tier: s.tier });
          burst(s.x, floorY - 40, (Q.burst * 0.7) | 0, s.tier);
          playSfx(s.tier === 'rainbow' ? 'yell_reveal' : 'pack_reveal');
        }
        const q = (p - 0.46) / 0.54;
        width = 0.06 + (1 - 0.06) * (1 - Math.pow(1 - q, 3)) * (1 + 0.28 * Math.exp(-q * 6) * Math.sin(q * 14));
        extra = (1 - q) * 1.1;
      }
      if (p >= 1) s.flipped = true;
    }

    const flick = 0.78 + 0.22 * Math.sin(now * 0.006 + s.i * 2.1);
    const alpha = Math.min(1, rise) * (0.3 + 0.22 * extra) * t.power * flick;
    const [c1, c2] = beamColors(s.tier, s.i, now);
    const len = (H * 0.92) * (0.9 + 0.1 * flick) * Math.min(1.15, pop * rise);

    ctx.save();
    ctx.translate(s.x, floorY);
    ctx.rotate(s.tilt);

    const baseW = (W < 560 ? 112 : 160) * (slots.length === 1 ? 2.1 : slots.length > 8 ? 0.72 : 1);
    ctx.globalAlpha = alpha;
    ctx.drawImage(beamSprite(c1), -baseW * width / 2, -len, baseW * width, len);
    ctx.globalAlpha = alpha * 0.75;
    ctx.drawImage(beamSprite(c2), -baseW * width * 0.42 / 2, -len * 0.82, baseW * width * 0.42, len * 0.82);
    ctx.restore();

    const hot = glow(s.tier === 'rainbow' ? beamColors(s.tier, s.i, now)[0] : t.hot, 256);
    const hw = baseW * width * 2.1;
    const hh = hw * 0.34;
    ctx.globalAlpha = Math.min(1, rise) * (0.5 + 0.4 * extra) * t.power;
    ctx.drawImage(hot, s.x - hw / 2, floorY - hh / 2, hw, hh);

    if (!reduceMotion && parts.length < Q.motes && Math.random() < 0.22) mote(s);
  }

  let perfAcc = 0;
  let perfN = 0;
  function perf(dt) {
    perfAcc += dt;
    perfN++;
    if (perfN >= 45) {
      const avg = perfAcc / perfN;
      perfAcc = 0;
      perfN = 0;
      if (avg > 0.024 && degraded < 2) {
        degraded++;
        degrade();
      }
    }
  }

  function showFlourish(best) {
    if (!flourishEl || !flourishBig || !flourishSub) return;
    const ur = best === 2;
    flourishEl.className = 'gacha-spot-flourish ' + (ur ? 'is-rainbow' : 'is-gold');
    flourishBig.textContent = ur ? labels.ur : labels.sr;
    flourishSub.textContent = ur ? labels.urSub : labels.srSub;
    void flourishEl.offsetWidth;
    flourishEl.classList.add('is-show');
    flash = Math.max(flash, ur ? 0.8 : 0.45);
    playSfx(ur ? 'yell_reveal' : 'pack_reveal');
    if (ur) {
      sweep = 0;
      slots.forEach((s) => burst(s.x, floorY - H * 0.25, (Q.burst * 0.5) | 0, s.finalTier));
    }
  }

  function hideFlourish() {
    if (!flourishEl) return;
    flourishEl.classList.remove('is-show');
    flourishEl.classList.add('is-out');
  }

  function bindUrTilt(mini) {
    if (!mini || mini._gachaTiltBound) return;
    mini._gachaTiltBound = true;
    mini.classList.add('gacha-spot-tilt');
    const onEnter = () => mini.classList.add('is-tilting');
    const onLeave = () => {
      mini.classList.remove('is-tilting');
      mini.style.setProperty('--foil-rx', '0deg');
      mini.style.setProperty('--foil-ry', '0deg');
      mini.style.setProperty('--foil-x', '50%');
      mini.style.setProperty('--foil-y', '50%');
    };
    const onMove = (e) => {
      const rect = mini.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const x = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
      const y = Math.max(0, Math.min(1, (e.clientY - rect.top) / rect.height));
      mini.style.setProperty('--foil-x', `${x * 100}%`);
      mini.style.setProperty('--foil-y', `${y * 100}%`);
      // Slightly stronger than in-game hand tilt for spotlight flair.
      mini.style.setProperty('--foil-ry', `${(x - 0.5) * 18}deg`);
      mini.style.setProperty('--foil-rx', `${(0.5 - y) * 14}deg`);
    };
    mini.addEventListener('pointerenter', onEnter);
    mini.addEventListener('pointerleave', onLeave);
    mini.addEventListener('pointermove', onMove);
  }

  function revealCards() {
    slots.forEach((s, i) => {
      setTimeout(() => {
        if (!s.el) return;
        s.el.classList.add('is-in');
        burst(s.x, floorY - 60, 8, s.finalTier);
        if (i === 0) playSfx('card_flip');
        if (s.finalTier === 'rainbow') {
          const mini = s.el.querySelector('.gacha-spot-mini');
          if (mini) bindUrTilt(mini);
        }
      }, i * TIMING.cardGap);
    });
  }

  function buildCards() {
    if (!cardsEl) return;
    cardsEl.innerHTML = '';
    slots.forEach((s) => {
      const t = TIER[s.finalTier] || TIER.grey;
      const el = document.createElement('div');
      el.className = 'gacha-spot-slot' + (s.finalTier === 'rainbow' ? ' is-ur' : s.finalTier === 'gold' ? ' is-sr' : ' is-n');
      el.style.setProperty('--frame', t.frame);
      el.style.setProperty('--halo', t.halo);
      const mini = document.createElement('div');
      mini.className = 'gacha-spot-mini' + (s.finalTier === 'rainbow' ? ' is-rainbow' : '');
      const face = document.createElement('div');
      face.className = 'gacha-spot-face';
      if (s.image) {
        const im = new Image();
        im.src = s.image;
        im.alt = s.name || '';
        im.decoding = 'async';
        face.appendChild(im);
      } else {
        face.textContent = t.text;
      }
      mini.appendChild(face);
      el.appendChild(mini);
      cardsEl.appendChild(el);
      s.el = el;
    });
    layoutSlots();
  }

  function frame(ts) {
    if (!running || !ctx) return;
    if (!t0) t0 = ts;
    now = ts - t0;
    const dt = Math.min(0.05, (ts - (lastTs || ts)) / 1000);
    lastTs = ts;

    ctx.clearRect(0, 0, W, H);
    ctx.globalCompositeOperation = 'lighter';

    const haze = glow('#4a6fd0', 512);
    ctx.globalAlpha = 0.22;
    ctx.drawImage(haze, -W * 0.1, floorY - H * 0.09, W * 1.2, H * 0.18);

    for (const s of slots) drawSlot(s);

    for (let i = rings.length - 1; i >= 0; i--) {
      const r = rings[i];
      r.r += dt * diag * 1.1;
      r.a -= dt * 1.4;
      if (r.a <= 0) {
        rings.splice(i, 1);
        continue;
      }
      const sp = ringSprite(r.tier === 'rainbow' ? RAINBOW[(now / 80 | 0) % HUES] : TIER[r.tier].hot, 256);
      ctx.globalAlpha = r.a;
      ctx.drawImage(sp, r.x - r.r, r.y - r.r * 0.36, r.r * 2, r.r * 0.72);
    }

    for (let i = parts.length - 1; i >= 0; i--) {
      const p = parts[i];
      p.x += p.vx * dt;
      p.y += p.vy * dt;
      p.vy += 22 * dt;
      p.life -= p.decay * dt;
      if (p.life <= 0) {
        parts.splice(i, 1);
        continue;
      }
      const col = p.tier === 'rainbow' ? RAINBOW[p.hue] : TIER[p.tier]?.hot || '#fff';
      const s2 = p.size * (0.6 + p.life);
      ctx.globalAlpha = p.life * 0.8;
      ctx.drawImage(glow(col, 64), p.x - s2 / 2, p.y - s2 / 2, s2, s2);
    }

    if (sweep >= 0) {
      sweep += dt * 1.5;
      if (sweep > 1.4) sweep = -1;
      else {
        const x = -W * 0.4 + sweep * W * 1.5;
        const g = ctx.createLinearGradient(x - W * 0.3, 0, x + W * 0.3, 0);
        g.addColorStop(0, 'rgba(0,0,0,0)');
        g.addColorStop(0.5, 'rgba(255,255,255,.16)');
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.globalAlpha = Math.max(0, 1 - Math.abs(sweep - 0.6));
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);
      }
    }

    if (flash > 0) {
      flash -= dt * 2.6;
      ctx.globalAlpha = Math.max(0, flash) * 0.85;
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, W, H);
    }

    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;

    for (const s of slots) {
      if (!s._lit && now >= s.ignite) {
        s._lit = true;
        rings.push({ x: s.x, y: floorY, r: 6, a: 0.7, tier: s.tier });
        burst(s.x, floorY - 10, 6, s.tier);
        if (s.i === 0) playSfx('pack_open');
      }
    }
    if (plan.hasFlourish && !plan._flourish && now >= plan.flourishAt) {
      plan._flourish = true;
      showFlourish(plan.best);
    }
    if (!plan._cards && now >= plan.cardsAt) {
      plan._cards = true;
      hideFlourish();
      revealCards();
    }
    if (!plan._done && now >= plan.endAt) {
      plan._done = true;
      if (hintEl) {
        hintEl.hidden = false;
        hintEl.textContent = labels.hint;
      }
      if (resolveDone) {
        const done = resolveDone;
        resolveDone = null;
        done();
      }
    }

    perf(dt);
    raf = requestAnimationFrame(frame);
  }

  function bindDom(root) {
    stage = root || document.getElementById('gacha-spot');
    if (!stage) return false;
    canvas = stage.querySelector('#gacha-spot-fx') || document.getElementById('gacha-spot-fx');
    lip = stage.querySelector('#gacha-spot-lip') || document.getElementById('gacha-spot-lip');
    cardsEl = stage.querySelector('#gacha-spot-cards') || document.getElementById('gacha-spot-cards');
    flourishEl = stage.querySelector('#gacha-spot-flourish') || document.getElementById('gacha-spot-flourish');
    flourishBig = flourishEl?.querySelector('.gacha-spot-flourish-big');
    flourishSub = flourishEl?.querySelector('.gacha-spot-flourish-sub');
    skipBtn = document.getElementById('gacha-spot-skip');
    hintEl = document.getElementById('gacha-pull-hint') || document.getElementById('gacha-spot-hint');
    if (!canvas) return false;
    ctx = canvas.getContext('2d', { alpha: true });
    return !!ctx;
  }

  function finish() {
    if (!running || !plan) return;
    slots.forEach((s) => {
      s.tier = s.finalTier;
      s.flipped = true;
      s._lit = true;
      s.ignite = -1;
    });
    hideFlourish();
    if (!plan._cards) {
      plan._cards = true;
      revealCards();
    }
    plan.endAt = now + slots.length * TIMING.cardGap + 200;
  }

  function stop() {
    running = false;
    cancelAnimationFrame(raf);
    parts.length = 0;
    rings.length = 0;
    flash = 0;
    sweep = -1;
    resolveDone = null;
    if (ctx && canvas) ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (flourishEl) {
      flourishEl.className = 'gacha-spot-flourish';
      flourishEl.style.opacity = '';
    }
    if (hintEl) hintEl.hidden = true;
    if (cardsEl) cardsEl.innerHTML = '';
    slots = [];
    plan = null;
    unbindListeners();
  }

  function unbindListeners() {
    if (onResizeBound) {
      global.removeEventListener('resize', onResizeBound);
      onResizeBound = null;
    }
    if (stage && onStageClick) {
      stage.removeEventListener('click', onStageClick);
      onStageClick = null;
    }
    if (skipBtn && onSkipClick) {
      skipBtn.removeEventListener('click', onSkipClick);
      onSkipClick = null;
    }
  }

  /**
   * @param {Array<{rarity:string,from?:string,name?:string,image?:string}>} results
   * @param {{sfx?:Function,labels?:object,root?:HTMLElement}} [opts]
   * @returns {Promise<void>}
   */
  function play(results, opts) {
    opts = opts || {};
    if (!bindDom(opts.root)) {
      return Promise.resolve();
    }
    sfxFn = opts.sfx || null;
    if (opts.labels) labels = { ...labels, ...opts.labels };
    if (skipBtn) skipBtn.textContent = labels.skip;

    stop();
    unbindListeners();

    onResizeBound = () => resize();
    global.addEventListener('resize', onResizeBound, { passive: true });
    onStageClick = () => finish();
    stage.addEventListener('click', onStageClick);
    if (skipBtn) {
      onSkipClick = (e) => {
        e.stopPropagation();
        finish();
      };
      skipBtn.addEventListener('click', onSkipClick);
    }

    plan = buildPlan(Array.isArray(results) ? results : []);
    buildCards();
    resize();
    running = true;
    t0 = 0;
    lastTs = 0;
    now = 0;
    raf = requestAnimationFrame(frame);

    return new Promise((resolve) => {
      resolveDone = () => {
        resolve();
      };
    });
  }

  /** Map game tiers n/sr/ur → spotlight rarities + flip bait. */
  function fromPulls(pulls, imageFn) {
    return (pulls || []).map((p) => {
      const tier = String(p.tier || 'n');
      const rarity = tier === 'ur' ? 'rainbow' : tier === 'sr' ? 'gold' : 'grey';
      const row = {
        rarity,
        name: p.name_en || p.name || '',
        image: typeof imageFn === 'function' ? imageFn(p) : (p.image || ''),
      };
      // Predetermined flips for spectacle (result already known).
      if (tier === 'ur') row.from = 'gold';
      else if (tier === 'sr') row.from = 'grey';
      return row;
    });
  }

  global.GachaSpotlight = {
    play,
    stop,
    finish,
    fromPulls,
    get isPlaying() {
      return running;
    },
  };
})(typeof window !== 'undefined' ? window : globalThis);
