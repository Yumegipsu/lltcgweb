/**
 * Replay export/load helpers.
 */
(function (global) {
  'use strict';

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

  global.getReplayExportCredentials = function getReplayExportCredentials() {
    if (global.G?.roomId && global.G?.token) return { roomId: global.G.roomId, token: global.G.token };
    const fin = global.G?.lastFinishedExport;
    if (fin?.roomId && fin?.token) return { roomId: fin.roomId, token: fin.token };
    return null;
  };

  global.replaySaveEnabled = function replaySaveEnabled() {
    return !!global.getReplayExportCredentials() && !global.G?.isTutorial && !global.G?.replayMode;
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

  global.saveReplayFile = async function saveReplayFile() {
    if (!global.replaySaveEnabled()) {
      global.toast(t('replay.saveAfterFinish'));
      return;
    }
    const creds = global.getReplayExportCredentials();
    if (!creds) {
      global.toast(t('replay.noCredentials'));
      return;
    }
    try {
      if (typeof global.isSignedInAccount === 'function' && global.isSignedInAccount()) {
        const saved = await global.accountPost('replay_save', {
          room_id: creds.roomId,
          player_token: creds.token,
        });
        if (saved.error) throw new Error(saved.error);
        const summary = saved.replay;
        global.toast(
          summary?.id
            ? t('replay.savedToLibraryId', { id: summary.id })
            : t('replay.savedToLibrary'),
          2800
        );
        return;
      }

      const r = await global.apiPost('replay_export', {
        room_id: creds.roomId,
        token: creds.token,
      });
      if (r.error) throw new Error(r.error);
      const replay = r.replay;
      if (!replay) throw new Error('No replay payload');
      const stamp = new Date().toISOString().replace(/[:.]/g, '-');
      const room = replay.meta?.room_id || global.G.roomId || 'room';
      global.downloadJsonFile(`tcg-replay-${room}-${stamp}.json`, replay);
      global.toast(t('replay.downloadedAsJson'), 2400);
    } catch (e) {
      global.toast(e.message || t('replay.couldNotSave'), 4200);
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
      return {
        step: idx + 1,
        ts,
        delta,
        player: a?.player || '',
        type: a?.type || '',
      };
    });
  };
})(window);
