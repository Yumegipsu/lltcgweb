/**
 * Runtime feature flags — load BEFORE api-client.js.
 * Cutover: set DEFAULT_MATCH_API_PRIMARY to true and redeploy this file only.
 *
 * Override order (first win):
 *  1. ?match_primary=0|1 query param
 *  2. localStorage.tcg_match_api_primary = '0'|'1'
 *  3. Committed default below
 *
 * Tournaments:
 *  Client default on (public). Override: ?tournaments=0|1 or localStorage.tcg_tournaments_enabled
 *  Server may still gate via tournament_enabled / me after login.
 */
(function (global) {
  'use strict';

  /** Production: VPS match-primary cutover (operator-approved). */
  var DEFAULT_MATCH_API_PRIMARY = true;
  var DEFAULT_TOURNAMENTS_ENABLED = true;
  /**
   * Card thumbs as static cardimg/thumbs/<w>/<name>.webp (no PHP). Keep false until every file
   * under cardimg/thumbs/ on Hostinger is world-readable (older thumbs were written 0600 and
   * the web server answers 403). Override: ?static_thumbs=0|1 or localStorage.tcg_static_thumbs.
   */
  var DEFAULT_STATIC_CARD_THUMBS = false;

  function parseBool(raw, fallback) {
    if (raw == null || raw === '') return fallback;
    var s = String(raw).toLowerCase();
    if (s === '1' || s === 'true' || s === 'yes' || s === 'on') return true;
    if (s === '0' || s === 'false' || s === 'no' || s === 'off') return false;
    return fallback;
  }

  var q = null;
  try {
    q = new URLSearchParams(global.location && global.location.search ? global.location.search : '');
  } catch (e) { q = null; }

  var fromQuery = null;
  try {
    if (q && q.has('match_primary')) {
      fromQuery = parseBool(q.get('match_primary'), null);
    }
  } catch (e) { /* ignore */ }

  var fromStorage = null;
  try {
    if (global.localStorage) {
      var ls = global.localStorage.getItem('tcg_match_api_primary');
      if (ls != null && ls !== '') fromStorage = parseBool(ls, null);
    }
  } catch (e) { /* ignore */ }

  if (fromQuery !== null) {
    global.TCG_MATCH_API_PRIMARY = fromQuery;
  } else if (fromStorage !== null) {
    global.TCG_MATCH_API_PRIMARY = fromStorage;
  } else if (typeof global.TCG_MATCH_API_PRIMARY === 'undefined') {
    global.TCG_MATCH_API_PRIMARY = DEFAULT_MATCH_API_PRIMARY;
  }

  var sFromQuery = null;
  try {
    if (q && q.has('static_thumbs')) sFromQuery = parseBool(q.get('static_thumbs'), null);
  } catch (e) { /* ignore */ }
  var sFromStorage = null;
  try {
    if (global.localStorage) {
      var sls = global.localStorage.getItem('tcg_static_thumbs');
      if (sls != null && sls !== '') sFromStorage = parseBool(sls, null);
    }
  } catch (e) { /* ignore */ }
  if (sFromQuery !== null) global.TCG_STATIC_CARD_THUMBS = sFromQuery;
  else if (sFromStorage !== null) global.TCG_STATIC_CARD_THUMBS = sFromStorage;
  else if (typeof global.TCG_STATIC_CARD_THUMBS === 'undefined') global.TCG_STATIC_CARD_THUMBS = DEFAULT_STATIC_CARD_THUMBS;

  var tFromQuery = null;
  try {
    if (q && q.has('tournaments')) {
      tFromQuery = parseBool(q.get('tournaments'), null);
    }
  } catch (e) { /* ignore */ }

  var tFromStorage = null;
  try {
    if (global.localStorage) {
      var tls = global.localStorage.getItem('tcg_tournaments_enabled');
      if (tls != null && tls !== '') tFromStorage = parseBool(tls, null);
    }
  } catch (e) { /* ignore */ }

  if (tFromQuery !== null) {
    global.TCG_TOURNAMENTS_ENABLED = tFromQuery;
  } else if (tFromStorage !== null) {
    global.TCG_TOURNAMENTS_ENABLED = tFromStorage;
  } else if (typeof global.TCG_TOURNAMENTS_ENABLED === 'undefined') {
    global.TCG_TOURNAMENTS_ENABLED = DEFAULT_TOURNAMENTS_ENABLED;
  }
})(typeof window !== 'undefined' ? window : globalThis);
