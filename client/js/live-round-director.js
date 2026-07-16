/**
 * LiveRoundDirector — single owner for live-round presentation.
 *
 * Owns a presentationToken + step so async reveal / spectacle / splash / SFX
 * can cancel cleanly when a newer poll or abort supersedes the run.
 * presentLiveRound remains the step runner; this module is the lock + policy layer.
 */
(function (global) {
  'use strict';

  const STEPS = Object.freeze({
    idle: 'idle',
    reveal: 'reveal',
    live_start: 'live_start',
    performance_splash: 'performance_splash',
    spectacle: 'spectacle',
    mid_yell_prompt: 'mid_yell_prompt',
    outcomes: 'outcomes',
    judge: 'judge',
    post_live: 'post_live',
    wr_flights: 'wr_flights',
    banners: 'banners',
  });

  const director = {
    token: 0,
    step: STEPS.idle,
    active: false,
    bannersReleased: true,
    pauseReason: null,
    runId: 0,

    isActive() {
      return !!this.active || !!G._liveRoundPlaybackActive || !!G._liveSpectacleGateRunning;
    },

    isStep(name) {
      return this.active && this.step === name;
    },

    /** True while Main / Live Set / turn-begin splashes must wait for the director. */
    blocksPhaseBanners() {
      if (!this.active && !G._liveRoundPlaybackActive) return false;
      return !this.bannersReleased;
    },

    /** True while phase SFX from renderGame must stay silent. */
    blocksPhaseSfx() {
      return this.isActive() || !!G._perfSpectacleActive || !!G._livePollHold;
    },

    /** Live-storage 3D flips only during the reveal step (or legacy reveal-running flag). */
    allowsLiveStorageFlips() {
      if (G._liveStorageRevealRunning) return true;
      if ((G._liveStorageRevealAnimCount || 0) > 0) return true;
      return this.isStep(STEPS.reveal);
    },

    bumpToken(reason) {
      this.token = (this.token || 0) + 1;
      if (typeof TCG_DEBUG !== 'undefined') {
        TCG_DEBUG.log('live', 'director token bump', { token: this.token, reason, step: this.step });
      }
      return this.token;
    },

    begin(reason) {
      const token = this.bumpToken(reason || 'begin');
      this.active = true;
      this.bannersReleased = false;
      this.pauseReason = null;
      this.step = STEPS.idle;
      this.runId = (this.runId || 0) + 1;
      G._liveRoundDirectorToken = token;
      G._liveRoundDirectorStep = this.step;
      return token;
    },

    setStep(name) {
      this.step = name || STEPS.idle;
      G._liveRoundDirectorStep = this.step;
    },

    check(token) {
      return token === this.token && this.active && !G._presentationAborted;
    },

    async awaitIf(token, ms) {
      if (!this.check(token)) return false;
      if (ms > 0) await new Promise((r) => setTimeout(r, ms));
      return this.check(token);
    },

    pause(reason) {
      this.pauseReason = reason || 'prompt';
      if (reason === 'mid_yell_prompt') this.setStep(STEPS.mid_yell_prompt);
      else if (reason === 'live_start') this.setStep(STEPS.live_start);
      else if (reason === 'post_live') this.setStep(STEPS.post_live);
    },

    resume(reason) {
      this.pauseReason = null;
      if (typeof TCG_DEBUG !== 'undefined') {
        TCG_DEBUG.log('live', 'director resume', { reason, step: this.step, token: this.token });
      }
    },

    /** Allow Main/Live/turn banners after WR flights / empty-skip complete. */
    releaseBanners() {
      this.bannersReleased = true;
      this.setStep(STEPS.banners);
    },

    end(reason) {
      if (typeof TCG_DEBUG !== 'undefined') {
        TCG_DEBUG.log('live', 'director end', { reason, token: this.token, step: this.step });
      }
      this.active = false;
      this.pauseReason = null;
      this.step = STEPS.idle;
      this.bannersReleased = true;
      G._liveRoundDirectorStep = this.step;
    },

    /**
     * Hard cancel — bumps token so in-flight awaits bail.
     * Does not clear recovery pending (caller decides).
     */
    abort(reason) {
      this.bumpToken(reason || 'abort');
      this.active = false;
      this.pauseReason = null;
      this.step = STEPS.idle;
      this.bannersReleased = true;
      G._liveRoundDirectorStep = this.step;
      if (typeof TCG_DEBUG !== 'undefined') {
        TCG_DEBUG.warn('live', 'director abort', { reason, token: this.token });
      }
    },
  };

  global.LiveRoundDirector = director;
  global.LIVE_ROUND_DIRECTOR_STEPS = STEPS;
})(typeof window !== 'undefined' ? window : globalThis);
