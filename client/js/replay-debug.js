/**
 * Replay export/load helpers.
 */
(function (global) {
  'use strict';

  const PENDING_AUTOSAVE_KEY = 'lltcg_pending_replay_autosaves';
  const PENDING_AUTOSAVE_MAX = 8;
  const PENDING_AUTOSAVE_TTL_MS = 48 * 60 * 60 * 1000;

  function t(key, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    return typeof fn === 'function' ? fn(key, vars) : key;
  }

  global.debugCardTestEnabled = function debugCardTestEnabled() {
    return global.TCG_DEBUG?.on || new URLSearchParams(location.search).has('debug');
  };

  global.replayLoadEnabled = function replayLoadEnabled() {
    return true;
  };

  /**
   * Prefer stashed finished-room credentials while autosave is pending / finished,
   * so rematch join cannot steal export credentials mid-save.
   */
  global.getReplayExportCredentials = function getReplayExportCredentials(opts = {}) {
    const preferFinished = !!opts.preferFinished
      || !!global.G?._replayAutosavePendingRoom
      || (global.G?.gameState?.status === 'finished');
    const fin = global.G?.lastFinishedExport;
    if (preferFinished && fin?.roomId && fin?.token) {
      if (!global.G?._replayAutosavePendingRoom
          || global.G._replayAutosavePendingRoom === fin.roomId) {
        return { roomId: fin.roomId, token: fin.token };
      }
    }
    if (global.G?._replayAutosavePendingRoom && fin?.roomId
        && fin.roomId === global.G._replayAutosavePendingRoom && fin.token) {
      return { roomId: fin.roomId, token: fin.token };
    }
    if (global.G?.roomId && global.G?.token) {
      // Rematch already swapped room — do not export the new unfinished room for autosave.
      if (preferFinished && fin?.roomId && fin.roomId !== global.G.roomId && fin.token) {
        return { roomId: fin.roomId, token: fin.token };
      }
      return { roomId: global.G.roomId, token: global.G.token };
    }
    if (fin?.roomId && fin?.token) return { roomId: fin.roomId, token: fin.token };
    return null;
  };

  /** End-of-match save (library when signed in, JSON otherwise). */
  global.replaySaveEnabled = function replaySaveEnabled() {
    return !!global.getReplayExportCredentials({ preferFinished: true })
      && !global.G?.isTutorial && !global.G?.replayMode;
  };

  /** In-game ?debug steppable export (action log + seekbar replay menu). */
  global.debugReplaySaveEnabled = function debugReplaySaveEnabled() {
    return global.debugCardTestEnabled()
      && !!global.getReplayExportCredentials()
      && !global.G?.isTutorial
      && !global.G?.replayMode;
  };

  global.downloadJsonFile = function downloadJsonFile(filename, data) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
  };

  /** Steppable debug replay JSON — import via Debug Replay menu (replay_start). */
  global.saveDebugSteppableReplay = async function saveDebugSteppableReplay() {
    if (!global.debugCardTestEnabled()) {
      global.toast('Add ?debug to the URL.');
      return;
    }
    const creds = global.getReplayExportCredentials();
    if (!creds) {
      global.toast(t('replay.noCredentials'));
      return;
    }
    try {
      const r = await global.apiPost('replay_export', {
        room_id: creds.roomId,
        token: creds.token,
        debug_mode: true,
      });
      if (r.error) throw new Error(r.error);
      const replay = r.replay;
      if (!replay) throw new Error('No replay payload');
      const stamp = new Date().toISOString().replace(/[:.]/g, '-');
      const room = replay.meta?.room_id || creds.roomId || 'room';
      global.downloadJsonFile(`tcg-replay-${room}-${stamp}.json`, replay);
      global.toast(t('replay.downloadedAsJson'), 2400);
    } catch (e) {
      global.toast(e.message || t('replay.couldNotSave'), 4200);
    }
  };

  /** Export replay JSON from the match host (Hostinger or VPS overflow). */
  global.exportReplayPayload = async function exportReplayPayload(creds) {
    const roomId = creds?.roomId || '';
    const token = creds?.token || '';
    if (!roomId || !token) {
      throw new Error(t('replay.noCredentials'));
    }
    const r = await global.apiPost('replay_export', {
      room_id: roomId,
      token,
    });
    if (r.error) throw new Error(r.error);
    if (!r.replay) throw new Error('No replay payload');
    return r.replay;
  };

  /** Slim v1 upload — baseline + actions only; Hostinger builds schema v2. */
  global.stripReplayForTransfer = function stripReplayForTransfer(replay) {
    return {
      schema_version: 1,
      meta: replay?.meta || {},
      baseline: replay?.baseline,
      actions: replay?.actions || [],
    };
  };

  function isRetryableReplaySaveError(err) {
    if (!err) return false;
    if (err.retryable) return true;
    const status = Number(err.httpStatus) || 0;
    if (status === 503) return true;
    const msg = String(err.message || '');
    return /Room not found|not finished|not ready|export not ready|host unreachable|503|Server busy|Lock timeout|No recorded actions|only be saved after the match finishes/i.test(msg);
  }

  function replaySaveLooksComplete(saved) {
    const summary = saved?.replay;
    if (!summary) return false;
    const id = Number(summary.id || 0);
    const actions = Number(summary.action_count || 0);
    return id > 0 && actions > 0;
  }

  function readPendingAutosaves() {
    try {
      const raw = global.localStorage?.getItem(PENDING_AUTOSAVE_KEY);
      if (!raw) return [];
      const list = JSON.parse(raw);
      if (!Array.isArray(list)) return [];
      const now = Date.now();
      return list.filter((e) => e?.roomId && e?.token
        && (!e.at || (now - Number(e.at)) < PENDING_AUTOSAVE_TTL_MS));
    } catch (e) {
      return [];
    }
  }

  function writePendingAutosaves(list) {
    try {
      if (!global.localStorage) return;
      const trimmed = (Array.isArray(list) ? list : []).slice(0, PENDING_AUTOSAVE_MAX);
      if (!trimmed.length) {
        global.localStorage.removeItem(PENDING_AUTOSAVE_KEY);
        return;
      }
      global.localStorage.setItem(PENDING_AUTOSAVE_KEY, JSON.stringify(trimmed));
    } catch (e) {
      // ignore quota / private mode
    }
  }

  function enqueuePendingAutosave(creds) {
    if (!creds?.roomId || !creds?.token) return;
    const roomId = String(creds.roomId).toUpperCase();
    const list = readPendingAutosaves().filter((e) => String(e.roomId).toUpperCase() !== roomId);
    list.unshift({ roomId, token: creds.token, at: Date.now() });
    writePendingAutosaves(list);
  }

  function clearPendingAutosave(roomId) {
    if (!roomId) return;
    const want = String(roomId).toUpperCase();
    writePendingAutosaves(readPendingAutosaves().filter(
      (e) => String(e.roomId).toUpperCase() !== want
    ));
  }

  async function postReplaySave(creds, opts, allowSlimFallback, prefetchedSlim) {
    const body = {
      room_id: creds.roomId,
      player_token: creds.token,
      ...opts,
    };
    // Prefer a prefetched slim payload — Hostinger stores v1 without re-sim (#186).
    if (prefetchedSlim && typeof prefetchedSlim === 'object') {
      body.replay = prefetchedSlim;
    }
    try {
      const saved = await global.accountPost('replay_save', body);
      if (saved.error) throw new Error(saved.error);
      return { saved, replay: body.replay || null };
    } catch (serverErr) {
      if (!allowSlimFallback || !isRetryableReplaySaveError(serverErr)) {
        throw serverErr;
      }
      const replay = await global.exportReplayPayload(creds);
      body.replay = global.stripReplayForTransfer(replay);
      const saved = await global.accountPost('replay_save', body);
      if (saved.error) throw new Error(saved.error);
      return { saved, replay: body.replay };
    }
  }

  async function postReplaySaveWithRetry(creds, opts, delaysMs) {
    let lastErr = null;
    let slim = null;
    // Export slim once up front while the match room is still on Redis.
    try {
      slim = global.stripReplayForTransfer(await global.exportReplayPayload(creds));
    } catch (e) {
      // Fall through to server-side overflow fetch on Hostinger.
      lastErr = e;
      // Re-attempt export on later retries when first draw races finish.
      if (isRetryableReplaySaveError(e)) {
        slim = null;
      }
    }
    for (let i = 0; i < delaysMs.length; i++) {
      if (i > 0 && delaysMs[i] > 0) {
        await new Promise((resolve) => setTimeout(resolve, delaysMs[i]));
      }
      if (!slim && i > 0) {
        try {
          slim = global.stripReplayForTransfer(await global.exportReplayPayload(creds));
        } catch (e) {
          lastErr = e;
        }
      }
      try {
        return await postReplaySave(creds, opts, false, slim);
      } catch (e) {
        lastErr = e;
        if (!isRetryableReplaySaveError(e)) {
          throw e;
        }
      }
    }
    if (lastErr && !isRetryableReplaySaveError(lastErr)) {
      throw lastErr;
    }
    return postReplaySave(creds, opts, true, slim);
  }

  function stashFinishedReplayCredentials(creds) {
    if (!creds?.roomId || !creds?.token || !global.G) return;
    G.lastFinishedExport = {
      roomId: creds.roomId,
      token: creds.token,
      at: Date.now(),
    };
  }

  /** End-of-match replay — account library (realtime) or JSON download. */
  global.saveReplayFile = async function saveReplayFile() {
    if (!global.replaySaveEnabled()) {
      global.toast(t('replay.saveAfterFinish'));
      return;
    }
    const creds = global.getReplayExportCredentials({ preferFinished: true });
    if (!creds) {
      global.toast(t('replay.noCredentials'));
      return;
    }
    stashFinishedReplayCredentials(creds);
    try {
      if (typeof global.isSignedInAccount === 'function' && global.isSignedInAccount()) {
        const { saved } = await postReplaySaveWithRetry(creds, {
          preserve: true,
          kind: 'library',
        }, [0, 1500, 4000, 8000]);
        const summary = saved.replay;
        if (replaySaveLooksComplete(saved) && global.G) {
          G._replayAutosavedRoom = creds.roomId;
          clearPendingAutosave(creds.roomId);
        }
        global.toast(
          summary?.id
            ? t('replay.savedToLibraryId', { id: summary.id })
            : t('replay.savedToLibrary'),
          2800
        );
        return;
      }

      const replay = await global.exportReplayPayload(creds);
      const stamp = new Date().toISOString().replace(/[:.]/g, '-');
      const room = replay.meta?.room_id || global.G.roomId || 'room';
      global.downloadJsonFile(`tcg-replay-${room}-${stamp}.json`, replay);
      global.toast(t('replay.downloadedAsJson'), 2400);
    } catch (e) {
      global.toast(e.message || t('replay.couldNotSave'), 4200);
    }
  };

  /**
   * Silent FIFO autosave (last 10) for signed-in players when a match finishes.
   * Manual Save Replay / Preserve upgrades the same room to permanent.
   * Concurrent callers (onState + showWin) share one in-flight promise so the
   * second call does not drop a retry after the first attempt fails (#186).
   * A delayed refresh pass can overwrite a shorter first export (#187).
   * Pending rooms survive rematch / reload via lastFinishedExport + localStorage.
   */
  global.autosaveFinishedReplay = async function autosaveFinishedReplay(opts = {}) {
    if (global.G?.isSpectator || global.G?.isTutorial || global.G?.replayMode) return null;
    if (typeof global.isSignedInAccount !== 'function' || !global.isSignedInAccount()) return null;
    if (!global.replaySaveEnabled() && !opts.creds) return null;
    const creds = opts.creds || global.getReplayExportCredentials({ preferFinished: true });
    if (!creds) return null;
    stashFinishedReplayCredentials(creds);
    if (!opts._refreshPass) {
      enqueuePendingAutosave(creds);
    }
    if (!opts._refreshPass
        && global.G?._replayAutosavedRoom
        && global.G._replayAutosavedRoom === creds.roomId) {
      clearPendingAutosave(creds.roomId);
      return null;
    }
    if (!opts._refreshPass
        && global.G?._replayAutosavePromise
        && global.G._replayAutosavePromiseRoom === creds.roomId) {
      return global.G._replayAutosavePromise;
    }
    if (global.G) {
      G._replayAutosavePendingRoom = creds.roomId;
      G._replayAutosavePromiseRoom = creds.roomId;
    }
    const delays = opts.delaysMs || [0, 1200, 3500, 8000, 15000];
    const run = (async () => {
      try {
        const { saved } = await postReplaySaveWithRetry(creds, {
          autosave: true,
          kind: 'autosave',
        }, delays);
        if (saved.error) throw new Error(saved.error);
        if (!replaySaveLooksComplete(saved)) {
          throw new Error('Replay save incomplete');
        }
        if (global.G) G._replayAutosavedRoom = creds.roomId;
        clearPendingAutosave(creds.roomId);
        if (opts.toast !== false) {
          global.toast(t('replay.autosavedRecent'), 2400);
        }
        // One silent refresh so a racey short first export can be replaced (#187).
        if (!opts._refreshPass && !opts._bgRetry && global.G) {
          setTimeout(() => {
            void global.autosaveFinishedReplay({
              toast: false,
              toastError: false,
              _refreshPass: true,
              _bgRetry: true,
              creds,
              delaysMs: [0, 2000],
            });
          }, 2800);
        }
        return saved.replay || null;
      } catch (e) {
        // Non-fatal — match UI should not block on library write failures.
        const showFailToast = opts.toastError === true
          || (opts.toastError !== false && !!opts._bgRetry && !opts._refreshPass);
        if (showFailToast) {
          global.toast(e.message || t('replay.couldNotSave'), 3200);
        }
        // Delayed background retry after poll has stopped (showWin path).
        if (!opts._bgRetry && global.G) {
          const roomId = creds.roomId;
          setTimeout(() => {
            if (!global.G || global.G._replayAutosavedRoom === roomId) return;
            void global.autosaveFinishedReplay({
              toast: false,
              toastError: true,
              _bgRetry: true,
              creds,
              delaysMs: [0, 2000, 6000, 12000],
            });
          }, 5000);
        }
        return null;
      } finally {
        if (global.G?._replayAutosavePendingRoom === creds.roomId) {
          G._replayAutosavePendingRoom = null;
        }
        if (global.G?._replayAutosavePromiseRoom === creds.roomId) {
          G._replayAutosavePromise = null;
          G._replayAutosavePromiseRoom = null;
        }
      }
    })();
    if (global.G && !opts._refreshPass) G._replayAutosavePromise = run;
    return run;
  };

  /** Flush localStorage pending autosaves after auth / hub return. */
  global.flushPendingReplayAutosaves = async function flushPendingReplayAutosaves() {
    if (typeof global.isSignedInAccount !== 'function' || !global.isSignedInAccount()) return;
    if (global.G?.isSpectator || global.G?.isTutorial || global.G?.replayMode) return;
    const pending = readPendingAutosaves();
    if (!pending.length) return;
    for (const entry of pending) {
      const roomId = String(entry.roomId || '').toUpperCase();
      if (!roomId || !entry.token) continue;
      if (global.G?._replayAutosavedRoom === roomId) {
        clearPendingAutosave(roomId);
        continue;
      }
      if (global.G) {
        G.lastFinishedExport = {
          roomId,
          token: entry.token,
          at: Number(entry.at) || Date.now(),
        };
      }
      await global.autosaveFinishedReplay({
        toast: false,
        toastError: false,
        _bgRetry: true,
        creds: { roomId, token: entry.token },
        delaysMs: [0, 1500, 4000, 10000],
      });
    }
  };

  global.preserveSavedReplay = async function preserveSavedReplay(replayId) {
    const id = Number(replayId || 0);
    if (!id) return null;
    if (typeof global.isSignedInAccount !== 'function' || !global.isSignedInAccount()) {
      global.toast(t('replay.signInLibrary'));
      return null;
    }
    try {
      const res = await global.accountPost('replay_preserve', { replay_id: id });
      if (res.error) throw new Error(res.error);
      global.toast(t('replay.preservedToast'), 2600);
      return res.replay || null;
    } catch (e) {
      global.toast(e.message || t('replay.couldNotSave'), 4200);
      return null;
    }
  };

  global.syncDebugReplayButtons = function syncDebugReplayButtons(forceShow) {
    const replayBtn = document.getElementById('btn-auth-debug-replay');
    if (replayBtn) replayBtn.hidden = false;
  };

  global.replayTimingFromActions = function replayTimingFromActions(actions) {
    if (!Array.isArray(actions)) return [];
    return actions.map((a, idx) => {
      const ts = Number(a?.ts || 0);
      const prevTs = idx > 0 ? Number(actions[idx - 1]?.ts || 0) : 0;
      const delta = ts > 0 && prevTs > 0 ? Math.max(0, ts - prevTs) : 0;
      const data = a?.data && typeof a.data === 'object' ? a.data : {};
      return {
        step: idx + 1,
        ts,
        delta,
        player: a?.player || '',
        type: a?.type || '',
        turn: Number(a?.turn || 0),
        card_name: String(a?.card_name || data.card_name || ''),
        slot: String(a?.slot || data.slot || ''),
      };
    });
  };

  try {
    global.addEventListener('tcg:auth-ready', () => {
      void global.flushPendingReplayAutosaves();
    });
  } catch (e) {
    // ignore
  }
})(window);
