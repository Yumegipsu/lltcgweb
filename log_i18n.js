/* Client-side game log localization (English server log → ja / es / ko display) */
(function (global) {
  'use strict';

  var namePairs = null;
  var namePairsKo = null;
  var namePairsZh = null;

  var SKILL_BRACKETS = {
    'On Enter': '登場時',
    'On Leave': '退場時',
    'Live Start': 'ライブ開始',
    'Live Success': 'ライブ成功',
    'Activated': '起動',
    'Always': '常時',
    'Automatic': '自動',
    'Auto': '自動',
    'Once per turn': 'ターン1回',
    'Center': 'センター',
    'Yell': 'エール',
  };

  var SKILL_BRACKETS_KO = {
    'On Enter': '등장 시',
    'On Leave': '퇴장 시',
    'Live Start': '라이브 개시',
    'Live Success': '라이브 성공',
    'Activated': '기동',
    'Always': '상시',
    'Automatic': '자동',
    'Auto': '자동',
    'Once per turn': '턴에 1회',
    'Center': '센터',
    'Yell': 'Yell',
  };

  var SKILL_BRACKETS_ZH = {
    'On Enter': '入场时',
    'On Leave': '离场时',
    'Live Start': 'Live开始',
    'Live Success': 'Live成功',
    'Activated': '起动',
    'Always': '永续',
    'Automatic': '自动',
    'Auto': '自动',
    'Once per turn': '每回合1次',
    'Twice per turn': '每回合2次',
    'Center': '中央',
    'Yell': 'Yell',
    'Left Side': '左侧',
    'Right Side': '右侧',
  };

  var SLOT_JA = { left: '左', center: 'センター', right: '右' };

  var SLOT_KO = { left: '왼쪽', center: '센터', right: '오른쪽' };
  var SLOT_ZH = { left: '左侧', center: '中央', right: '右侧' };

  var HEART_COLOR_JA = {
    red: '赤',
    blue: '青',
    green: '緑',
    yellow: '黄',
    purple: '紫',
    pink: 'ピンク',
    any: '任意',
  };

  /** English server message → i18n.js log key (exact match before regex). */
  var EXACT_LOG_KEYS = {
    'Game started! Coin flip — winner chooses who goes first.': 'log.gameStartedCoinFlip',
    'Preparation: each player drew 6 cards and placed 3 Energy in storage.': 'log.preparationDrawEnergy',
    'Preparation — Mulligan: you may replace any number of opening hand cards once.': 'log.preparationMulligan',
    'LIVE Phase: place 0–3 cards (Live or Member) face-down in Live storage (draw 1 per card placed), then end LIVE Phase.': 'log.livePhaseIntro',
    'Both players reveal Live storage simultaneously.': 'log.bothRevealLive',
    'No Lives played this turn.': 'log.noLivesThisTurn',
    'Remaining Live storage sent to Waiting Room.': 'log.remainingLiveToWr',
    'Neither player had cards in hand to put into the Waiting Room.': 'log.neitherWrFromHand',
    'Neither player could draw (deck empty).': 'log.neitherCouldDraw',
    'Neither player succeeds — no Live winner this turn.': 'log.neitherLiveWinner',
    'Coin flip — continued automatically (player did not respond in time).': 'log.coinFlipAuto',
    '=== LIVE Phase ===': 'log.dividerLive',
    '=== Performance Phase ===': 'log.dividerPerformance',
    '=== Live Show ===': 'log.dividerLiveShow',
    '=== Live Win/Loss Check Phase ===': 'log.dividerLiveJudge',
    '=== Live Win/Loss Check ===': 'log.dividerLiveJudge',
  };

  function tLog(key, vars) {
    var i18n = global.LLTCG_I18N;
    if (i18n && typeof i18n.t === 'function') return i18n.t(key, vars);
    return key;
  }

  function translateExact(msg) {
    var key = EXACT_LOG_KEYS[msg];
    if (key) return tLog(key);
    var cpu = msg.match(/^CPU deck: (.+)$/);
    if (cpu) return translateOpponentLabels(tLog('log.cpuDeck', { label: cpu[1] }));
    var turnBegin = msg.match(/^=== Turn (\d+) begins ===$/);
    if (turnBegin) return tLog('log.dividerTurnBegin', { turn: turnBegin[1] });
    var turnDash = msg.match(/^--- Turn (\d+) ---$/);
    if (turnDash) return tLog('log.dividerTurn', { turn: turnDash[1] });
    var disc = msg.match(/^(.+) disconnected\. (.+) wins!$/);
    if (disc) return tLog('log.disconnectedWin', { loser: disc[1], winner: disc[2] });
    return null;
  }

  function translateHeartList(raw) {
    if (!raw || !raw.trim()) return raw;
    return raw.split(/\s*,\s*/).map(function (part) {
      var p = part.trim().toLowerCase();
      return HEART_COLOR_JA[p] || part;
    }).join(', ');
  }

  function translateStructuredLine(msg) {
    var m;

    m = msg.match(/^(.+?) performed Live! Blades: (\d+) \| Hearts: \[([^\]]*)\] \| Live success: (\d+) \| Failed: (\d+)( \| Round: failed \(not all Lives succeeded\))?$/);
    if (m) {
      var roundNote = m[6] ? ' | ラウンド失敗（全ライブ成功が必要）' : '';
      return m[1] + ' ライブ披露！ 刃: ' + m[2] +
        ' | ハート: [' + translateHeartList(m[3]) + ']' +
        ' | ライブ成功: ' + m[4] + ' | 失敗: ' + m[5] + roundNote;
    }

    m = msg.match(/^Live Scores: (.+?) = (\d+) \| (.+?) = (\d+)$/);
    if (m) return 'ライブスコア: ' + m[1] + ' = ' + m[2] + ' | ' + m[3] + ' = ' + m[4];

    m = msg.match(/^(.+?) wins the Live — (.+) failed\.$/);
    if (m) return m[1] + ' のライブ勝利 — ' + m[2] + 'は失敗。';

    m = msg.match(/^(.+?) wins this Live! "(.+)" added to successes\.$/);
    if (m) return m[1] + ' このライブ勝利！「' + m[2] + '」を成功ライブに追加。';

    m = msg.match(/^(.+) has no valid Live cards!$/);
    if (m) return m[1] + tLog('log.hasNoValidLive');

    m = msg.match(/^(.+) — choose a Live card for Success Live\.$/);
    if (m) return m[1] + tLog('log.chooseSuccessLive');

    if (msg.endsWith(' — score tied; Success Live blocked; Live cards sent to Waiting Room.')) {
      return msg.slice(0, -' — score tied; Success Live blocked; Live cards sent to Waiting Room.'.length) +
        tLog('log.scoreTiedBlocked');
    }
    if (msg.endsWith(' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.')) {
      return msg.slice(0, -' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.'.length) +
        tLog('log.scoreTiedCap');
    }

    m = msg.match(/^🪙 Coin flip: (.+) won — first player chosen automatically \(time expired\)\.$/);
    if (m) return '🪙 コイントス：' + m[1] + ' の勝ち — 時間切れのため先攻を自動選択。';

    return null;
  }

  function translateStructuredLineEs(msg) {
    var m;

    m = msg.match(/^(.+?) performed Live! Blades: (\d+) \| Hearts: \[([^\]]*)\] \| Live success: (\d+) \| Failed: (\d+)( \| Round: failed \(not all Lives succeeded\))?$/);
    if (m) {
      var roundNote = m[6] ? ' | Ronda fallida (no todos los Lives tuvieron éxito)' : '';
      return m[1] + ' presentó Live. Cuchillas: ' + m[2] +
        ' | Corazones: [' + m[3] + ']' +
        ' | Live exitoso: ' + m[4] + ' | Fallidos: ' + m[5] + roundNote;
    }

    m = msg.match(/^Live Scores: (.+?) = (\d+) \| (.+?) = (\d+)$/);
    if (m) return 'Puntuaciones Live: ' + m[1] + ' = ' + m[2] + ' | ' + m[3] + ' = ' + m[4];

    m = msg.match(/^(.+?) wins the Live — (.+) failed\.$/);
    if (m) return m[1] + ' gana el Live — ' + m[2] + ' falló.';

    m = msg.match(/^(.+?) wins this Live! "(.+)" added to successes\.$/);
    if (m) return m[1] + ' gana este Live. "' + m[2] + '" añadido a los éxitos.';

    m = msg.match(/^(.+) has no valid Live cards!$/);
    if (m) return m[1] + tLog('log.hasNoValidLive');

    m = msg.match(/^(.+) — choose a Live card for Success Live\.$/);
    if (m) return m[1] + tLog('log.chooseSuccessLive');

    if (msg.endsWith(' — score tied; Success Live blocked; Live cards sent to Waiting Room.')) {
      return msg.slice(0, -' — score tied; Success Live blocked; Live cards sent to Waiting Room.'.length) +
        tLog('log.scoreTiedBlocked');
    }
    if (msg.endsWith(' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.')) {
      return msg.slice(0, -' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.'.length) +
        tLog('log.scoreTiedCap');
    }

    m = msg.match(/^🪙 Coin flip: (.+) won — first player chosen automatically \(time expired\)\.$/);
    if (m) return '🪙 Lanzamiento de moneda: ' + m[1] + ' ganó — primer jugador elegido automáticamente (tiempo agotado).';

    return null;
  }

  function translateStructuredLineKo(msg) {
    var m;

    m = msg.match(/^(.+?) performed Live! Blades: (\d+) \| Hearts: \[([^\]]*)\] \| Live success: (\d+) \| Failed: (\d+)( \| Round: failed \(not all Lives succeeded\))?$/);
    if (m) {
      var roundNote = m[6] ? ' | 라운드 실패 (모든 Live가 성공하지 못함)' : '';
      return m[1] + ' Live 진행! 블레이드: ' + m[2] +
        ' | 하트: [' + m[3] + ']' +
        ' | Live 성공: ' + m[4] + ' | 실패: ' + m[5] + roundNote;
    }

    m = msg.match(/^Live Scores: (.+?) = (\d+) \| (.+?) = (\d+)$/);
    if (m) return 'Live 점수: ' + m[1] + ' = ' + m[2] + ' | ' + m[3] + ' = ' + m[4];

    m = msg.match(/^(.+?) wins the Live — (.+) failed\.$/);
    if (m) return m[1] + '의 Live 승리 — ' + m[2] + ' 실패.';

    m = msg.match(/^(.+?) wins this Live! "(.+)" added to successes\.$/);
    if (m) return m[1] + '이 이 Live에서 승리! "' + m[2] + '"이(가) 성공 Live에 추가됨.';

    m = msg.match(/^(.+) has no valid Live cards!$/);
    if (m) return m[1] + tLog('log.hasNoValidLive');

    m = msg.match(/^(.+) — choose a Live card for Success Live\.$/);
    if (m) return m[1] + tLog('log.chooseSuccessLive');

    if (msg.endsWith(' — score tied; Success Live blocked; Live cards sent to Waiting Room.')) {
      return msg.slice(0, -' — score tied; Success Live blocked; Live cards sent to Waiting Room.'.length) +
        tLog('log.scoreTiedBlocked');
    }
    if (msg.endsWith(' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.')) {
      return msg.slice(0, -' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.'.length) +
        tLog('log.scoreTiedCap');
    }

    m = msg.match(/^🪙 Coin flip: (.+) won — first player chosen automatically \(time expired\)\.$/);
    if (m) return '🪙 코인 던지기: ' + m[1] + ' 승리 — 시간 초과로 선공이 자동 선택됨.';

    return null;
  }

  function translateStructuredLineZh(msg) {
    var m;

    m = msg.match(/^(.+?) performed Live! Blades: (\d+) \| Hearts: \[([^\]]*)\] \| Live success: (\d+) \| Failed: (\d+)( \| Round: failed \(not all Lives succeeded\))?$/);
    if (m) {
      var roundNote = m[6] ? ' | 回合失败（未能全部Live成功）' : '';
      return m[1] + ' 进行了Live！Blade：' + m[2] +
        ' | 心形：[' + m[3] + ']' +
        ' | Live成功：' + m[4] + ' | 失败：' + m[5] + roundNote;
    }

    m = msg.match(/^Live Scores: (.+?) = (\d+) \| (.+?) = (\d+)$/);
    if (m) return 'Live分数：' + m[1] + ' = ' + m[2] + ' | ' + m[3] + ' = ' + m[4];

    m = msg.match(/^(.+?) wins the Live — (.+) failed\.$/);
    if (m) return m[1] + '取得Live胜利 — ' + m[2] + '失败。';

    m = msg.match(/^(.+?) wins this Live! "(.+)" added to successes\.$/);
    if (m) return m[1] + '赢得本次Live！"' + m[2] + '"已加入成功Live。';

    m = msg.match(/^(.+) has no valid Live cards!$/);
    if (m) return m[1] + tLog('log.hasNoValidLive');

    m = msg.match(/^(.+) — choose a Live card for Success Live\.$/);
    if (m) return m[1] + tLog('log.chooseSuccessLive');

    if (msg.endsWith(' — score tied; Success Live blocked; Live cards sent to Waiting Room.')) {
      return msg.slice(0, -' — score tied; Success Live blocked; Live cards sent to Waiting Room.'.length) +
        tLog('log.scoreTiedBlocked');
    }
    if (msg.endsWith(' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.')) {
      return msg.slice(0, -' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.'.length) +
        tLog('log.scoreTiedCap');
    }

    m = msg.match(/^🪙 Coin flip: (.+) won — first player chosen automatically \(time expired\)\.$/);
    if (m) return '🪙 抛硬币：' + m[1] + '获胜 — 超时，已自动决定先攻。';

    return null;
  }

  var DIFFICULTY_JA = {
    Easy: 'イージー', Normal: 'ノーマル', Hard: 'ハード',
    easy: 'イージー', normal: 'ノーマル', hard: 'ハード',
  };

  /** CPU opponent label + difficulty (player names in log lines). */
  function translateOpponentLabels(msg) {
    return String(msg)
      .replace(/\bCPU\s*\((Easy|Normal|Hard)\)/g, function (_m, d) {
        return 'COM（' + (DIFFICULTY_JA[d] || d) + '）';
      })
      .replace(/\bCPU\b/g, 'COM')
      .replace(/\b(Easy|Normal|Hard)\b/g, function (m) { return DIFFICULTY_JA[m] || m; });
  }

  /**
   * Phase / system phrases that contain the card name "Energy" — must run before
   * replaceCardNames (catalog has name_en "Energy" → エネルギー).
   */
  var STRUCTURAL_PHRASE_RULES = [
    [/^=== LIVE Phase ===$/, '=== ライブフェイズ ==='],
    [/^=== Performance Phase ===$/, '=== パフォーマンスフェイズ ==='],
    [/^=== Live Show ===$/, '=== ライブショー ==='],
    [/^=== Live Win\/Loss Check Phase ===$/, '=== ライブ勝敗判定 ==='],
    [/^=== Live Win\/Loss Check ===$/, '=== ライブ勝敗判定 ==='],
    [/^=== Turn (\d+) begins ===$/, '=== ターン$1 開始 ==='],
    [/^--- Turn (\d+) ---$/, '--- ターン $1 ---'],
    [/^Game started! Coin flip — winner chooses who goes first\.$/, 'ゲーム開始！コイントス — 勝者が先攻を選びます。'],
    [/^Preparation: each player drew 6 cards and placed 3 Energy in storage\.$/, '準備：各プレイヤーは6枚引き、エネルギー3枚を置きました。'],
    [/^Preparation — Mulligan: you may replace any number of opening hand cards once\.$/, '準備 — マリガン：初手を任意枚数、1回だけ入れ替えできます。'],
    [/^LIVE Phase: place 0–3 cards \(Live or Member\) face-down in Live storage \(draw 1 per card placed\), then end LIVE Phase\.$/, 'ライブフェイズ：ライブ置き場に0〜3枚（ライブまたはメンバー）を裏向きで置き（1枚につき1枚ドロー）、ライブフェイズを終了。'],
    [/^Both players reveal Live storage simultaneously\.$/, '両プレイヤーが同時にライブ置き場を公開。'],
    [/^No Lives played this turn\.$/, 'このターンはライブなし。'],
    [/^Remaining Live storage sent to Waiting Room\.$/, '残りのライブ置き場のカードを控え室へ。'],
    [/^Neither player had cards in hand to put into the Waiting Room\.$/, '手札を控え室に置けるカードがどちらもありませんでした。'],
    [/^Neither player could draw \(deck empty\)\.$/, 'どちらもドローできませんでした（デッキが空）。'],
    [/^Neither player succeeds — no Live winner this turn\.$/, 'どちらも成功せず — このターンのライブ勝者なし。'],
    [/^Coin flip — continued automatically \(player did not respond in time\)\.$/, 'コイントス — 時間切れのため自動続行。'],
    [/^CPU deck: (.+)$/, 'COMデッキ：$1'],
    [/ — End Main Phase\.$/, ' — メインフェイズ終了。'],
    [/ completed mulligan\.$/, ' マリガン完了。'],
    [/ resigned\. (.+) wins!$/, ' リタイア。$1 の勝利！'],
    [/ WINS with 3 successful Lives!$/, ' ライブ3回成功で勝利！'],
    [/ used Baton Touch! Cost reduced to (\d+)\.$/, ' バトンタッチ！コストが$1に減少。'],
    [/ used Baton Touch! Cost reduced to (\d+)\. \((\d+) Energy under replaced Member carried over\.\)$/, ' バトンタッチ！コストが$1に減少。（置き換えメンバー下のエネルギー$2枚を引き継ぎ）'],
    [/ used second Baton Touch! Cost reduced to (\d+)\.$/, ' 2枚目のバトンタッチ！コストが$1に減少。'],
    [/ placed (\d+) card\(s\) face-down in storage \((\d+)\/3\)\.$/, ' $1枚を置き場に裏向きでセット（$2/3）。'],
    [/ placed card\(s\) in Live storage\.$/, ' ライブ置き場にカードをセット。'],
    [/ — locked in LIVE selection \((\d+) card\(s\) in storage\)\.$/, ' — ライブ選択を確定（置き場$1枚）。'],
    [/ — locked in LIVE selection\.$/, ' — ライブ選択を確定。'],
    [/ — Draw Phase: could not draw \(deck and Waiting Room empty\)\.$/, ' — ドローフェイズ：ドロー不可（デッキと控え室が空）。'],
    [/ — Draw Phase\.$/, ' — ドローフェイズ。'],
    [/ — Active Phase: Energy and Members refreshed\.$/, ' — アクティブフェイズ：エネルギーとメンバーをアクティブに。'],
    [/ — Energy Phase: storage full \((\d+)\/(\d+)\), no Energy added\.$/, ' — エネルギーフェイズ：置き場満杯（$1/$2）、エネルギー追加なし。'],
    [/ — Energy Phase: no cards left in Energy deck\.$/, ' — エネルギーフェイズ：エネルギーデッキにカードなし。'],
    [/ — Energy Phase: placed 1 Energy in storage \((\d+)\/(\d+)\)\.$/, ' — エネルギーフェイズ：エネルギー1枚を置き場に（$1/$2）。'],
    [/ — Main Phase time expired \(auto end\)\.$/, ' — メインフェイズ時間切れ（自動終了）。'],
    [/ — LIVE Phase time expired \(auto lock-in\)\.$/, ' — ライブフェイズ時間切れ（自動確定）。'],
    [/ — Yell retry: drew (\d+) card\(s\) for Blade\.$/, ' — エール再試行：刃分$1枚ドロー。'],
    [/ — Yell retry reduced by (\d+) \(drew 0 of (\d+) Blade\)\.$/, ' — エール再試行：$1減少（刃$2枚中0枚ドロー）。'],
    [/ — Yell reduced by (\d+) \(drew (\d+) of (\d+) Blade\)\.$/, ' — エール：$1減少（刃$3枚中$2枚ドロー）。'],
    [/ — Support LIVE \(Yell\): drew (\d+) card\(s\) for Blade\.$/, ' — サポートライブ（エール）：刃分$1枚ドロー。'],
    [/ — Drew (\d+) card\(s\) from Yell draw icon\(s\)\.$/, ' — エールドローアイコンから$1枚ドロー。'],
    [/ — (\d+) non-Live card\(s\) from storage sent to Waiting Room\.$/, ' — 置き場の非ライブカード$1枚を控え室へ。'],
    [/ — (\d+) other successful Live\(s\) in storage cannot be placed \(only 1 Success Live per Judge win\); sent to Waiting Room\.$/, ' — 置き場の他の成功ライブ$1枚は追加不可（判定勝利ごとに成功ライブ1枚）、控え室へ。'],
    [/ — \[([^\]]+)\] drew (\d+) \(Active → Wait\)\.$/, ' — [$1] $2枚ドロー（アクティブ→ウェイト）。'],
    [/ — \[([^\]]+)\] optional skill skipped\.$/, ' — [$1] スキルをスキップ。'],
    [/ — \[([^\]]+)\] activated\.$/, ' — [$1] 起動。'],
    [/ — \[([^\]]+)\] Live Start skipped\.$/, ' — [$1] ライブ開始スキップ。'],
    [/ — \[([^\]]+)\] Live Success skipped\.$/, ' — [$1] ライブ成功スキップ。'],
    [/ — \[([^\]]+)\] Yell cards to Waiting Room; Yell again \(Blade hearts from prior Yell lost\)\.$/, ' — [$1] エールカードを控え室へ、再エール（前回エールの刃ハート消失）。'],
    [/put 1 Energy from Energy deck into Wait\./, 'エネルギーデッキからエネルギー1枚をウェイトに。'],
    [/put 1 Energy from Energy deck into Wait \(excess hearts\)\./, 'エネルギーデッキからエネルギー1枚をウェイトに（余剰ハート）。'],
    [/put 1 Energy from Energy deck into Wait \(fewer Energy\)\./, 'エネルギーデッキからエネルギー1枚をウェイトに（エネルギー不足）。'],
    [/put 1 Energy from Energy deck into Wait \(Yell revealed Live\)\./, 'エネルギーデッキからエネルギー1枚をウェイトに（エールで公開したライブ）。'],
    [/could not put Energy into Wait \(Energy deck empty\)\./, 'エネルギーをウェイトに置けません（エネルギーデッキが空）。'],
    [/added (\d+) Member cards? from Waiting Room to hand\./, '控え室からメンバーカード$1枚を手札に加えた。'],
    [/no Member card in Waiting Room to add to hand\./, '控え室に手札へ加えるメンバーカードがない。'],
    [/Live SUCCESS/, 'ライブ成功'],
    [/Live FAIL/, 'ライブ失敗'],
    [/Live failed/, 'ライブ失敗'],
    [/Live succeeded/, 'ライブ成功'],
    [/ is activating a skill \(([^)]+)\)…$/, ' がスキルを発動中（$1）…'],
    [/ is activating a skill…$/, ' がスキルを発動中…'],
    [/^🪙 Coin flip: (.+) won and chose to go first!$/, '🪙 コイントス：$1 の勝ち — 自分が先攻！'],
    [/^🪙 Coin flip: (.+) won and chose (.+) to go first!$/, '🪙 コイントス：$1 の勝ち — $2 が先攻！'],
    [/^🎉 (.+) WINS with 3 successful Lives!$/, '🎉 $1 ライブ3回成功で勝利！'],
    [/^(.+)'s turn — Main Phase \(Active · Energy · Draw complete\)\.$/, '$1のターン — メインフェイズ（アクティブ・エネルギー・ドロー完了）。'],
    [/^(.+) turn — Main Phase \(Active · Energy · Draw complete\)\.$/, '$1のターン — メインフェイズ（アクティブ・エネルギー・ドロー完了）。'],
    [/^(.+) turn — Main Phase…$/, '$1のターン — メインフェイズ…'],
    [/Both players put (\d+) cards? into the Waiting Room\.$/, '両プレイヤーが手札$1枚を控え室に置きました。'],
    [/Both players drew \(([^)]+)\)\.$/, '両プレイヤーがドロー（$1）。'],
    [/Both players' Stage Members gain \+(\d+) Blade\.?$/, '両プレイヤーのステージのメンバー全員が刃+$1。'],
    [/put (\d+) opponent Stage Member\(s\) into Wait\.?$/, '相手ステージのメンバー$1体をウェイトに。'],
    [/ had no card in hand to discard\.$/, ' 手札に捨てるカードがなかった。'],
    [/ had no cards in hand to discard\.$/, ' 手札に捨てるカードがなかった。'],
    [/ drew (\d+) but had no cards in hand to discard\.$/, ' $1枚ドローしたが手札に捨てるカードがなかった。'],
    [/ disconnected\. (.+) wins!$/, ' 切断。$1 の勝利！'],
    [/ wins the Live — (.+) failed\.$/, ' のライブ勝利 — $1は失敗。'],
    [/ wins this Live! "/, ' このライブ勝利！「'],
    [/" added to successes\.$/, '」を成功ライブに追加。'],
    [/Live Scores: /, 'ライブスコア: '],
    [/ — Active Phase: エネルギー and Members refreshed\.$/, ' — アクティブフェイズ：エネルギーとメンバーをアクティブに。'],
    [/ — エネルギー Phase: placed 1 エネルギー in storage \((\d+)\/(\d+)\)\.$/, ' — エネルギーフェイズ：エネルギー1枚を置き場に（$1/$2）。'],
    [/^(.+)'s turn — メインフェイズ \(Active · エネルギー · Draw complete\)\.$/, '$1のターン — メインフェイズ（アクティブ・エネルギー・ドロー完了）。'],
    [/^(.+) turn — メインフェイズ \(Active · エネルギー · Draw complete\)\.$/, '$1のターン — メインフェイズ（アクティブ・エネルギー・ドロー完了）。'],
  ];

  /** Regex rules applied after card-name swap (order matters). */
  var PHRASE_RULES = [
    [/ overplayed onto (.+)\.$/, ' $1の上に上書きプレイ。'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      return ' ' + card + 'を' + (SLOT_JA[slot] || slot) + 'エリアにプレイ。';
    }],
    [/ is performing Live with (.+)\.$/, ' がライブを披露：$1。'],
    [/Waiting Room/g, '控え室'],
    [/Live storage/g, 'ライブ置き場'],
    [/Success Live card storage/g, '成功ライブ置き場'],
    [/Success Live/g, '成功ライブ'],
    [/Energy deck/g, 'エネルギーデッキ'],
    [/Main Deck/g, 'メインデッキ'],
    [/Stage Member/g, 'ステージのメンバー'],
    [/Baton Touch/g, 'バトンタッチ'],
  ];

  /** Core structural phrases for Spanish (high-frequency turn / phase / Live lines). */
  var STRUCTURAL_PHRASE_RULES_ES = [
    [/ — End Main Phase\.$/, ' — Fin de la Fase principal.'],
    [/ completed mulligan\.$/, ' completó el muligan.'],
    [/ resigned\. (.+) wins!$/, ' se rindió. ¡$1 gana!'],
    [/ WINS with 3 successful Lives!$/, ' GANA con 3 Lives exitosos.'],
    [/ used Baton Touch! Cost reduced to (\d+)\.$/, ' usó Baton Touch. Costo reducido a $1.'],
    [/ used second Baton Touch! Cost reduced to (\d+)\.$/, ' usó un segundo Baton Touch. Costo reducido a $1.'],
    [/ placed (\d+) card\(s\) face-down in storage \((\d+)\/3\)\.$/, ' colocó $1 carta(s) boca abajo en almacenamiento ($2/3).'],
    [/ placed card\(s\) in Live storage\.$/, ' colocó carta(s) en almacenamiento de Live.'],
    [/ — locked in LIVE selection \((\d+) card\(s\) in storage\)\.$/, ' — selección Live confirmada ($1 carta(s) en almacenamiento).'],
    [/ — locked in LIVE selection\.$/, ' — selección Live confirmada.'],
    [/ — Draw Phase: could not draw \(deck and Waiting Room empty\)\.$/, ' — Fase de robo: no pudo robar (mazo y Sala de espera vacíos).'],
    [/ — Draw Phase\.$/, ' — Fase de robo.'],
    [/ — Active Phase: Energy and Members refreshed\.$/, ' — Fase activa: Energía y Miembros renovados.'],
    [/ — Energy Phase: storage full \((\d+)\/(\d+)\), no Energy added\.$/, ' — Fase de Energía: almacenamiento lleno ($1/$2), no se añadió Energía.'],
    [/ — Energy Phase: no cards left in Energy deck\.$/, ' — Fase de Energía: no quedan cartas en el mazo de Energía.'],
    [/ — Energy Phase: placed 1 Energy in storage \((\d+)\/(\d+)\)\.$/, ' — Fase de Energía: colocó 1 Energía en almacenamiento ($1/$2).'],
    [/ — Main Phase time expired \(auto end\)\.$/, ' — Fase principal: tiempo agotado (fin automático).'],
    [/ — LIVE Phase time expired \(auto lock-in\)\.$/, ' — Fase Live: tiempo agotado (confirmación automática).'],
    [/^(.+?)(?:'s|') Live Phase\.$/, 'Fase Live de $1.'],
    [/ — \[([^\]]+)\] drew (\d+) \(Active → Wait\)\.$/, ' — [$1] robó $2 (Activo → Espera).'],
    [/ — \[([^\]]+)\] optional skill skipped\.$/, ' — [$1] habilidad opcional omitida.'],
    [/ — \[([^\]]+)\] activated\.$/, ' — [$1] activada.'],
    [/ — \[([^\]]+)\] Live Start skipped\.$/, ' — [$1] Live Start omitido.'],
    [/ — \[([^\]]+)\] Live Success skipped\.$/, ' — [$1] Live Success omitido.'],
    [/Live SUCCESS/, 'Live EXITOSO'],
    [/Live FAIL/, 'Live FALLIDO'],
    [/Live failed/, 'Live fallido'],
    [/Live succeeded/, 'Live exitoso'],
    [/^(.+)'s turn — Main Phase \(Active · Energy · Draw complete\)\.$/, 'Turno de $1 — Fase principal (Activa · Energía · Robo completos).'],
    [/^(.+) turn — Main Phase \(Active · Energy · Draw complete\)\.$/, 'Turno de $1 — Fase principal (Activa · Energía · Robo completos).'],
    [/^(.+) turn — Main Phase…$/, 'Turno de $1 — Fase principal…'],
    [/^🪙 Coin flip: (.+) won and chose to go first!$/, '🪙 Lanzamiento de moneda: $1 ganó y eligió ir primero.'],
    [/^🪙 Coin flip: (.+) won and chose (.+) to go first!$/, '🪙 Lanzamiento de moneda: $1 ganó y eligió que $2 vaya primero.'],
    [/^🎉 (.+) WINS with 3 successful Lives!$/, '🎉 ¡$1 GANA con 3 Lives exitosos!'],
    [/ disconnected\. (.+) wins!$/, ' se desconectó. ¡$1 gana!'],
    [/ had no card in hand to discard\.$/, ' no tenía carta en mano para descartar.'],
    [/ had no cards in hand to discard\.$/, ' no tenía cartas en mano para descartar.'],
    [/ drew (\d+) but had no cards in hand to discard\.$/, ' robó $1 pero no tenía cartas en mano para descartar.'],
    [/ is performing Live with (.+)\.$/, ' está presentando Live con $1.'],
    [/Both players put (\d+) cards? into the Waiting Room\.$/, 'Ambos jugadores pusieron $1 carta(s) en la Sala de espera.'],
    [/Both players drew \(([^)]+)\)\.$/, 'Ambos jugadores robaron ($1).'],
  ];

  /** Core structural phrases for Korean (high-frequency turn / phase / Live lines). */
  var STRUCTURAL_PHRASE_RULES_KO = [
    [/ — End Main Phase\.$/, ' — 메인 페이즈 종료.'],
    [/ completed mulligan\.$/, ' 멀리건 완료.'],
    [/ resigned\. (.+) wins!$/, ' 기권. $1 승리!'],
    [/ WINS with 3 successful Lives!$/, ' Live 3회 성공으로 승리!'],
    [/ used Baton Touch! Cost reduced to (\d+)\.$/, ' 바톤 터치 사용! 코스트가 $1(으)로 감소.'],
    [/ used second Baton Touch! Cost reduced to (\d+)\.$/, ' 두 번째 바톤 터치 사용! 코스트가 $1(으)로 감소.'],
    [/ placed (\d+) card\(s\) face-down in storage \((\d+)\/3\)\.$/, ' 카드 $1장을 뒤집어 보관함에 배치 ($2/3).'],
    [/ placed card\(s\) in Live storage\.$/, ' Live 보관함에 카드를 배치.'],
    [/ — locked in LIVE selection \((\d+) card\(s\) in storage\)\.$/, ' — Live 선택 확정 (보관함 $1장).'],
    [/ — locked in LIVE selection\.$/, ' — Live 선택 확정.'],
    [/ — Draw Phase: could not draw \(deck and Waiting Room empty\)\.$/, ' — 드로우 페이즈: 드로우 불가 (덱과 대기실이 비어 있음).'],
    [/ — Draw Phase\.$/, ' — 드로우 페이즈.'],
    [/ — Active Phase: Energy and Members refreshed\.$/, ' — 액티브 페이즈: 에너지와 멤버가 리프레시됨.'],
    [/ — Energy Phase: storage full \((\d+)\/(\d+)\), no Energy added\.$/, ' — 에너지 페이즈: 보관함이 가득 차 ($1/$2), 에너지가 추가되지 않음.'],
    [/ — Energy Phase: no cards left in Energy deck\.$/, ' — 에너지 페이즈: 에너지 덱에 카드가 남아 있지 않음.'],
    [/ — Energy Phase: placed 1 Energy in storage \((\d+)\/(\d+)\)\.$/, ' — 에너지 페이즈: 에너지 1장을 보관함에 배치 ($1/$2).'],
    [/ — Main Phase time expired \(auto end\)\.$/, ' — 메인 페이즈 시간 초과 (자동 종료).'],
    [/ — LIVE Phase time expired \(auto lock-in\)\.$/, ' — Live 페이즈 시간 초과 (자동 확정).'],
    [/^(.+?)(?:'s|') Live Phase\.$/, '$1의 Live 페이즈.'],
    [/ — \[([^\]]+)\] drew (\d+) \(Active → Wait\)\.$/, ' — [$1] $2장 드로우 (액티브 → 웨이트).'],
    [/ — \[([^\]]+)\] optional skill skipped\.$/, ' — [$1] 선택 스킬 건너뜀.'],
    [/ — \[([^\]]+)\] activated\.$/, ' — [$1] 발동.'],
    [/ — \[([^\]]+)\] Live Start skipped\.$/, ' — [$1] 라이브 개시 건너뜀.'],
    [/ — \[([^\]]+)\] Live Success skipped\.$/, ' — [$1] 라이브 성공 건너뜀.'],
    [/Live SUCCESS/, 'Live 성공'],
    [/Live FAIL/, 'Live 실패'],
    [/Live failed/, 'Live 실패'],
    [/Live succeeded/, 'Live 성공'],
    [/^(.+)'s turn — Main Phase \(Active · Energy · Draw complete\)\.$/, '$1의 턴 — 메인 페이즈 (액티브 · 에너지 · 드로우 완료).'],
    [/^(.+) turn — Main Phase \(Active · Energy · Draw complete\)\.$/, '$1의 턴 — 메인 페이즈 (액티브 · 에너지 · 드로우 완료).'],
    [/^(.+) turn — Main Phase…$/, '$1의 턴 — 메인 페이즈…'],
    [/^🪙 Coin flip: (.+) won and chose to go first!$/, '🪙 코인 던지기: $1 승리 — 선공을 선택함!'],
    [/^🪙 Coin flip: (.+) won and chose (.+) to go first!$/, '🪙 코인 던지기: $1 승리 — $2 를 선공으로 선택함!'],
    [/^🎉 (.+) WINS with 3 successful Lives!$/, '🎉 $1 Live 3회 성공으로 승리!'],
    [/ disconnected\. (.+) wins!$/, ' 연결 끊김. $1 승리!'],
    [/ had no card in hand to discard\.$/, ' 손에 버릴 카드가 없었음.'],
    [/ had no cards in hand to discard\.$/, ' 손에 버릴 카드가 없었음.'],
    [/ drew (\d+) but had no cards in hand to discard\.$/, ' $1장 드로우했지만 손에 버릴 카드가 없었음.'],
    [/ is performing Live with (.+)\.$/, ' 이 $1로 Live 진행 중.'],
    [/Both players put (\d+) cards? into the Waiting Room\.$/, '두 플레이어 모두 $1장을 대기실로 보냄.'],
    [/Both players drew \(([^)]+)\)\.$/, '두 플레이어 모두 드로우함 ($1).'],
  ];


  var STRUCTURAL_PHRASE_RULES_ZH = [
    [/ — End Main Phase\.$/, ' — 主要阶段结束。'],
    [/ completed mulligan\.$/, ' 完成换牌。'],
    [/ resigned\. (.+) wins!$/, ' 投降。$1 获胜！'],
    [/ WINS with 3 successful Lives!$/, ' 以3次成功Live获胜！'],
    [/ used Baton Touch! Cost reduced to (\d+)\.$/, ' 使用了接棒！费用降至 $1。'],
    [/ used second Baton Touch! Cost reduced to (\d+)\.$/, ' 使用了第二次接棒！费用降至 $1。'],
    [/ placed (\d+) card\(s\) face-down in storage \((\d+)\/3\)\.$/, ' 将 $1 张卡正面朝下放入存放区（$2/3）。'],
    [/ placed card\(s\) in Live storage\.$/, ' 将卡放入Live存放区。'],
    [/ — locked in LIVE selection \((\d+) card\(s\) in storage\)\.$/, ' — 已锁定Live选择（存放区 $1 张）。'],
    [/ — locked in LIVE selection\.$/, ' — 已锁定Live选择。'],
    [/ — Draw Phase: could not draw \(deck and Waiting Room empty\)\.$/, ' — 抽牌阶段：无法抽牌（牌组与等候室均为空）。'],
    [/ — Draw Phase\.$/, ' — 抽牌阶段。'],
    [/ — Active Phase: Energy and Members refreshed\.$/, ' — 激活阶段：能量与成员已重整。'],
    [/ — Energy Phase: storage full \((\d+)\/(\d+)\), no Energy added\.$/, ' — 能量阶段：存放区已满（$1/$2），未添加能量。'],
    [/ — Energy Phase: no cards left in Energy deck\.$/, ' — 能量阶段：能量牌组中没有卡。'],
    [/ — Energy Phase: placed 1 Energy in storage \((\d+)\/(\d+)\)\.$/, ' — 能量阶段：将1张能量放入存放区（$1/$2）。'],
    [/ — Main Phase time expired \(auto end\)\.$/, ' — 主要阶段超时（自动结束）。'],
    [/ — LIVE Phase time expired \(auto lock-in\)\.$/, ' — Live阶段超时（自动锁定）。'],
    [/^(.+?)(?:'s|') Live Phase\.$/, '$1的Live阶段。'],
    [/ — \[([^\]]+)\] drew (\d+) \(Active → Wait\)\.$/, ' — [$1] 抽了 $2 张（激活 → Wait）。'],
    [/ — \[([^\]]+)\] optional skill skipped\.$/, ' — [$1] 跳过了可选技能。'],
    [/ — \[([^\]]+)\] activated\.$/, ' — [$1] 发动。'],
    [/ — \[([^\]]+)\] Live Start skipped\.$/, ' — [$1] 跳过了Live开始。'],
    [/ — \[([^\]]+)\] Live Success skipped\.$/, ' — [$1] 跳过了Live成功。'],
    [/Live SUCCESS/, 'Live成功'],
    [/Live FAIL/, 'Live失败'],
    [/Live failed/, 'Live失败'],
    [/Live succeeded/, 'Live成功'],
    [/^(.+)'s turn — Main Phase \(Active · Energy · Draw complete\)\.$/, '$1的回合 — 主要阶段（激活 · 能量 · 抽牌完成）。'],
    [/^(.+) turn — Main Phase \(Active · Energy · Draw complete\)\.$/, '$1的回合 — 主要阶段（激活 · 能量 · 抽牌完成）。'],
    [/^(.+) turn — Main Phase…$/, '$1的回合 — 主要阶段…'],
    [/^🪙 Coin flip: (.+) won and chose to go first!$/, '🪙 抛硬币：$1 获胜 — 选择先攻！'],
    [/^🪙 Coin flip: (.+) won and chose (.+) to go first!$/, '🪙 抛硬币：$1 获胜 — 选择 $2 先攻！'],
    [/^🎉 (.+) WINS with 3 successful Lives!$/, '🎉 $1 以3次成功Live获胜！'],
    [/ disconnected\. (.+) wins!$/, ' 断线。$1 获胜！'],
    [/ had no card in hand to discard\.$/, ' 手牌没有可弃置的卡。'],
    [/ had no cards in hand to discard\.$/, ' 手牌没有可弃置的卡。'],
    [/ drew (\d+) but had no cards in hand to discard\.$/, ' 抽了 $1 张但手牌没有可弃置的卡。'],
    [/ is performing Live with (.+)\.$/, ' 正在用 $1 进行Live。'],
    [/Both players put (\d+) cards? into the Waiting Room\.$/, '双方各将 $1 张卡放入等候室。'],
    [/Both players drew \(([^)]+)\)\.$/, '双方都抽了牌（$1）。'],
  ];

  /** Term replacement for Spanish (order matters; Baton Touch stays English). */
  var PHRASE_RULES_ES = [
    [/Success Live card storage/g, 'almacenamiento de Live exitoso'],
    [/Live storage/g, 'almacenamiento de Live'],
    [/Success Live/g, 'Live exitoso'],
    [/Waiting Room/g, 'Sala de espera'],
    [/Energy deck/g, 'mazo de Energía'],
    [/Main Deck/g, 'mazo principal'],
    [/ overplayed onto (.+)\.$/, ' sobrescribió sobre $1.'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      var slots = { left: 'izquierda', center: 'centro', right: 'derecha' };
      return ' jugó ' + card + ' en el área ' + (slots[slot] || slot) + '.';
    }],
  ];

  /** Term replacement for Korean (order matters; Baton Touch stays English). */
  var PHRASE_RULES_KO = [
    [/Success Live card storage/g, '성공 Live 카드 보관함'],
    [/Live storage/g, 'Live 보관함'],
    [/Success Live/g, '성공 Live'],
    [/Waiting Room/g, '대기실'],
    [/Energy deck/g, '에너지 덱'],
    [/Main Deck/g, '메인 덱'],
    [/Stage Member/g, '스테이지 멤버'],
    [/ overplayed onto (.+)\.$/, ' $1 위에 겹쳐 플레이함.'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      return ' ' + card + '를 ' + (SLOT_KO[slot] || slot) + ' 구역에 플레이함.';
    }],
  ];

  /** Effect-detail suffix rules for Spanish (draw / discard / play). */
  var EFFECT_RULES_ES = [
    [/drew a card\./, 'robó una carta.'],
    [/drew (.+)\./, 'robó $1.'],
    [/discarded a card\./, 'descartó una carta.'],
    [/put (.+) into the Waiting Room\./, 'envió $1 a la Sala de espera.'],
    [/put a card into the Waiting Room\./, 'envió una carta a la Sala de espera.'],
    [/optional Live Start \(choose\)\./, 'Live Start opcional (elige).'],
    [/optional Live Start effect \(choose\)\./, 'efecto Live Start opcional (elige).'],
    [/Live Success choice\./, 'elección de Live Success.'],
  ];

  /** Effect-detail suffix rules for Korean (draw / discard / play). */
  var EFFECT_RULES_KO = [
    [/drew a card\./, '카드 1장을 드로우함.'],
    [/drew (.+)\./, '$1을(를) 드로우함.'],
    [/discarded a card\./, '카드 1장을 버림.'],
    [/put (.+) into the Waiting Room\./, '$1을(를) 대기실로 보냄.'],
    [/put a card into the Waiting Room\./, '카드 1장을 대기실로 보냄.'],
    [/optional Live Start \(choose\)\./, '선택적 라이브 개시 (선택).'],
    [/optional Live Start effect \(choose\)\./, '선택적 라이브 개시 효과 (선택).'],
    [/Live Success choice\./, '라이브 성공 선택.'],
  ];

  /** Effect-detail suffix rules (after card names are localized). */
  var EFFECT_RULES = [
    [/gained \+(\d+) Blade until Live ends \(Yell\)\./, 'ライブ終了まで刃+$1（エール）。'],
    [/gained \+(\d+) Blade until Live ends \(Baton Touch\)\./, 'ライブ終了まで刃+$1（バトンタッチ）。'],
    [/gained \+(\d+) Blade until Live ends \(moved in slot\)\./, 'ライブ終了まで刃+$1（スロット移動）。'],
    [/gained \+(\d+) Blade until Live ends\./, 'ライブ終了まで刃+$1。'],
    [/gained \+(\d+) Blade until this Live ends\./, 'このライブ終了まで刃+$1。'],
    [/gained \+(\d+) Blade \(moved\)\./, '刃+$1（移動）。'],
    [/gained \+(\d+) bonus heart\(s\) \(all milled Members matched\)\./, 'ボーナスハート+$1（ミルした全メンバー一致）。'],
    [/gained \+(\d+) Blade \(all milled Members had hearts\)\./, '刃+$1（ミルした全メンバーにハート）。'],
    [/gains \+(\d+) Blade until this Live ends\./, 'このライブ終了まで刃+$1。'],
    [/gains \+(\d+) total Live Score until this Live ends\./, 'このライブ終了まで合計ライブスコア+$1。'],
    [/Live total score \+(\d+) until Live ends\./, 'ライブ終了まで合計スコア+$1。'],
    [/(\d+) other Member\(s\) gained \+(\d+) Blade until Live ends\./, '他メンバー$1体がライブ終了まで刃+$2。'],
    [/(\d+) Member\(s\) gained \+(\d+) Blade until Live ends\./, 'メンバー$1体がライブ終了まで刃+$2。'],
    [/score \+(\d+) until Live ends\./, 'ライブ終了までスコア+$1。'],
    [/score \+(\d+) \(([^)]+)\)\./, 'スコア+$1（$2）。'],
    [/score \+(\d+)\./, 'スコア+$1。'],
    [/score set to (\d+)\./, 'スコアを$1に設定。'],
    [/revealed Live; score \+(\d+)\./, 'ライブ公開、スコア+$1。'],
    [/revealed top of deck \(not a Live card\)\./, 'デッキトップ公開（ライブカードではない）。'],
    [/revealed (.+) from deck top\./, '$1をデッキトップから公開。'],
    [/revealed a card from deck top\./, 'デッキトップから1枚公開。'],
    [/looked at (\d+) card\(s\); none eligible\./, '$1枚確認、対象なし。'],
    [/looked at (\d+) card\(s\) \(choose\)\./, '$1枚確認（選択）。'],
    [/looked at top (\d+) — arrange them\./, '上$1枚確認 — 順序を決定。'],
    [/looked at deck top \(empty\)\./, 'デッキトップ確認（空）。'],
    [/drew (\d+) \(opponent active Member put into Wait by your effect\)\./, '$1枚ドロー（相手アクティブメンバーをウェイトに）。'],
    [/drew a card\./, '1枚ドロー。'],
    [/drew (.+)\./, '$1をドロー。'],
    [/put (.+) into the Waiting Room\./, '$1を控え室へ。'],
    [/put a card into the Waiting Room\./, '1枚を控え室へ。'],
    [/put (\d+) card\(s\) from deck top into Waiting Room\./, 'デッキトップ$1枚を控え室へ。'],
    [/put (\d+) card\(s\) into Waiting Room\./, '$1枚を控え室へ。'],
    [/put (\d+) opponent Stage Member(s?) into Wait\./, '相手ステージのメンバー$1体をウェイトに。'],
    [/Put 1 opponent Stage Member with cost (\d+) or less into Wait\./, 'コスト$1以下の相手ステージメンバー1体をウェイトに。'],
    [/Put all opponent Stage Members with cost (\d+) or less into Wait\./, 'コスト$1以下の相手ステージメンバー全員をウェイトに。'],
    [/from Waiting Room onto Stage in Wait\./, '控え室からステージへ（ウェイト）。'],
    [/from Waiting Room onto Stage\./, '控え室からステージへ。'],
    [/added (.+) from Yell to hand\./, 'エールから$1を手札に加えた。'],
    [/added (.+) from Baton Touch to hand\./, 'バトンタッチから$1を手札に加えた。'],
    [/added 1 card from surveil to hand\./, '見た1枚を手札に加えた。'],
    [/added a card from Waiting Room to hand\./, '控え室から1枚を手札に加えた。'],
    [/added a card on top of deck\./, '1枚をデッキトップに加えた。'],
    [/discarded a card\./, '1枚を捨てた。'],
    [/discarded (\d+); (\d+) Member\(s\) gained \+(\d+) Blade\./, '$1枚捨て、メンバー$2体が刃+$3。'],
    [/paid (\d+) Energy; placed Live card from Waiting Room into storage\./, 'エネルギー$1支払い、控え室のライブを置き場へ。'],
    [/activated (\d+) (.+?) Member\(s\)\./, '$2メンバー$1体をアクティブに。'],
    [/optional Live Start \(choose\)\./, 'ライブ開始（任意・選択）。'],
    [/optional Live Start effect \(choose\)\./, 'ライブ開始効果（任意・選択）。'],
    [/optional On Enter \(pay Energy\)\./, '登場時（任意・エネルギー支払い）。'],
    [/optional On Enter \(choose\)\./, '登場時（任意・選択）。'],
    [/optional On Enter \(choose Member\)\./, '登場時（任意・メンバー選択）。'],
    [/optional On Enter effect \(choose\)\./, '登場時効果（任意・選択）。'],
    [/optional On Enter skipped \(no cards left in deck\)\./, '登場時スキップ（デッキ残りなし）。'],
    [/optional Wait effect \(choose\)\./, 'ウェイト効果（任意・選択）。'],
    [/optional effect \(choose\)\./, '任意効果（選択）。'],
    [/optional Stage reposition \(choose\)\./, 'ステージ移動（任意・選択）。'],
    [/optional position change \(choose\)\./, '位置変更（任意・選択）。'],
    [/optional Success \/ WR Live swap \(choose\)\./, '成功ライブ／控え室ライブ入替（任意・選択）。'],
    [/effect skipped \(need (\d+)\+ Energy\)\./, '効果スキップ（エネルギー$1以上必要）。'],
    [/Baton Touch effect resolved\./, 'バトンタッチ効果解決。'],
    [/Live Start: choose a heart color\./, 'ライブ開始：ハート色を選択。'],
    [/Live Start: choose a heart for a μ's Member\./, 'ライブ開始：μ\'sメンバーのハートを選択。'],
    [/Live Start: choose a player\./, 'ライブ開始：プレイヤーを選択。'],
    [/Live Start: choose an effect\./, 'ライブ開始：効果を選択。'],
    [/Live Success choice\./, 'ライブ成功：選択。'],
    [/Live Success \(optional deck bottom\)\./, 'ライブ成功（任意・デッキ底）。'],
    [/choose a Live card from Waiting Room\./, '控え室からライブカードを選択。'],
    [/choose a Live card\./, 'ライブカードを選択。'],
    [/choose a Yell card\./, 'エールカードを選択。'],
    [/choose a heart color to waive\./, '免除するハート色を選択。'],
    [/choose a heart color\./, 'ハート色を選択。'],
    [/choose required heart pattern\./, '必要なハートパターンを選択。'],
    [/choose Members for \+Blade\./, '刃+対象メンバーを選択。'],
    [/choose Waiting Room Lives for opponent to pick\./, '相手に選ばせる控え室ライブを選択。'],
    [/opponent must choose an effect\./, '相手が効果を選択。'],
    [/choose one effect\./, '効果を1つ選択。'],
    [/asks opponent: "/, '相手に確認：「'],
    [/Waited a μ's Member for bonus hearts\./, 'μ\'sメンバー1体をウェイトにしてボーナスハート。'],
    [/Yell Blade hearts become Blue until Live ends\./, 'エール刃ハートが青扱いになる（ライブ終了まで）。'],
    [/Yell reveal count reduced by (\d+) until Live ends\./, 'エール公開枚数-$1（ライブ終了まで）。'],
    [/\+1 Blade per (\d+) cards in hand until Live ends\./, '手札$1枚ごとに刃+1（ライブ終了まで）。'],
    [/Optional effect — see card text\./, '任意効果 — カードテキスト参照。'],
    [/Live Success ability negated \(Aqours stage hearts\)\./, 'ライブ成功能力無効（Aqoursステージハート）。'],
    [/if Live scores tie, neither player adds Success Lives this turn\./, 'ライブスコア同点のため、双方成功ライブ追加なし。'],
    [/arranged (\d+) looked card\(s\)\./, '確認した$1枚の順序を決定。'],
    [/granted bonus hearts to /, 'ボーナスハート付与：'],
    [/granted \+(\d+) Blade to /, '刃+$1付与：'],
    [/Center Blade/g, 'センター刃'],
    [/Success score/g, '成功スコア'],
    [/deck refreshed this turn/g, 'このターンにデッキ再構築'],
    [/fewer Success Lives/g, '成功ライブが少ない'],
    [/more cards in hand/g, '手札が多い'],
    [/all heart colors in Yell/g, 'エールの全ハート色'],
    [/Aqours stage hearts/g, 'Aqoursステージハート'],
    [/Aqours hearts \+ opponent no excess/g, 'Aqoursハート＋相手余剰なし'],
    [/stage \+ Waiting Room Live name/g, 'ステージ＋控え室ライブ名'],
    [/lily white only, no Success Lives/g, 'リリーホワイトのみ、成功ライブなし'],
    [/named Members in position/g, '指定メンバーが配置'],
    [/distinct Members/g, '異なるメンバー'],
    [/turn 1/g, 'ターン1'],
    [/Put 1 card from your hand into the Waiting Room: look at the top (\d+) cards of your deck, add 1 to your hand, and put the rest into the Waiting Room\?$/,
      '手札1枚を控え室に：デッキ上$1枚を見て1枚を手札に加え、残りを控え室へ？'],
    [/Put 1 card from your hand into the Waiting Room: look at the top (\d+) cards of your deck, add 1 to your hand, and put the rest into the Waiting Room\./,
      '手札1枚を控え室に：デッキ上$1枚を見て1枚を手札に加え、残りを控え室へ。'],
    [/Use optional Live Start effect\?/, 'このライブ開始時効果を使いますか？'],
    [/Use optional effect\?/, 'この効果を使いますか？'],
  ];


  var PHRASE_RULES_ZH = [
    [/Success Live card storage/g, '成功Live卡区'],
    [/Live storage/g, 'Live存放区'],
    [/Success Live/g, '成功Live'],
    [/Waiting Room/g, '等候室'],
    [/Energy deck/g, '能量牌组'],
    [/Main Deck/g, '主牌组'],
    [/Stage Member/g, '舞台成员'],
    [/ overplayed onto (.+)\.$/, ' 叠放在 $1 上。'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      return ' 将 ' + card + ' 打到' + (SLOT_ZH[slot] || slot) + '区域。';
    }],
  ];

  var EFFECT_RULES_ZH = [
    [/drew a card\./, '抽了1张卡。'],
    [/drew (.+)\./, '抽了 $1。'],
    [/discarded a card\./, '弃置了1张卡。'],
    [/put (.+) into the Waiting Room\./, '将 $1 放入等候室。'],
    [/put a card into the Waiting Room\./, '将1张卡放入等候室。'],
    [/optional Live Start \(choose\)\./, '可选的Live开始（选择）。'],
    [/optional Live Start effect \(choose\)\./, '可选的Live开始效果（选择）。'],
    [/Live Success choice\./, 'Live成功选择。'],
  ];

  function clearLogNameCache() {
    namePairs = null;
    namePairsKo = null;
    namePairsZh = null;
  }

  function buildNamePairs(catalog) {
    if (!catalog) return [];
    var pairs = [];
    var seen = Object.create(null);
    Object.keys(catalog).forEach(function (no) {
      var c = catalog[no];
      if (!c) return;
      var en = String(c.name_en || '').trim();
      var jp = String(c.name || '').trim();
      if (!en || !jp || en === jp) return;
      var key = en.toLowerCase();
      if (seen[key]) return;
      seen[key] = 1;
      pairs.push([en, jp]);
    });
    pairs.sort(function (a, b) { return b[0].length - a[0].length; });
    return pairs;
  }

  function getNamePairs(catalog) {
    if (!namePairs) namePairs = buildNamePairs(catalog);
    return namePairs;
  }

  function replaceCardNames(msg, catalog) {
    if (!msg) return msg;
    getNamePairs(catalog).forEach(function (pair) {
      if (msg.indexOf(pair[0]) === -1) return;
      msg = msg.split(pair[0]).join(pair[1]);
    });
    return msg;
  }

  /**
   * English name → Korean name pairs, built from `LLTCG_I18N.cardLocaleName()`
   * (KO_NAME_MAP / KO_SONG_MAP). Cards with no Korean mapping fall back to their
   * English name inside `cardLocaleName`, so they are skipped here (no-op).
   */
  function buildNamePairsKo(catalog) {
    if (!catalog) return [];
    var i18n = global.LLTCG_I18N;
    if (!i18n || typeof i18n.cardLocaleName !== 'function') return [];
    var pairs = [];
    var seen = Object.create(null);
    Object.keys(catalog).forEach(function (no) {
      var c = catalog[no];
      if (!c) return;
      var en = String(c.name_en || '').trim();
      if (!en) return;
      var ko = String(i18n.cardLocaleName(c) || '').trim();
      if (!ko || ko === en) return;
      var key = en.toLowerCase();
      if (seen[key]) return;
      seen[key] = 1;
      pairs.push([en, ko]);
    });
    pairs.sort(function (a, b) { return b[0].length - a[0].length; });
    return pairs;
  }

  function getNamePairsKo(catalog) {
    if (!namePairsKo) namePairsKo = buildNamePairsKo(catalog);
    return namePairsKo;
  }

  function replaceCardNamesKo(msg, catalog) {
    if (!msg) return msg;
    getNamePairsKo(catalog).forEach(function (pair) {
      if (msg.indexOf(pair[0]) === -1) return;
      msg = msg.split(pair[0]).join(pair[1]);
    });
    return msg;
  }

  function buildNamePairsZh(catalog) {
    if (!catalog) return [];
    var i18n = global.LLTCG_I18N;
    if (!i18n || typeof i18n.cardLocaleName !== 'function') return [];
    var pairs = [];
    var seen = Object.create(null);
    Object.keys(catalog).forEach(function (no) {
      var c = catalog[no];
      if (!c) return;
      var en = String(c.name_en || '').trim();
      if (!en) return;
      var zh = String(i18n.cardLocaleName(c) || '').trim();
      if (!zh || zh === en) return;
      var key = en.toLowerCase();
      if (seen[key]) return;
      seen[key] = 1;
      pairs.push([en, zh]);
    });
    pairs.sort(function (a, b) { return b[0].length - a[0].length; });
    return pairs;
  }

  function getNamePairsZh(catalog) {
    if (!namePairsZh) namePairsZh = buildNamePairsZh(catalog);
    return namePairsZh;
  }

  function replaceCardNamesZh(msg, catalog) {
    if (!msg) return msg;
    getNamePairsZh(catalog).forEach(function (pair) {
      if (msg.indexOf(pair[0]) === -1) return;
      msg = msg.split(pair[0]).join(pair[1]);
    });
    return msg;
  }

  function replaceSkillBrackets(msg, map) {
    var brackets = map || SKILL_BRACKETS;
    return msg.replace(/\[([^\]]+)\]/g, function (full, inner) {
      var trimmed = inner.trim();
      if (brackets[trimmed]) return '[' + brackets[trimmed] + ']';
      return full;
    });
  }

  function applyRules(msg, rules) {
    var out = msg;
    rules.forEach(function (rule) {
      var re = rule[0];
      var rep = rule[1];
      if (typeof rep === 'function') {
        out = out.replace(re, rep);
      } else {
        out = out.replace(re, rep);
      }
    });
    return out;
  }

  function localizePromptTextJa(msg, catalog) {
    if (!msg) return msg;
    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = replaceCardNames(out, catalog);
    out = replaceSkillBrackets(out);
    out = applyRules(out, PHRASE_RULES);
    out = applyRules(out, EFFECT_RULES);
    return out;
  }

  function localizePromptTextEs(msg, catalog) {
    if (!msg) return msg;
    var out = String(msg);
    out = applyRules(out, PHRASE_RULES_ES);
    out = applyRules(out, EFFECT_RULES_ES);
    return out;
  }

  function localizePromptTextKo(msg, catalog) {
    if (!msg) return msg;
    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = replaceCardNamesKo(out, catalog);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_KO);
    out = applyRules(out, PHRASE_RULES_KO);
    out = applyRules(out, EFFECT_RULES_KO);
    return out;
  }

  function localizePromptTextZh(msg, catalog) {
    if (!msg) return msg;
    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = replaceCardNamesZh(out, catalog);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_ZH);
    out = applyRules(out, PHRASE_RULES_ZH);
    out = applyRules(out, EFFECT_RULES_ZH);
    return out;
  }

  function localizePromptText(msg, catalog) {
    if (!msg) return msg;
    var i18n = global.LLTCG_I18N;
    if (!i18n) return msg;
    var loc = i18n.getLocale();
    if (loc === 'en') return msg;
    if (loc === 'ja') return localizePromptTextJa(msg, catalog);
    if (loc === 'es') return localizePromptTextEs(msg, catalog);
    if (loc === 'ko') return localizePromptTextKo(msg, catalog);
    if (loc === 'zh') return localizePromptTextZh(msg, catalog);
    return msg;
  }

  function localizeLogMessageJa(msg, catalog) {
    if (!msg) return msg;

    var exact = translateExact(msg);
    if (exact != null) return exact;

    var structured = translateStructuredLine(msg);
    if (structured != null) return translateOpponentLabels(structured);

    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = applyRules(out, STRUCTURAL_PHRASE_RULES);
    out = translateOpponentLabels(out);
    out = replaceCardNames(out, catalog);
    out = replaceSkillBrackets(out);
    out = applyRules(out, PHRASE_RULES);
    out = applyRules(out, EFFECT_RULES);
    out = translateOpponentLabels(out);
    return out;
  }

  function localizeLogMessageEs(msg, catalog) {
    if (!msg) return msg;

    var exact = translateExact(msg);
    if (exact != null) return exact;

    var structured = translateStructuredLineEs(msg);
    if (structured != null) return structured;

    var out = String(msg);
    out = applyRules(out, STRUCTURAL_PHRASE_RULES_ES);
    out = applyRules(out, PHRASE_RULES_ES);
    out = applyRules(out, EFFECT_RULES_ES);
    return out;
  }

  function localizeLogMessageKo(msg, catalog) {
    if (!msg) return msg;

    var exact = translateExact(msg);
    if (exact != null) return exact;

    var structured = translateStructuredLineKo(msg);
    if (structured != null) return structured;

    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = applyRules(out, STRUCTURAL_PHRASE_RULES_KO);
    out = replaceCardNamesKo(out, catalog);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_KO);
    out = applyRules(out, PHRASE_RULES_KO);
    out = applyRules(out, EFFECT_RULES_KO);
    return out;
  }

  function localizeLogMessageZh(msg, catalog) {
    if (!msg) return msg;

    var exact = translateExact(msg);
    if (exact != null) return exact;

    var structured = translateStructuredLineZh(msg);
    if (structured != null) return structured;

    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = applyRules(out, STRUCTURAL_PHRASE_RULES_ZH);
    out = replaceCardNamesZh(out, catalog);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_ZH);
    out = applyRules(out, PHRASE_RULES_ZH);
    out = applyRules(out, EFFECT_RULES_ZH);
    return out;
  }

  function localizeLogMessage(msg, catalog) {
    if (!msg) return msg;
    var i18n = global.LLTCG_I18N;
    if (!i18n) return msg;
    var loc = i18n.getLocale();
    if (loc === 'en') return msg;
    if (loc === 'ja') return localizeLogMessageJa(msg, catalog);
    if (loc === 'es') return localizeLogMessageEs(msg, catalog);
    if (loc === 'ko') return localizeLogMessageKo(msg, catalog);
    if (loc === 'zh') return localizeLogMessageZh(msg, catalog);
    return msg;
  }

  global.LLTCG_LOG_I18N = {
    clearLogNameCache: clearLogNameCache,
    localizeLogMessage: localizeLogMessage,
    localizePromptText: localizePromptText,
  };
})(typeof window !== 'undefined' ? window : globalThis);
