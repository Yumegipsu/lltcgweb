/**
 * Prompt submit state + overlay suppression during resolution.
 */
(function (global) {
  'use strict';

  global.promptSubmitKey = function promptSubmitKey(s) {
    const pr = s?.pending_prompt;
    if (!pr || !s) return null;
    return `${s.seq}:${pr.type}:${pr.step ?? ''}:${pr.responder ?? ''}`;
  };

  global.markPromptSubmitting = function markPromptSubmitting(s) {
    global.G._promptSubmitKey = global.promptSubmitKey(s || global.G.gameState);
  };

  global.syncPromptSubmitState = function syncPromptSubmitState(s) {
    const pr = s?.pending_prompt;
    if (!pr) {
      global.G._promptSubmitKey = null;
      if (global.G._deferredPromptState?.pending_prompt) global.clearDeferredPromptState();
      return;
    }
    if (!global.G._promptSubmitKey) return;
    if (global.promptSubmitKey(s) !== global.G._promptSubmitKey) global.G._promptSubmitKey = null;
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
})(window);
