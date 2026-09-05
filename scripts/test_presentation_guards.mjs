#!/usr/bin/env node
/**
 * Regression contracts for Live Win/Loss Check / heart-check presentation heals.
 * node scripts/test_presentation_guards.mjs
 */
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const g = require(path.join(root, 'client/js/presentation-guards.js'));
const syncSrc = fs.readFileSync(path.join(root, 'client/js/game-sync.js'), 'utf8');
const indexSrc = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const applySrc = fs.readFileSync(path.join(root, 'client/js/state-apply.js'), 'utf8');
const spectacleSrc = fs.readFileSync(path.join(root, 'client/js/spectacle.js'), 'utf8');
const cardimgCacheSrc = fs.readFileSync(path.join(root, 'cardimg_cache.php'), 'utf8');

let failed = 0;
function check(name, cond) {
  if (cond) {
    console.log(`OK: ${name}`);
  } else {
    console.error(`FAIL: ${name}`);
    failed += 1;
  }
}

const mainPrev = { seq: 10, phase: 'main_first', active_player: 'p1', players: { p1: { hand: [{}], stage: {}, energy_zone: [] } } };
const mainNext = { seq: 11, phase: 'main_first', active_player: 'p1', players: { p1: { hand: [], stage: { left: { instance_id: 'a' } }, energy_zone: [] } } };

check('play onto empty slot is Main catch-up', g.isMainBoardCatchupSnapshot(mainPrev, mainNext));
check('End Main first→second is turn-advance', g.isTurnAdvanceSnapshot(
  { seq: 4, phase: 'main_first', active_player: 'p1' },
  { seq: 5, phase: 'main_second', active_player: 'p2' },
));

const liveJudge = {
  seq: 20,
  phase: 'live_judge',
  live_show: { stage: 'judge', stage_seq: 4 },
};
const afterJudgeMain = { seq: 21, phase: 'main_first', active_player: 'p1' };

check('live_judge is a Win/Loss pipeline phase', g.isLiveWinLossPipelinePhase('live_judge'));
check('live_show judge is in flight', g.liveShowInFlight(liveJudge));

check('allow End Main despite leftover Checking hearts (no live_show)', g.mayForceApplyHeldSnapshot(
  { seq: 4, phase: 'main_first', active_player: 'p1' },
  { seq: 5, phase: 'main_second', active_player: 'p2' },
  { heartCheckHold: true },
));
check('allow End Main despite leftover spectacle flags on settled Main', g.mayForceApplyHeldSnapshot(
  { seq: 4, phase: 'main_first', active_player: 'p1' },
  { seq: 5, phase: 'main_second', active_player: 'p2' },
  { perfSpectacle: true, heartCheckHold: true },
));
check('never force-apply Main catch-up through leftover Checking hearts', !g.mayForceApplyHeldSnapshot(mainPrev, mainNext, { heartCheckHold: true }));
check('never force-apply Main catch-up through leftover empty-LIVE playback', !g.mayForceApplyHeldSnapshot(mainPrev, mainNext, { liveRoundPlayback: true }));
check('never force-apply while live_show runner owns Win/Loss', !g.mayForceApplyHeldSnapshot(mainPrev, mainNext, { liveShowRunner: true }));
check('never force-apply incoming live_judge', !g.mayForceApplyHeldSnapshot(
  { seq: 8, phase: 'live_set', active_player: 'p1' },
  { seq: 9, phase: 'live_judge' },
  {},
));
check('never force-apply live_set → live_performance', !g.mayForceApplyHeldSnapshot(
  { seq: 8, phase: 'live_set', active_player: 'p1' },
  { seq: 9, phase: 'live_performance_first' },
  {},
));
check('never force-apply Main catch-up over live_show performance', !g.mayForceApplyHeldSnapshot(
  { ...mainPrev, live_show: { stage: 'performance' } },
  mainNext,
  {},
));
check('never force-apply while prev is live_judge with leftover chrome', !g.mayForceApplyHeldSnapshot(liveJudge, afterJudgeMain, { perfSpectacle: true }));
check('allow live_set → Main despite leftover empty-LIVE playback chrome', g.mayForceApplyHeldSnapshot(
  { seq: 6, phase: 'live_set', active_player: 'p1' },
  { seq: 9, phase: 'main_first', active_player: 'p1' },
  { heartCheckHold: true, perfSpectacle: false },
));

check('allow Main catch-up with only leftover G.animating', g.mayForceApplyHeldSnapshot(mainPrev, mainNext, { animating: true }));

check('do not unstick Main during live_judge', !g.mayUnstickStuckMainPresentation(liveJudge, { animating: true, zeroFlightMs: 9999 }, 0));
check('do not unstick Main during Checking hearts', !g.mayUnstickStuckMainPresentation(
  { phase: 'main_first' },
  { heartCheckHold: true, animating: true, zeroFlightMs: 9999 },
  0,
));
check('do not unstick Main while live_show in flight', !g.mayUnstickStuckMainPresentation(
  { phase: 'main_first', live_show: { stage: 'performance' } },
  { animating: true, zeroFlightMs: 9999 },
  0,
));
check('do not unstick Main during fresh baton / log-sync gap', !g.mayUnstickStuckMainPresentation(
  { phase: 'main_first' },
  { animating: true, zeroFlightMs: 100 },
  0,
));
check('unstick Main after hysteresis with no flights', g.mayUnstickStuckMainPresentation(
  { phase: 'main_first' },
  { animating: true, zeroFlightMs: 2000 },
  0,
));

check('may clear Checking hearts when live_show already cleared (3rd Success path)', g.mayClearStuckPerfSpectacle(
  { phase: 'live_performance_first', status: 'playing' },
  { perfSpectacle: true, heartCheckHold: true },
));
check('do not clear Checking hearts while live_show still in flight', !g.mayClearStuckPerfSpectacle(
  { phase: 'live_performance_first', live_show: { stage: 'performance' } },
  { perfSpectacle: true, heartCheckHold: true },
));
check('faster unstick when hide-iids stuck after baton flights', g.mayUnstickStuckMainPresentation(
  { phase: 'main_first' },
  { animating: true, hideIidsStuck: true, zeroFlightMs: 800 },
  0,
));
check('do not fast-unstick hide-iids before short latch', !g.mayUnstickStuckMainPresentation(
  { phase: 'main_first' },
  { animating: true, hideIidsStuck: true, zeroFlightMs: 200 },
  0,
));

check('may clear spectacle on finished match-end live_judge', g.mayClearStuckPerfSpectacle(
  { status: 'finished', phase: 'live_judge' },
  { perfSpectacle: true },
));

check('do not skip yells mid-performance after brief tab hide',
  !g.shouldForceSkipLiveYellsOnTabRestore('performance', 2800, { live_show: { stage: 'performance' } }));
check('skip yells once stage is outcomes',
  g.shouldForceSkipLiveYellsOnTabRestore('outcomes', 100, { live_show: { stage: 'outcomes' } }));
check('skip yells mid-performance when hearts already resolved',
  g.shouldForceSkipLiveYellsOnTabRestore('performance', 500, {
    live_show: { stage: 'performance' },
    _perf_hearts_resolved: { p1: true },
  }));
check('skip yells mid-performance only after very long hide',
  g.shouldForceSkipLiveYellsOnTabRestore('performance', 30000, { live_show: { stage: 'performance' } }));
check('do not seal performance on reveal/live_start tab hide',
  !g.shouldForceSkipLiveYellsOnTabRestore('reveal', 5000, { live_show: { stage: 'reveal' } })
  && !g.shouldForceSkipLiveYellsOnTabRestore('live_start', 5000, { live_show: { stage: 'live_start' } })
  && !g.shouldSealLivePerformanceOnTabRestore('reveal', 5000, { live_show: { stage: 'reveal' } }));
check('seal performance only past performance stage on tab restore',
  g.shouldSealLivePerformanceOnTabRestore('judge', 100, { live_show: { stage: 'judge' } })
  && !g.shouldSealLivePerformanceOnTabRestore('performance', 500, { live_show: { stage: 'performance' } }));
check('game-sync uses shouldForceSkipLiveYellsOnTabRestore',
  /shouldForceSkipLiveYellsOnTabRestore/.test(syncSrc)
  && /LLTCG_PRESENTATION_GUARDS/.test(syncSrc)
  && !/TCGPresentationGuards/.test(syncSrc));
check('pending queue preserves live_set snapshot when coalescing',
  /liveSetSnap/.test(applySrc) && /Never drop a live_set snapshot/.test(applySrc));
check('Bo3 spectator follow helper exists',
  /maybeFollowTournamentBo3NextSpectate/.test(indexSrc)
  && /bo3NextWait/.test(indexSrc));
check('WLR Success pick not deferred after hearts resolved',
  /pick_judge_success_live[\s\S]{0,400}liveShowHeartsResolvedFromBoard/.test(spectacleSrc));
check('performance seal without yell climb replays',
  /performance seal without yell climb/.test(spectacleSrc));
check('may clear spectacle on promptless post-cursor live_judge', g.mayClearStuckPerfSpectacle(
  { phase: 'live_judge' },
  { perfSpectacle: true },
));
check('do not close spectacle during in-flight live_show judge', !g.mayClearStuckPerfSpectacle(
  { phase: 'live_judge', live_show: { stage: 'judge' } },
  { perfSpectacle: true },
));
check('do not close spectacle during performance live_show', !g.mayClearStuckPerfSpectacle(
  { phase: 'live_performance_first', live_show: { stage: 'performance' } },
  { perfSpectacle: true },
));
check('may close leftover spectacle on settled Main', g.mayClearStuckPerfSpectacle(
  { phase: 'main_first' },
  { perfSpectacle: true },
));
check('may close spectacle for Success-Live pick after show', g.mayClearStuckPerfSpectacle(
  { phase: 'live_judge', pending_prompt: { type: 'pick_judge_success_live' } },
  { perfSpectacle: true, postSpectacleReady: true },
));
check('do not close spectacle during Live Success surveil (START:DASH)', !g.mayClearStuckPerfSpectacle(
  { phase: 'live_judge', pending_prompt: { type: 'surveil_arrange' } },
  { perfSpectacle: true, postSpectacleReady: true },
));
check('finished match unblocks polls', g.mayUnblockPollsForFinishedMatch(
  { status: 'finished', phase: 'live_judge' },
));
check('promptless mid-match live_judge does not unblock polls', !g.mayUnblockPollsForFinishedMatch(
  { phase: 'live_judge' },
));
check('surveil pending does not unblock as finished', !g.mayUnblockPollsForFinishedMatch(
  { phase: 'live_judge', pending_prompt: { type: 'surveil_arrange' } },
));
check('in-flight judge does not unblock as finished', !g.mayUnblockPollsForFinishedMatch(
  { phase: 'live_judge', live_show: { stage: 'judge' } },
));

check('resume runner when live_show judge and runner died', g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'judge' } },
  {},
));
check('resume runner on performance heart-check beat', g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'performance' } },
  {},
));
check('do not resume runner when already running', !g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'judge' } },
  { liveShowRunner: true },
));
check('do not resume runner over an open skill prompt', !g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'performance' }, pending_prompt: { type: 'yes_no' } },
  {},
));
check('spectators do not ack via runner resume', !g.shouldResumeLiveShowRunner(
  { live_show: { stage: 'judge' } },
  { isSpectator: true },
));

check('index.html loads presentation-guards before game-sync',
  /presentation-guards\.js\?v=\d+[\s\S]*game-sync\.js/.test(indexSrc));
check('game-sync no longer closes spectacle on promptless live_judge',
  !/judgeWaitNoLocalPrompt/.test(syncSrc));
check('state-apply uses mayForceApplyHeldSnapshot',
  /mayForceApplyHeldSnapshot/.test(applySrc));
check('game-sync resumes dead live_show runner',
  /shouldResumeLiveShowRunner/.test(syncSrc));

check('force-apply dismisses local prompt chrome',
  /dismissLocalPromptChrome\(['"]turn-advance['"]\)/.test(applySrc)
  || /dismissLocalPromptChrome\('turn-advance'\)/.test(applySrc));
check('spectacle defines dismissLocalPromptChrome',
  /function dismissLocalPromptChrome\(/.test(spectacleSrc));
check('perfCloseSpectacle bumps heart fly epoch when closing active spectacle',
  /function perfCloseSpectacle\([\s\S]*?wasActive[\s\S]*?_perfHeartFlyEpoch/.test(spectacleSrc));
check('animated perfSeekPhase bumps heart fly epoch before climb',
  /New animated climb[\s\S]*?_perfHeartFlyEpoch/.test(spectacleSrc));
check('abortGameplayPresentation invalidates heart fly epoch',
  /function abortGameplayPresentation\([\s\S]*?bumpLiveShowRunnerEpoch/.test(indexSrc)
  || /function abortGameplayPresentation\([\s\S]*?_perfHeartFlyEpoch/.test(indexSrc));
check('spectacle gates deferred resurface with maySurfaceDeferredPromptState',
  /function maySurfaceDeferredPromptState\(/.test(spectacleSrc));
check('state-apply softlock uses maySurfaceDeferredPromptState',
  /maySurfaceDeferredPromptState/.test(applySrc));
check('live_start/success not in needsResurface after resolve',
  !/needsResurface = \(pr\.type === 'pick_judge_success_live'[\s\S]*s\.phase === 'live_start_effects'/.test(spectacleSrc));
check('resolved prompt identity never resurfaces',
  /_lastResolvedPromptKey === surfKey[\s\S]{0,200}return;/.test(spectacleSrc)
  || /_lastResolvedPromptKey === logicalKey/.test(spectacleSrc));
check('promptLogicalKey is seq-free (turn-scoped)',
  (() => {
    const src = fs.readFileSync(path.join(root, 'client/js/prompt-renderer.js'), 'utf8');
    return /promptLogicalKey\s*=\s*function/.test(src)
      && /\$\{turn\}:\$\{pr\.type\}/.test(src)
      && !/promptLogicalKey[\s\S]{0,400}s\.seq/.test(src);
  })());
check('renderPrompt respects isPromptAlreadyResolved',
  /isPromptAlreadyResolved/.test(
    fs.readFileSync(path.join(root, 'client/js/prompt-renderer.js'), 'utf8'),
  ));
const boardRenderSrc = fs.readFileSync(path.join(root, 'client/js/board-render.js'), 'utf8');
check('skillPromptUiState scrubs already-resolved prompts (#146)',
  /function scrubAlreadyResolvedPromptState/.test(boardRenderSrc)
  && /scrubAlreadyResolvedPromptState\(best\)/.test(boardRenderSrc)
  && /scrubAlreadyResolvedPromptState\(def\)/.test(boardRenderSrc));
check('hasOpenSkillPrompt ignores already-resolved (#146)',
  /function hasOpenSkillPrompt[\s\S]{0,500}isPromptAlreadyResolved/.test(boardRenderSrc));
check('End Main uses hasOpenSkillPrompt not raw pending (#146)',
  /hasOpenSkillPrompt\(s\)/.test(indexSrc)
  && /Resolve skill first/.test(indexSrc));
check('Success Live binds spectator inspect (#149)',
  /function renderSuccessLives[\s\S]{0,900}bindSpectatorCardInspect/.test(boardRenderSrc)
  && /G\.isSpectator[\s\S]{0,200}bindSpectatorCardInspect/.test(
    boardRenderSrc.match(/function renderSuccessLives[\s\S]{0,1200}/)?.[0] || '',
  ));
check('spectator inspect sweep includes Success Live chips (#149)',
  /\.slive-chip\[data-iid\]/.test(indexSrc));
check('force-apply never clears lastResolvedPromptKey',
  /Never clear _lastResolvedPromptKey/.test(applySrc)
  && !/dismissLocalPromptChrome\('turn-advance'\)[\s\S]{0,400}G\._lastResolvedPromptKey = null/.test(applySrc));
check('flushPending forceAdvance is turn-advance only',
  /isTurnAdvanceSnapshot\?\.?\(G\.gameState, next\)/.test(applySrc)
  || /guards\?\.isTurnAdvanceSnapshot/.test(applySrc));
check('dismissLocalPromptChrome keeps lastResolvedPromptKey',
  /function dismissLocalPromptChrome\([\s\S]*?Keep _lastResolvedPromptKey/.test(spectacleSrc)
  || (/function dismissLocalPromptChrome\(/.test(spectacleSrc)
    && !/function dismissLocalPromptChrome\([\s\S]{0,800}G\._lastResolvedPromptKey = null/.test(spectacleSrc)));
check('game-sync has action apply epoch',
  /beginActionApplyEpoch/.test(syncSrc) && /endActionApplyEpoch/.test(syncSrc)
  && /_actionApplyEpoch/.test(syncSrc));
check('sendAct owns apply epoch for resolve/end_main/play',
  /beginActionApplyEpoch/.test(indexSrc) && /endActionApplyEpoch/.test(indexSrc)
  && /ownsApplyEpoch/.test(indexSrc));
check('SSE deferred pull respects action apply epoch',
  /_actionApplyEpochNeedsFollowUp/.test(syncSrc));
check('force pull waits for board catch-up (not full spectacle)',
  /async function dispatchOnState/.test(syncSrc)
  && /waitBoard/.test(syncSrc)
  && /await dispatchOnState\(d, \{ waitBoard: !!\(force && opts\.actionEpoch\) \}\)/.test(syncSrc));
check('turn-advance clears leftover Checking hearts chrome',
  /perfClearHeartCheckHold/.test(applySrc)
  && /apply turn-advance despite presentation hold/.test(applySrc));
check('tab catch-up paints HUD helper',
  /function paintMatchHudAfterTabCatchUp/.test(syncSrc)
  && /clearPlaySelection/.test(syncSrc));
check('state-apply coalesces pending queue (memory)',
  /Keep only the oldest still-owed board/.test(applySrc)
  && /G\._pendingStateQueue = oldest \? \[oldest, s\] : \[s\]/.test(applySrc));
check('tab catch-up soft-preserves Live Start pipeline',
  /shouldSoftTabCatchUpPreserveLivePipeline/.test(syncSrc)
  && /softCatchUpPreserveLivePipeline/.test(syncSrc)
  && /shouldSoftTabCatchUpPreserveLivePipeline/.test(
    fs.readFileSync(path.join(root, 'client/js/presentation-guards.js'), 'utf8'),
  ));
check('soft catch-up drops superseded pending queue',
  /softCatchUpPreserveLivePipeline[\s\S]*?_pendingStateQueue = \(_pendingStateQueue \|\| \[\]\)\.filter/.test(
    syncSrc.replace(/G\./g, ''),
  )
  || /G\._pendingStateQueue = \(G\._pendingStateQueue \|\| \[\]\)\.filter\(st => \(st\.seq \?\? 0\) > \(d\.seq \?\? 0\)\)/.test(syncSrc));
check('soft preserve Live Start wait / live_start stage',
  g.shouldSoftTabCatchUpPreserveLivePipeline(
    { phase: 'live_start_effects', live_show: { stage: 'live_start' } },
    {},
  )
  && g.shouldSoftTabCatchUpPreserveLivePipeline(
    { phase: 'main_first' },
    { awaitingLiveStart: true },
  )
  && !g.shouldSoftTabCatchUpPreserveLivePipeline(
    { phase: 'main_first' },
    { liveRoundPlayback: true },
  )
  && !g.shouldSoftTabCatchUpPreserveLivePipeline(
    { phase: 'main_first' },
    { livePollHold: true },
  )
  && !g.shouldSoftTabCatchUpPreserveLivePipeline(
    { phase: 'main_first' },
    {},
  ));
check('soft catch-up escalates when server left Live Start',
  g.shouldEscalateSoftTabCatchUpToHard({ phase: 'main_first' }, {})
  && g.shouldEscalateSoftTabCatchUpToHard({ status: 'finished', phase: 'live_judge' }, {})
  && g.shouldEscalateSoftTabCatchUpToHard(
    { phase: 'live_start_effects' },
    { hiddenMs: 3000, seqGap: 5 },
  )
  && !g.shouldEscalateSoftTabCatchUpToHard(
    { phase: 'live_start_effects', live_show: { stage: 'live_start' } },
    { hiddenMs: 100, seqGap: 0 },
  ));
check('visibility uses Aug18 gated catch-up (not always)',
  /presentationBusy \|\| hiddenMs >= 1200/.test(indexSrc)
  && /void catchUp\(\{ wasBusy: presentationBusy, hiddenMs \}\)/.test(indexSrc)
  && !/Math\.max\(hiddenMs, 1\)/.test(indexSrc));
check('poll-hold watchdog soft-releases without aborting director',
  /soft release on idle Main/.test(applySrc)
  && /releaseLivePolls\(\{ forceResume: true \}\)/.test(applySrc)
  && !/LiveRoundDirector\.abort\('poll-hold-watchdog'\)/.test(applySrc));
check('abortGameplayPresentation resumes polls via releaseLivePolls',
  /releaseLivePolls\(\{ forceResume: true \}\)/.test(indexSrc));
check('poll gate clears chrome for finished match',
  /mayUnblockPollsForFinishedMatch/.test(syncSrc)
  && /clear leftover chrome for finished/.test(syncSrc));
check('game-sync allows polls during heart-check / Win-Loss when live_show cleared',
  /winLossChrome/.test(syncSrc)
  && /Do NOT block when Checking hearts/.test(syncSrc));
check('dropStale skips flight sweep on settled Main',
  /skipFlightSweep/.test(spectacleSrc)
  || /Never sweep card flights for settled-Main/.test(spectacleSrc));
check('soft catch-up avoids full paintMatchHud thrash',
  /Light HUD only/.test(syncSrc)
  && (() => {
    const m = syncSrc.match(
      /async function softCatchUpPreserveLivePipeline[\s\S]*?\n  global\.catchUpMatchAfterTabVisible/,
    );
    return !!m && !/paintMatchHudAfterTabCatchUp/.test(m[0]);
  })());
check('soft catch-up can escalate to hard',
  /return 'escalate'/.test(syncSrc)
  && /soft !== 'escalate'/.test(syncSrc));
check('matched status keeps search float until enter',
  /Do not clear the float here/.test(indexSrc)
  && /Keep float until enterCasualMatch/.test(indexSrc));
check('resumeQueue re-polls ranked after focus',
  /Signed-in players: always re-check ranked_status/.test(indexSrc));
check('card image preload has timeout',
  /CARD_IMAGE_PRELOAD_TIMEOUT_MS/.test(indexSrc));
check('cache_card_image prebuilds board thumbs',
  /tcgPrebuildCardImageThumbs/.test(cardimgCacheSrc)
  && /foreach \(\[180, 256\]/.test(cardimgCacheSrc));

if (failed) {
  console.error(`\n${failed} presentation-guard contract(s) failed`);
  process.exit(1);
}
console.log('\npresentation-guards: PASS');
