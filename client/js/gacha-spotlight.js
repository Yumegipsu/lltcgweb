/**
 * Overhead scout spotlight rig — beams hang from a truss, splay to floor pools.
 * Rarities: grey (N) · gold (SR) · rainbow (UR). Results are predetermined;
 * `from` is spectacle-only (e.g. gold → rainbow for UR).
 *
 * UR cards skip the spin-in and use Balatro-style mouse/touch tilt instead.
 */
(function (global) {
  'use strict';

  const TIER = {
    grey: {
      rank: 0, core: '#ffffff', edge: '#cfe0f2', pool: '#e8f1ff',
      frame: '#cfe0f5', halo: 'rgba(205,228,255,.5)', text: 'N', power: 0.8, game: 'n',
    },
    gold: {
      rank: 1, core: '#fff6d2', edge: '#ffbc3d', pool: '#ffd88a',
      frame: '#ffc84d', halo: 'rgba(255,190,70,.6)', text: 'SR', power: 1.05, game: 'sr',
    },
    rainbow: {
      rank: 2, core: '#ffffff', edge: '#ffffff', pool: '#ffffff',
      frame: '#ffffff', halo: 'rgba(190,160,255,.7)', text: 'UR', power: 1.3, game: 'ur',
    },
  };
  const BANDS = ['#ff6fae', '#ffd36a', '#9df5a8', '#5fd8ff', '#b08bff'];

  const T = {
    first: 340,
    gap: 255,
    flipLead: 620,
    glitter: 480,
    bloomIn: 230,
    settle: 520,
    flipGap: 420,
    flourishDelay: 420,
    flourishLen: 1150,
    cardGap: 85,
  };
  const FLIP_LEN = T.glitter + T.bloomIn + T.settle;

  const Q = {
    dpr: Math.min(global.devicePixelRatio || 1, 2),
    motes: 46,
    sparkles: 26,
    sprite: [160, 700],
  };

  let reduceMotion = false;
  try {
    reduceMotion = !!global.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  } catch (_) { /* ignore */ }
  if (reduceMotion) {
    Q.motes = 10;
    Q.sparkles = 8;
  }

  const cache = new Map();
  let degraded = 0;

  let canvas = null;
  let ctx = null;
  let stage = null;
  let floorline = null;
  let cardsEl = null;
  let flourishEl = null;
  let flourishBig = null;
  let flourishSub = null;
  let skipBtn = null;
  let hintEl = null;

  let W = 0;
  let H = 0;
  let floorY = 0;
  let rigY = 0;
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
  let wash = 0;
  let plan = null;
  let resolveDone = null;
  let onResizeBound = null;
  let onStageClick = null;
  let onSkipClick = null;
  let sfxFn = null;
  let labels = {
    ur: 'UR', sr: 'SR', urSub: '', srSub: '', skip: 'Skip', hint: 'Tap to continue',
  };

  const RB_PHASES = 6;

  function fade(hex, a) {
    let h = String(hex || '').replace('#', '');
    if (h.length === 3) h = h.split('').map((x) => x + x).join('');
    const n = parseInt(h, 16) || 0;
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
  }

  function cone(key, stops) {
    if (cache.has(key)) return cache.get(key);
    const [w, h] = Q.sprite;
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const g = c.getContext('2d');
    for (let y = 0; y < h; y += 2) {
      const s = y / h;
      const half = (w / 2) * (0.028 + 0.972 * Math.pow(1 - s, 0.52)) + 1;
      const a = (0.3 + 0.7 * Math.pow(s, 0.85)) * 0.55;
      const grad = g.createLinearGradient(w / 2 - half, 0, w / 2 + half, 0);
      for (const [p, col] of stops) grad.addColorStop(p, col);
      g.globalAlpha = a;
      g.fillStyle = grad;
      g.fillRect(w / 2 - half, y, half * 2, 3);
    }
    cache.set(key, c);
    return c;
  }

  function solidCone(t) {
    return cone('s' + t.core + t.edge, [
      [0, 'rgba(0,0,0,0)'],
      [0.16, fade(t.edge, 0.35)],
      [0.34, t.edge],
      [0.5, t.core],
      [0.66, t.edge],
      [0.84, fade(t.edge, 0.35)],
      [1, 'rgba(0,0,0,0)'],
    ]);
  }

  function rainbowCone(phase) {
    const k = 'rb' + phase;
    if (cache.has(k)) return cache.get(k);
    const stops = [[0, 'rgba(0,0,0,0)']];
    const n = BANDS.length;
    for (let i = 0; i < n; i++) {
      const p = 0.12 + (0.76 * i) / (n - 1);
      stops.push([p, BANDS[(i + phase) % n]]);
    }
    stops.push([1, 'rgba(0,0,0,0)']);
    return cone(k, stops);
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
    gr.addColorStop(0.18, color);
    gr.addColorStop(0.44, fade(color, 0.34));
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
      Q.sparkles = (Q.sparkles * 0.5) | 0;
    }
    resize();
  }

  function playSfx(id) {
    try {
      if (typeof sfxFn === 'function') sfxFn(id);
    } catch (_) { /* ignore */ }
  }

  function resize() {
    if (!stage || !canvas || !ctx) return;
    W = stage.clientWidth;
    H = stage.clientHeight;
    canvas.width = Math.round(W * Q.dpr);
    canvas.height = Math.round(H * Q.dpr);
    ctx.setTransform(Q.dpr, 0, 0, Q.dpr, 0, 0);
    floorY = H * 0.785;
    rigY = -H * 0.22;
    diag = Math.hypot(W, H);
    if (floorline) floorline.style.top = (floorY / H * 100) + '%';
    layout();
  }

  function layout() {
    const n = slots.length;
    if (!n) return;

    // Beam pools stay on one floor row (spectacle).
    const beamPad = n > 4 ? 0.04 : 0.28;
    slots.forEach((s, i) => {
      s.bx = n === 1 ? W / 2 : W * beamPad + i * (W * (1 - beamPad * 2) / (n - 1));
      s.ax = W / 2 + (s.bx - W / 2) * 0.52;
    });

    // Card faces: 10+1 uses 5 / 5 / 1 so each card stays readable.
    if (n >= 10) {
      const cols = 5;
      const hPad = Math.min(0.05, 28 / W);
      const usable = W * (1 - hPad * 2);
      const gap = Math.min(16, Math.max(6, usable * 0.018));
      const cw = Math.min(
        Math.max(96, Math.min(148, W * 0.14)),
        (usable - gap * (cols - 1)) / cols
      );
      const cardH = cw * (4.1 / 3);
      const vGap = Math.min(14, cardH * 0.08);
      const rowsUsed = n > 10 ? 3 : 2;
      const stackH = rowsUsed * cardH + (rowsUsed - 1) * vGap;
      const baseY = Math.min(floorY - 8, H * 0.92);
      const topRowBaseline = baseY - stackH + cardH;

      slots.forEach((s, i) => {
        if (!s.el) return;
        let row;
        let col;
        let rowLen;
        if (i < 5) {
          row = 0;
          col = i;
          rowLen = 5;
        } else if (i < 10) {
          row = 1;
          col = i - 5;
          rowLen = 5;
        } else {
          row = 2;
          col = 0;
          rowLen = 1;
        }
        const rowWidth = rowLen * cw + (rowLen - 1) * gap;
        const left0 = (W - rowWidth) / 2;
        const x = left0 + col * (cw + gap) + cw / 2;
        const y = topRowBaseline + row * (cardH + vGap);
        s.el.style.left = x + 'px';
        s.el.style.top = y + 'px';
        s.el.style.width = cw + 'px';
      });
      return;
    }

    const pad = n > 4 ? 0.06 : 0.28;
    slots.forEach((s, i) => {
      if (!s.el) return;
      const span = n === 1 ? W * 0.42 : (W * (1 - pad * 2) / Math.max(1, n - 1));
      const maxW = n === 1 ? 230 : (n >= 6 ? 128 : 140);
      const cw = Math.min(n === 1 ? 230 : span * 0.9, maxW);
      const x = n === 1 ? W / 2 : W * pad + i * (W * (1 - pad * 2) / (n - 1));
      s.el.style.left = x + 'px';
      s.el.style.top = (floorY - 6) + 'px';
      s.el.style.width = cw + 'px';
    });
  }

  function mote(s) {
    parts.push({
      x: s.bx + (Math.random() - 0.5) * 70,
      y: floorY - Math.random() * 30,
      vx: (Math.random() - 0.5) * 10,
      vy: -18 - Math.random() * 40,
      life: 1,
      decay: 0.3 + Math.random() * 0.3,
      size: 3 + Math.random() * 6,
      col: s.tier === 'rainbow'
        ? BANDS[(Math.random() * BANDS.length) | 0]
        : TIER[s.tier].pool,
    });
  }

  function sparkle(s, p) {
    const x = s.ax + (s.bx - s.ax) * p + (Math.random() - 0.5) * 46 * p;
    const y = rigY + (floorY - rigY) * p;
    parts.push({
      x,
      y,
      vx: (s.bx - s.ax) * 0.22,
      vy: 170 + Math.random() * 190,
      life: 1,
      decay: 0.5 + Math.random() * 0.4,
      size: 4 + Math.random() * 8,
      col: Math.random() < 0.5 ? '#fff6d2' : BANDS[(Math.random() * BANDS.length) | 0],
    });
  }

  function burst(x, y, n, col) {
    for (let i = 0; i < n; i++) {
      const a = Math.random() * Math.PI * 2;
      const sp = 80 + Math.random() * 340;
      parts.push({
        x,
        y,
        vx: Math.cos(a) * sp,
        vy: Math.sin(a) * sp * 0.62 - 70,
        life: 1,
        decay: 0.65 + Math.random() * 0.5,
        size: 5 + Math.random() * 10,
        col: col === 'rainbow' ? BANDS[(Math.random() * BANDS.length) | 0] : col,
      });
    }
  }

  function buildPlan(results) {
    slots = results.map((r, i) => ({
      tier: r.from || r.rarity,
      finalTier: r.rarity,
      from: r.from || null,
      name: r.name || '',
      image: r.image || null,
      ignite: T.first + i * T.gap,
      flipAt: 0,
      done: !r.from,
      i,
      phase: 0,
    }));
    const last = T.first + (slots.length - 1) * T.gap;
    let cur = last + T.flipLead;
    slots.filter((s) => s.from).forEach((s) => {
      s.flipAt = cur;
      cur += FLIP_LEN + T.flipGap;
    });
    const flipsEnd = slots.some((s) => s.from) ? cur - T.flipGap : last + 260;
    const best = slots.reduce((m, s) => Math.max(m, TIER[s.finalTier]?.rank ?? 0), 0);
    const fAt = flipsEnd + T.flourishDelay;
    const has = best >= 1;
    const cAt = has ? fAt + T.flourishLen : flipsEnd + 320;
    return {
      best,
      has,
      fAt,
      cAt,
      endAt: cAt + slots.length * T.cardGap + 420,
    };
  }

  function drawSlot(s) {
    const age = now - s.ignite;
    if (age < 0) return;
    const t = TIER[s.tier];
    if (!t) return;

    const rise = Math.min(1, age / 300);
    const strike = 1 + 1.2 * Math.exp(-age / 140) * Math.sin(age / 62);
    let widen = 1;
    let boost = 0;
    let dim = 1;

    if (s.from && !s.done && now >= s.flipAt) {
      const e = now - s.flipAt;
      if (e < T.glitter) {
        const p = e / T.glitter;
        boost = p * 1.3;
        widen = 1 + p * 0.1;
        if (!s._glitterSfx) {
          s._glitterSfx = true;
          playSfx('energy_chip');
        }
        if (!reduceMotion && parts.length < Q.motes + Q.sparkles && Math.random() < 0.85) {
          sparkle(s, Math.random() * 0.95);
        }
      } else if (e < T.glitter + T.bloomIn) {
        const p = (e - T.glitter) / T.bloomIn;
        if (s.tier !== s.finalTier) {
          s.tier = s.finalTier;
          flash = Math.max(flash, 0.75);
          rings.push({ x: s.bx, y: floorY, r: 14, a: 1, col: '#ffffff' });
          rings.push({ x: s.bx, y: floorY, r: 8, a: 0.8, col: TIER[s.finalTier].pool });
          burst(
            s.bx,
            floorY - 30,
            26,
            s.finalTier === 'rainbow' ? 'rainbow' : TIER[s.finalTier].pool
          );
          playSfx(s.finalTier === 'rainbow' ? 'yell_reveal' : 'pack_reveal');
        }
        boost = 2.2 * (1 - p);
        dim = 1 + 1.4 * (1 - p);
        widen = 1.3 - 0.2 * p;
      } else {
        const p = Math.min(1, (e - T.glitter - T.bloomIn) / T.settle);
        boost = 0.8 * (1 - p);
        widen = 1.12 - 0.12 * p;
        if (p >= 1) s.done = true;
      }
    }

    const flick = 0.86 + 0.14 * Math.sin(now * 0.0042 + s.i * 2.3);
    const sway = reduceMotion ? 0 : Math.sin(now * 0.0011 + s.i * 1.7) * W * 0.006;
    const bx = s.bx + sway;

    const dx = bx - s.ax;
    const dy = floorY - rigY;
    const len = Math.hypot(dx, dy) * (0.99 + 0.02 * flick);
    const ang = Math.atan2(dx, -dy);
    const baseW = (W < 560 ? 130 : 182)
      * (slots.length === 1 ? 2.0 : slots.length > 8 ? 0.72 : 1)
      * widen;

    const sprite = s.tier === 'rainbow'
      ? rainbowCone(Math.floor(now / 140) % RB_PHASES)
      : solidCone(t);

    ctx.save();
    ctx.translate(s.ax, rigY);
    ctx.rotate(ang);
    ctx.globalAlpha = Math.min(1, rise * strike)
      * (0.62 + 0.2 * boost) * t.power * flick * dim;
    ctx.drawImage(sprite, -baseW / 2, -len, baseW, len);
    ctx.restore();

    const poolCol = s.tier === 'rainbow'
      ? BANDS[(now / 140 | 0) % BANDS.length]
      : t.pool;
    const pw = baseW * 1.9;
    const ph = pw * 0.28;
    ctx.globalAlpha = Math.min(1, rise) * (0.62 + 0.3 * boost) * t.power;
    ctx.drawImage(glow(poolCol, 256), bx - pw / 2, floorY - ph / 2, pw, ph);
    ctx.globalAlpha *= 0.8;
    ctx.drawImage(glow(t.core, 128), bx - pw * 0.34, floorY - ph * 0.3, pw * 0.68, ph * 0.6);

    if (!reduceMotion && parts.length < Q.motes && Math.random() < 0.12) mote(s);
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
    flash = Math.max(flash, ur ? 0.7 : 0.4);
    playSfx(ur ? 'splash_success' : 'splash_live');
    if (ur) {
      slots.forEach((s) => burst(
        s.bx,
        floorY - H * 0.2,
        10,
        s.finalTier === 'rainbow' ? 'rainbow' : TIER[s.finalTier].pool
      ));
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
        burst(
          s.bx,
          floorY - 50,
          7,
          s.finalTier === 'rainbow' ? 'rainbow' : TIER[s.finalTier].pool
        );
        if (i === 0) playSfx('pack_reveal');
        else if (i % 2 === 0) playSfx('card_flip');
        if (s.finalTier === 'rainbow') {
          const mini = s.el.querySelector('.gacha-spot-mini');
          if (mini) bindUrTilt(mini);
        }
      }, i * T.cardGap);
    });
  }

  function buildCards() {
    if (!cardsEl) return;
    cardsEl.innerHTML = '';
    slots.forEach((s) => {
      const t = TIER[s.finalTier] || TIER.grey;
      const el = document.createElement('div');
      el.className = 'gacha-spot-slot'
        + (s.finalTier === 'rainbow' ? ' is-ur' : s.finalTier === 'gold' ? ' is-sr' : ' is-n');
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
    layout();
  }

  function frame(ts) {
    if (!running || !ctx) return;
    if (!t0) t0 = ts;
    now = ts - t0;
    const dt = Math.min(0.05, (ts - (lastTs || ts)) / 1000);
    lastTs = ts;

    ctx.clearRect(0, 0, W, H);
    ctx.globalCompositeOperation = 'lighter';

    const lit = slots.reduce(
      (a, s) => a + (now >= s.ignite ? (TIER[s.tier]?.power || 0) : 0),
      0
    );
    wash += ((lit / Math.max(1, slots.length)) - wash) * Math.min(1, dt * 3);
    ctx.globalAlpha = 0.1 * wash;
    ctx.drawImage(glow('#b98bff', 512), -W * 0.15, floorY - H * 0.55, W * 1.3, H * 0.72);

    for (const s of slots) drawSlot(s);

    for (let i = rings.length - 1; i >= 0; i--) {
      const r = rings[i];
      r.r += dt * diag * 1.25;
      r.a -= dt * 1.6;
      if (r.a <= 0) {
        rings.splice(i, 1);
        continue;
      }
      ctx.globalAlpha = r.a * 0.8;
      ctx.drawImage(glow(r.col, 256), r.x - r.r, r.y - r.r * 0.34, r.r * 2, r.r * 0.68);
    }

    for (let i = parts.length - 1; i >= 0; i--) {
      const p = parts[i];
      p.x += p.vx * dt;
      p.y += p.vy * dt;
      p.vy += 30 * dt;
      p.life -= p.decay * dt;
      if (p.life <= 0) {
        parts.splice(i, 1);
        continue;
      }
      const s2 = p.size * (0.55 + p.life);
      ctx.globalAlpha = p.life * 0.85;
      ctx.drawImage(glow(p.col, 64), p.x - s2 / 2, p.y - s2 / 2, s2, s2);
    }

    if (flash > 0) {
      flash -= dt * 2.7;
      ctx.globalAlpha = Math.max(0, flash) * 0.8;
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, W, H);
    }

    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;

    for (const s of slots) {
      if (!s._lit && now >= s.ignite) {
        s._lit = true;
        rings.push({ x: s.bx, y: floorY, r: 6, a: 0.6, col: TIER[s.tier].pool });
        burst(s.bx, floorY - 8, 5, TIER[s.tier].pool);
        if (s.i === 0) playSfx('pack_open');
        else playSfx('skill_tick');
      }
    }
    if (plan.has && !plan._f && now >= plan.fAt) {
      plan._f = true;
      showFlourish(plan.best);
    }
    if (!plan._c && now >= plan.cAt) {
      plan._c = true;
      hideFlourish();
      revealCards();
    }
    if (!plan._d && now >= plan.endAt) {
      plan._d = true;
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
    floorline = stage.querySelector('#gacha-spot-floorline')
      || stage.querySelector('#gacha-spot-lip')
      || document.getElementById('gacha-spot-floorline')
      || document.getElementById('gacha-spot-lip');
    cardsEl = stage.querySelector('#gacha-spot-cards') || document.getElementById('gacha-spot-cards');
    flourishEl = stage.querySelector('#gacha-spot-flourish')
      || document.getElementById('gacha-spot-flourish');
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
      s.done = true;
      s._lit = true;
      s.ignite = -1;
    });
    hideFlourish();
    if (!plan._c) {
      plan._c = true;
      revealCards();
    }
    plan.endAt = now + slots.length * T.cardGap + 250;
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

  function stop() {
    running = false;
    cancelAnimationFrame(raf);
    parts.length = 0;
    rings.length = 0;
    flash = 0;
    wash = 0;
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

  /**
   * @param {Array<{rarity:string,from?:string,name?:string,image?:string}>} results
   * @param {{sfx?:Function,labels?:object,root?:HTMLElement}} [opts]
   * @returns {Promise<void>}
   */
  function play(results, opts) {
    opts = opts || {};
    if (!bindDom(opts.root)) return Promise.resolve();
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
    wash = 0;
    raf = requestAnimationFrame(frame);

    return new Promise((resolve) => {
      resolveDone = () => resolve();
    });
  }

  function fromPulls(pulls, imageFn) {
    return (pulls || []).map((p) => {
      const tier = String(p.tier || 'n');
      const rarity = tier === 'ur' ? 'rainbow' : tier === 'sr' ? 'gold' : 'grey';
      const row = {
        rarity,
        name: p.name_en || p.name || '',
        image: typeof imageFn === 'function' ? imageFn(p) : (p.image || ''),
      };
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
