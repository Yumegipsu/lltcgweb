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
    return global.parseAccountJson(r);
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
    return d;
  };

  global.apiPost = async function apiPost(action, body) {
    const r = await fetch(`${global.API}?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const d = await r.json();
    if (d.error) throw new Error(d.error);
    return d;
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
})(window);
