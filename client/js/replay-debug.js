/**
 * Debug replay export/load helpers (?debug mode).
 */
(function (global) {
  'use strict';

  global.debugCardTestEnabled = function debugCardTestEnabled() {
    return global.TCG_DEBUG?.on || new URLSearchParams(location.search).has('debug');
  };

  global.replayLoadEnabled = function replayLoadEnabled() {
    return global.debugCardTestEnabled() && !global.isSignedInAccount();
  };

  global.getReplayExportCredentials = function getReplayExportCredentials() {
    if (global.G?.roomId && global.G?.token) return { roomId: global.G.roomId, token: global.G.token };
    const fin = global.G?.lastFinishedExport;
    if (fin?.roomId && fin?.token) return { roomId: fin.roomId, token: fin.token };
    return null;
  };

  global.replaySaveEnabled = function replaySaveEnabled() {
    return global.debugCardTestEnabled() && !!global.getReplayExportCredentials() && !global.G?.isTutorial && !global.G?.replayMode;
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
      global.toast('Save replay needs ?debug and an active match.');
      return;
    }
    const creds = global.getReplayExportCredentials();
    if (!creds) {
      global.toast('Save replay needs ?debug and an active match.');
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
      const room = replay.meta?.room_id || global.G.roomId || 'room';
      global.downloadJsonFile(`tcg-replay-${room}-${stamp}.json`, replay);
      global.toast('Replay saved 💾', 2400);
    } catch (e) {
      global.toast(e.message || 'Could not save replay', 4200);
    }
  };

  global.syncDebugReplayButtons = function syncDebugReplayButtons(forceShow) {
    const show = forceShow === true || global.TCG_DEBUG?.on === true;
    const replayBtn = document.getElementById('btn-auth-debug-replay');
    if (replayBtn) replayBtn.hidden = !(show && !global.isSignedInAccount());
  };
})(window);
