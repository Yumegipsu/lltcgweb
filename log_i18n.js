/* Client-side game log localization (English server log → ja / es display) */
(function (global) {
  'use strict';

  var namePairs = null;

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

  var SLOT_JA = { left: '左', center: 'センター', right: '右' };

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

  function clearLogNameCache() {
    namePairs = null;
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

  function replaceSkillBrackets(msg) {
    return msg.replace(/\[([^\]]+)\]/g, function (full, inner) {
      var trimmed = inner.trim();
      if (SKILL_BRACKETS[trimmed]) return '[' + SKILL_BRACKETS[trimmed] + ']';
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

  function localizePromptText(msg, catalog) {
    if (!msg) return msg;
    var i18n = global.LLTCG_I18N;
    if (!i18n) return msg;
    var loc = i18n.getLocale();
    if (loc === 'en') return msg;
    if (loc === 'ja') return localizePromptTextJa(msg, catalog);
    if (loc === 'es') return localizePromptTextEs(msg, catalog);
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

  function localizeLogMessage(msg, catalog) {
    if (!msg) return msg;
    var i18n = global.LLTCG_I18N;
    if (!i18n) return msg;
    var loc = i18n.getLocale();
    if (loc === 'en') return msg;
    if (loc === 'ja') return localizeLogMessageJa(msg, catalog);
    if (loc === 'es') return localizeLogMessageEs(msg, catalog);
    return msg;
  }

  global.LLTCG_LOG_I18N = {
    clearLogNameCache: clearLogNameCache,
    localizeLogMessage: localizeLogMessage,
    localizePromptText: localizePromptText,
  };
})(typeof window !== 'undefined' ? window : globalThis);
