/**
 * TCG HTTP API client — game + account endpoints, sync metadata capture.
 */
(function (global) {
  'use strict';

  global.API = './api.php';
  global.ACCOUNT_API = './account.php';
  global.WRAPPED_API = '/wrapped/api.php';
  global.AUTH_FETCH_TIMEOUT_MS = global.AUTH_FETCH_TIMEOUT_MS || 12000;
  global.RECONNECT_FETCH_TIMEOUT_MS = global.RECONNECT_FETCH_TIMEOUT_MS || 8000;

  global.fetchWithTimeout = async function fetchWithTimeout(url, options = {}, ms = global.AUTH_FETCH_TIMEOUT_MS) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), ms);
    try {
      return await fetch(url, { ...options, signal: ctrl.signal });
    } catch (e) {
      if (e && e.name === 'AbortError') throw new Error('Request timed out');
      throw e;
    } finally {
      clearTimeout(timer);
    }
  };

  global.handleMissionCompletions = function handleMissionCompletions(res) {
    if (!res || typeof res !== 'object') return;
    if (Array.isArray(res.mission_completions) && res.mission_completions.length) {
      if (global.TCGMissions && typeof global.TCGMissions.onMissionCompletions === 'function') {
        global.TCGMissions.onMissionCompletions(res.mission_completions);
      }
    }
    if (typeof res.claimable_count === 'number' && global.TCGMissions && typeof global.TCGMissions.syncHubBadge === 'function') {
      global.TCGMissions.syncHubBadge(res.claimable_count);
    } else if (res.missions && typeof res.missions.claimable_count === 'number' && global.TCGMissions && typeof global.TCGMissions.syncHubBadge === 'function') {
      global.TCGMissions.syncHubBadge(res.missions.claimable_count);
    }
    global.handleRankedPrReward(res);
  };

  global.handleRankedPrReward = function handleRankedPrReward(res) {
    if (!res || typeof res !== 'object' || !res.ranked_pr_reward) return;
    global.G = global.G || {};
    global.G.lastRankedPrReward = res.ranked_pr_reward;
    const reward = res.ranked_pr_reward;
    // Server only attaches ranked_pr_reward for the winning seat.
    if (reward && reward.daily && typeof global.syncRankedPrFromReward === 'function') {
      global.syncRankedPrFromReward(reward);
      if (typeof global.updateRankedPrUI === 'function') global.updateRankedPrUI();
    }
    if (reward.star_gems_earned > 0 && global.A && global.A.profile) {
      global.A.profile.star_gems = reward.star_gems ?? global.A.profile.star_gems;
      if (typeof global.syncStarGemsFromProfile === 'function') {
        global.syncStarGemsFromProfile(global.A.profile);
      }
      if (typeof global.updateStarGemsUI === 'function') global.updateStarGemsUI();
    }
    if (typeof global.queueRankedPrReward === 'function') {
      global.queueRankedPrReward(reward);
    }
  };

  global.parseAccountJson = async function parseAccountJson(r) {
    let d;
    try {
      d = await r.json();
    } catch (e) {
      throw new Error(r.ok ? 'Invalid account response' : ('Account error (' + r.status + ')'));
    }
    return d;
  };

  global.accountGet = async function accountGet(action, extra = {}) {
    const token = global.getAuthToken();
    const q = new URLSearchParams({ action, token, ...extra });
    const r = await global.fetchWithTimeout(global.ACCOUNT_API + '?' + q);
    const d = await global.parseAccountJson(r);
    if (!d.success && d.error) throw new Error(d.error);
    global.handleMissionCompletions(d);
    return d;
  };

  global.accountPost = async function accountPost(action, body = {}) {
    const token = global.getAuthToken();
    const r = await global.fetchWithTimeout(global.ACCOUNT_API + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Auth-Token': token },
      body: JSON.stringify({ ...body, token }),
    });
    const d = await global.parseAccountJson(r);
    if (!d.success && d.error) throw new Error(d.error);
    global.handleMissionCompletions(d);
    return d;
  };

  global.createApiError = function createApiError(message, status) {
    const err = new Error(message || 'Request failed');
    err.httpStatus = Number(status) || 0;
    return err;
  };

  /** Parse game API JSON; throws createApiError with httpStatus on failure. */
  global.parseGameApiResponse = async function parseGameApiResponse(r) {
    const status = r.status || 0;
    let d;
    try {
      d = await r.json();
    } catch (e) {
      const msg = status >= 500 ? 'Server error' : (status >= 400 ? `Request failed (${status})` : 'Invalid server response');
      throw global.createApiError(msg, status >= 400 ? status : 500);
    }
    if (d && d.error) {
      throw global.createApiError(d.error, status >= 400 ? status : 400);
    }
    if (!r.ok) {
      throw global.createApiError(`Request failed (${status})`, status || 500);
    }
    return d;
  };

  global.closeApiErrorPopup = function closeApiErrorPopup() {
    const ov = document.getElementById('overlay-api-error');
    if (ov) ov.classList.remove('open');
    global._apiErrorPopupOpen = false;
  };

  global.showApiErrorPopup = function showApiErrorPopup(message, opts = {}) {
    const status = Number(opts.status) || 0;
    const msg = String(message || 'Request failed').trim();
    if (!msg) return;

    const key = `${status}|${msg.slice(0, 240)}`;
    const now = Date.now();
    if (global._apiErrorPopupOpen && global._lastApiErrorPopupKey === key) return;
    if (global._lastApiErrorPopupKey === key && global._lastApiErrorPopupAt && now - global._lastApiErrorPopupAt < 8000) return;
    global._lastApiErrorPopupKey = key;
    global._lastApiErrorPopupAt = now;

    let ov = document.getElementById('overlay-api-error');
    if (!ov) return;
    const tFn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    const t = typeof tFn === 'function' ? tFn : (_k, _v) => _k;
    const titleEl = ov.querySelector('#api-error-title');
    const msgEl = ov.querySelector('#api-error-msg');
    const hintEl = ov.querySelector('#api-error-hint');
    if (titleEl) titleEl.textContent = status >= 500 ? t('apiError.titleServer') : t('apiError.titleClient');
    if (msgEl) msgEl.textContent = msg;
    if (hintEl) hintEl.textContent = status >= 500 ? t('apiError.hintServer') : t('apiError.hintClient');
    ov.classList.add('open');
    global._apiErrorPopupOpen = true;
    if (typeof global.sfxPlay === 'function') global.sfxPlay('error', { volume: 0.8 });
  };

  global.reportApiError = function reportApiError(err, opts = {}) {
    if (!err || opts.silent) return;
    const status = err.httpStatus || opts.status || 0;
    const msg = err.message || 'Request failed';
    if (global.TCG_DEBUG && typeof global.TCG_DEBUG.warn === 'function') {
      global.TCG_DEBUG.warn('api', opts.source || 'error', msg, status);
    }
    if (!opts.force && status < 400) return;
    global.showApiErrorPopup(msg, { status });
  };

  global.apiPost = async function apiPost(action, body = {}, opts = {}) {
    const payload = { ...body };
    const authToken = typeof global.getAuthToken === 'function' ? global.getAuthToken() : '';
    const headers = { 'Content-Type': 'application/json' };
    // action uses body.token as the seat token; other game API calls may use token for auth.
    if (authToken) {
      headers['X-Auth-Token'] = authToken;
      if (action === 'action') {
        // Keep seat token in body.token; pass Discord auth separately for missions.
        payload.auth_token = authToken;
      } else if (!payload.token) {
        payload.token = authToken;
      }
    }
    const r = await fetch(`${global.API}?action=${action}`, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
    try {
      const d = await global.parseGameApiResponse(r);
      global.handleMissionCompletions(d);
      return d;
    } catch (e) {
      global.reportApiError(e, { source: 'apiPost:' + action, silent: !!opts.silent });
      throw e;
    }
  };

  global.captureSyncMeta = function captureSyncMeta(res) {
    if (!res || typeof res !== 'object' || !global.G) return;
    if (res.sync_enabled && res.sync_ticket) {
      global.G.syncEnabled = true;
      global.G.syncTicket = res.sync_ticket;
    } else if (res.sync_enabled === false) {
      global.G.syncEnabled = false;
      global.G.syncTicket = null;
    }
  };

  function initApiErrorPopup() {
    const ov = document.getElementById('overlay-api-error');
    const btn = document.getElementById('btn-api-error-ok');
    if (!ov || ov.dataset.apiErrorBound) return;
    ov.dataset.apiErrorBound = '1';
    const close = () => global.closeApiErrorPopup();
    btn?.addEventListener('click', close);
    ov.addEventListener('click', (ev) => { if (ev.target === ov) close(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initApiErrorPopup);
  else initApiErrorPopup();
})(window);
