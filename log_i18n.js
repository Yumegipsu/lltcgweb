/* Client-side game log / skill-prompt localization (English server text → ja / es / ko / zh / th / pt) */
(function (global) {
  'use strict';

  var namePairs = null;
  var namePairsKo = null;
  var namePairsZh = null;
  var namePairsTh = null;

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
    'Twice per turn': 'ターン2回',
    'Twice per Turn': 'ターン2回',
    'Center': 'センター',
    'Yell': 'エール',
  };

  var SKILL_BRACKETS_ES = {
    'On Enter': 'Al entrar',
    'On Leave': 'Al salir',
    'Live Start': 'Inicio de Live',
    'Live Success': 'Éxito de Live',
    'Activated': 'Activada',
    'Always': 'Siempre',
    'Automatic': 'Automático',
    'Auto': 'Automático',
    'Once per turn': 'Una vez por turno',
    'Twice per turn': 'Dos veces por turno',
    'Twice per Turn': 'Dos veces por turno',
    'Center': 'Centro',
    'Yell': 'Yell',
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
    'Twice per turn': '턴당 2회',
    'Twice per Turn': '턴당 2회',
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
    'Twice per Turn': '每回合2次',
    'Center': '中央',
    'Yell': 'Yell',
    'Left Side': '左侧',
    'Right Side': '右侧',
  };

  var SKILL_BRACKETS_TH = {
    'On Enter': 'เมื่อเข้าสนาม',
    'On Leave': 'เมื่อออกจากสนาม',
    'Live Start': 'เริ่ม Live',
    'Live Success': 'Live สำเร็จ',
    'Activated': 'เปิดใช้',
    'Always': 'ต่อเนื่อง',
    'Continuous': 'ต่อเนื่อง',
    'Automatic': 'อัตโนมัติ',
    'Auto': 'อัตโนมัติ',
    'Once per turn': 'เทิร์นละ 1 ครั้ง',
    'Twice per turn': 'เทิร์นละ 2 ครั้ง',
    'Twice per Turn': 'เทิร์นละ 2 ครั้ง',
    'Center': 'เซ็นเตอร์',
    'Yell': 'Yell',
    'Left Side': 'ฝั่งซ้าย',
    'Right Side': 'ฝั่งขวา',
  };

  var SLOT_JA = { left: '左', center: 'センター', right: '右' };

  var SLOT_KO = { left: '왼쪽', center: '센터', right: '오른쪽' };
  var SLOT_ZH = { left: '左侧', center: '中央', right: '右侧' };
  var SLOT_TH = { left: 'ซ้าย', center: 'เซ็นเตอร์', right: 'ขวา' };
  var SKILL_BRACKETS_PT = {
    'On Enter': 'Ao Entrar',
    'On Leave': 'Ao Sair',
    'Live Start': 'Início de Live',
    'Live Success': 'Live Bem-Sucedida',
    'Activated': 'Ativado',
    'Always': 'Sempre',
    'Automatic': 'Automático',
    'Auto': 'Automático',
    'Once per turn': 'Uma vez por turno',
    'Twice per turn': 'Duas vezes por turno',
    'Twice per Turn': 'Duas vezes por turno',
    'Center': 'Centro',
    'Yell': 'Grito',
    'Left Side': 'Lado Esquerdo',
    'Right Side': 'Lado Direito',
  };

  var SLOT_PT = { left: 'esquerda', center: 'centro', right: 'direita' };


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

  function translateStructuredLinePt(msg) {
    var m;

    m = msg.match(/^(.+?) performed Live! Blades: (\d+) \| Hearts: \[([^\]]*)\] \| Live success: (\d+) \| Failed: (\d+)( \| Round: failed \(not all Lives succeeded\))?$/);
    if (m) {
      var roundNote = m[6] ? ' | Rodada falhou (nem todas as Lives tiveram sucesso)' : '';
      return m[1] + ' realizou Live! Blades: ' + m[2] +
        ' | Corações: [' + m[3] + ']' +
        ' | Live bem-sucedida: ' + m[4] + ' | Falhas: ' + m[5] + roundNote;
    }

    m = msg.match(/^Live Scores: (.+?) = (\d+) \| (.+?) = (\d+)$/);
    if (m) return 'Pontuações Live: ' + m[1] + ' = ' + m[2] + ' | ' + m[3] + ' = ' + m[4];

    m = msg.match(/^(.+?) wins the Live — (.+) failed\.$/);
    if (m) return m[1] + ' venceu a Live — ' + m[2] + ' falhou.';

    m = msg.match(/^(.+?) wins this Live! "(.+)" added to successes\.$/);
    if (m) return m[1] + ' venceu esta Live! "' + m[2] + '" adicionada aos bem-sucedidos.';

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
    if (m) return '🪙 Cara ou coroa: ' + m[1] + ' venceu — primeiro jogador escolhido automaticamente (tempo esgotado).';

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

  function translateStructuredLineTh(msg) {
    var m;

    m = msg.match(/^(.+?) performed Live! Blades: (\d+) \| Hearts: \[([^\]]*)\] \| Live success: (\d+) \| Failed: (\d+)( \| Round: failed \(not all Lives succeeded\))?$/);
    if (m) {
      var roundNote = m[6] ? ' | รอบล้มเหลว (Live ไม่สำเร็จครบทุกใบ)' : '';
      return m[1] + ' ทำการแสดง Live! เบลด: ' + m[2] +
        ' | หัวใจ: [' + m[3] + ']' +
        ' | Live สำเร็จ: ' + m[4] + ' | ล้มเหลว: ' + m[5] + roundNote;
    }

    m = msg.match(/^Live Scores: (.+?) = (\d+) \| (.+?) = (\d+)$/);
    if (m) return 'คะแนน Live: ' + m[1] + ' = ' + m[2] + ' | ' + m[3] + ' = ' + m[4];

    m = msg.match(/^(.+?) wins the Live — (.+) failed\.$/);
    if (m) return m[1] + ' ชนะ Live — ' + m[2] + ' ล้มเหลว';

    m = msg.match(/^(.+?) wins this Live! "(.+)" added to successes\.$/);
    if (m) return m[1] + ' ชนะ Live นี้! "' + m[2] + '" ถูกเพิ่มเป็น Live สำเร็จ';

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
    if (m) return '🪙 โยนเหรียญ: ' + m[1] + ' ชนะ — หมดเวลา จึงเลือกผู้เล่นก่อนอัตโนมัติ';

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
    [/ mulliganed: redrew (\d+) card\(s\)\.$/, ' マリガン：$1枚引き直し。'],
    [/ mulliganed: kept hand\.$/, ' マリガン：手札キープ。'],
    [/^Mulligan — (.+) redrew (\d+), (.+) redrew (\d+)\.$/, 'マリガン — $1 が$2枚、$3 が$4枚引き直し。'],
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
    [/ mulliganed: redrew (\d+) card\(s\)\.$/, ' hizo muligan: volvió a robar $1 carta(s).'],
    [/ mulliganed: kept hand\.$/, ' hizo muligan: conservó la mano.'],
    [/^Mulligan — (.+) redrew (\d+), (.+) redrew (\d+)\.$/, 'Muligan — $1 robó de nuevo $2, $3 robó de nuevo $4.'],
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
    [/ mulliganed: redrew (\d+) card\(s\)\.$/, ' 멀리건: $1장 다시 뽑음.'],
    [/ mulliganed: kept hand\.$/, ' 멀리건: 패 유지.'],
    [/^Mulligan — (.+) redrew (\d+), (.+) redrew (\d+)\.$/, '멀리건 — $1 $2장, $3 $4장 다시 뽑음.'],
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
    [/ mulliganed: redrew (\d+) card\(s\)\.$/, ' 调度：重抽 $1 张。'],
    [/ mulliganed: kept hand\.$/, ' 调度：保留手牌。'],
    [/^Mulligan — (.+) redrew (\d+), (.+) redrew (\d+)\.$/, '调度 — $1 重抽 $2，$3 重抽 $4。'],
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

  var STRUCTURAL_PHRASE_RULES_TH = [
    [/ — End Main Phase\.$/, ' — จบเฟสหลัก'],
    [/ completed mulligan\.$/, ' มัลลิแกนเสร็จแล้ว'],
    [/ mulliganed: redrew (\d+) card\(s\)\.$/, ' มัลลิแกน: จั่วใหม่ $1 ใบ'],
    [/ mulliganed: kept hand\.$/, ' มัลลิแกน: เก็บมือ'],
    [/^Mulligan — (.+) redrew (\d+), (.+) redrew (\d+)\.$/, 'มัลลิแกน — $1 จั่วใหม่ $2, $3 จั่วใหม่ $4'],
    [/ resigned\. (.+) wins!$/, ' ยอมแพ้ $1 ชนะ!'],
    [/ WINS with 3 successful Lives!$/, ' ชนะด้วย Live สำเร็จ 3 ครั้ง!'],
    [/ used Baton Touch! Cost reduced to (\d+)\.$/, ' ใช้บาตองทัช! ต้นทุนลดเหลือ $1'],
    [/ used second Baton Touch! Cost reduced to (\d+)\.$/, ' ใช้บาตองทัชครั้งที่สอง! ต้นทุนลดเหลือ $1'],
    [/ placed (\d+) card\(s\) face-down in storage \((\d+)\/3\)\.$/, ' วางการ์ด $1 ใบคว่ำหน้าในที่เก็บ ($2/3)'],
    [/ placed card\(s\) in Live storage\.$/, ' วางการ์ดในที่เก็บ Live'],
    [/ — locked in LIVE selection \((\d+) card\(s\) in storage\)\.$/, ' — ล็อกการเลือก Live แล้ว (ที่เก็บ $1 ใบ)'],
    [/ — locked in LIVE selection\.$/, ' — ล็อกการเลือก Live แล้ว'],
    [/ — Draw Phase: could not draw \(deck and Waiting Room empty\)\.$/, ' — เฟสจั่ว: จั่วไม่ได้ (เด็คและห้องรอว่าง)'],
    [/ — Draw Phase\.$/, ' — เฟสจั่ว'],
    [/ — Active Phase: Energy and Members refreshed\.$/, ' — เฟสแอคทีฟ: พลังงานและสมาชิกถูกรีเฟรชแล้ว'],
    [/ — Energy Phase: storage full \((\d+)\/(\d+)\), no Energy added\.$/, ' — เฟสพลังงาน: ที่เก็บเต็ม ($1/$2) ไม่ได้เพิ่มพลังงาน'],
    [/ — Energy Phase: no cards left in Energy deck\.$/, ' — เฟสพลังงาน: ไม่มีการ์ดเหลือในเด็คพลังงาน'],
    [/ — Energy Phase: placed 1 Energy in storage \((\d+)\/(\d+)\)\.$/, ' — เฟสพลังงาน: วางพลังงาน 1 ใบในที่เก็บ ($1/$2)'],
    [/ — Main Phase time expired \(auto end\)\.$/, ' — เฟสหลักหมดเวลา (จบอัตโนมัติ)'],
    [/ — LIVE Phase time expired \(auto lock-in\)\.$/, ' — เฟส Live หมดเวลา (ล็อกอัตโนมัติ)'],
    [/^(.+?)(?:'s|') Live Phase\.$/, 'เฟส Live ของ $1'],
    [/ — \[([^\]]+)\] drew (\d+) \(Active → Wait\)\.$/, ' — [$1] จั่ว $2 (แอคทีฟ → Wait)'],
    [/ — \[([^\]]+)\] optional skill skipped\.$/, ' — [$1] ข้ามสกิลทางเลือก'],
    [/ — \[([^\]]+)\] activated\.$/, ' — [$1] เปิดใช้'],
    [/ — \[([^\]]+)\] Live Start skipped\.$/, ' — [$1] ข้ามเริ่ม Live'],
    [/ — \[([^\]]+)\] Live Success skipped\.$/, ' — [$1] ข้าม Live สำเร็จ'],
    [/Live SUCCESS/, 'Live สำเร็จ'],
    [/Live FAIL/, 'Live ล้มเหลว'],
    [/Live failed/, 'Live ล้มเหลว'],
    [/Live succeeded/, 'Live สำเร็จ'],
    [/^(.+)'s turn — Main Phase \(Active · Energy · Draw complete\)\.$/, 'เทิร์นของ $1 — เฟสหลัก (แอคทีฟ · พลังงาน · จั่ว เสร็จแล้ว)'],
    [/^(.+) turn — Main Phase \(Active · Energy · Draw complete\)\.$/, 'เทิร์นของ $1 — เฟสหลัก (แอคทีฟ · พลังงาน · จั่ว เสร็จแล้ว)'],
    [/^(.+) turn — Main Phase…$/, 'เทิร์นของ $1 — เฟสหลัก…'],
    [/^🪙 Coin flip: (.+) won and chose to go first!$/, '🪙 โยนเหรียญ: $1 ชนะ — เลือกไปก่อน!'],
    [/^🪙 Coin flip: (.+) won and chose (.+) to go first!$/, '🪙 โยนเหรียญ: $1 ชนะ — เลือกให้ $2 ไปก่อน!'],
    [/^🎉 (.+) WINS with 3 successful Lives!$/, '🎉 $1 ชนะด้วย Live สำเร็จ 3 ครั้ง!'],
    [/ disconnected\. (.+) wins!$/, ' ตัดการเชื่อมต่อ $1 ชนะ!'],
    [/ had no card in hand to discard\.$/, ' ไม่มีการ์ดในมือให้ทิ้ง'],
    [/ had no cards in hand to discard\.$/, ' ไม่มีการ์ดในมือให้ทิ้ง'],
    [/ drew (\d+) but had no cards in hand to discard\.$/, ' จั่ว $1 แต่ไม่มีการ์ดในมือให้ทิ้ง'],
    [/ is performing Live with (.+)\.$/, ' กำลังแสดง Live ด้วย $1'],
    [/Both players put (\d+) cards? into the Waiting Room\.$/, 'ผู้เล่นทั้งสองวางการ์ด $1 ใบลงห้องรอ'],
    [/Both players drew \(([^)]+)\)\.$/, 'ผู้เล่นทั้งสองจั่วแล้ว ($1)'],
  ];

  /** Term replacement for Spanish (order matters; Baton Touch stays English). */
  var PHRASE_RULES_ES = [
    [/Success Live card storage/g, 'almacenamiento de Live exitoso'],
    [/Live storage/g, 'almacenamiento de Live'],
    [/Success Live/g, 'Live exitoso'],
    [/Waiting Room/g, 'Sala de espera'],
    [/Energy deck/g, 'mazo de Energía'],
    [/Main Deck/g, 'mazo principal'],
    [/from your hand/g, 'de tu mano'],
    [/your hand/g, 'tu mano'],
    [/your deck/g, 'tu mazo'],
    [/your Stage/g, 'tu Escenario'],
    [/Member card/g, 'carta de Miembro'],
    [/Live card/g, 'carta Live'],
    [/Energy/g, 'Energía'],
    [/Member/g, 'Miembro'],
    [/ overplayed onto (.+)\.$/, ' sobrescribió sobre $1.'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      var slots = { left: 'izquierda', center: 'centro', right: 'derecha' };
      return ' jugó ' + card + ' en el área ' + (slots[slot] || slot) + '.';
    }],
  ];

  /** Term replacement for Brazilian Portuguese (order matters). */
  var PHRASE_RULES_PT = [
    [/Success Live card storage/g, 'Zona de Lives Bem-Sucedidas'],
    [/Live storage/g, 'Zona de Live'],
    [/Success Live/g, 'Live Bem-Sucedida'],
    [/Waiting Room/g, 'Sala de Espera'],
    [/Energy deck/g, 'Deck de Energia'],
    [/Main Deck/g, 'Deck Principal'],
    [/from your hand/g, 'da sua mão'],
    [/your hand/g, 'sua mão'],
    [/your deck/g, 'seu deck'],
    [/your Stage/g, 'seu Palco'],
    [/Member card/g, 'carta Membro'],
    [/Live card/g, 'carta Live'],
    [/Energy/g, 'Energia'],
    [/Member/g, 'Membro'],
    [/ overplayed onto (.+)\.$/, ' sobrescreveu em $1.'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      return ' jogou ' + card + ' na área ' + (SLOT_PT[slot] || slot) + '.';
    }],
  ];

  /** Core structural phrases for Brazilian Portuguese. */
  var STRUCTURAL_PHRASE_RULES_PT = [
    [/ — End Main Phase\.$/, ' — Fim da Fase Principal.'],
    [/ completed mulligan\.$/, ' concluiu o mulligan.'],
    [/ mulliganed: redrew (\d+) card\(s\)\.$/, ' fez mulligan: comprou novamente $1 carta(s).'],
    [/ mulliganed: kept hand\.$/, ' fez mulligan: manteve a mão.'],
    [/^Mulligan — (.+) redrew (\d+), (.+) redrew (\d+)\.$/, 'Mulligan — $1 comprou novamente $2, $3 comprou novamente $4.'],
    [/ resigned\. (.+) wins!$/, ' desistiu. $1 venceu!'],
    [/ WINS with 3 successful Lives!$/, ' VENCEU com 3 Lives Bem-Sucedidas!'],
    [/ used Baton Touch! Cost reduced to (\d+)\.$/, ' usou Passe de Bastão! Custo reduzido para $1.'],
    [/ used second Baton Touch! Cost reduced to (\d+)\.$/, ' usou um segundo Passe de Bastão! Custo reduzido para $1.'],
    [/ placed (\d+) card\(s\) face-down in storage \((\d+)\/3\)\.$/, ' colocou $1 carta(s) viradas para baixo na zona ($2/3).'],
    [/ placed card\(s\) in Live storage\.$/, ' colocou carta(s) na zona de Live.'],
    [/ — locked in LIVE selection \((\d+) card\(s\) in storage\)\.$/, ' — seleção de Live confirmada ($1 carta(s) na zona).'],
    [/ — locked in LIVE selection\.$/, ' — seleção de Live confirmada.'],
    [/ — Draw Phase: could not draw \(deck and Waiting Room empty\)\.$/, ' — Fase de Compra: não pôde comprar (deck e Sala de Espera vazios).'],
    [/ — Draw Phase\.$/, ' — Fase de Compra.'],
    [/ — Active Phase: Energy and Members refreshed\.$/, ' — Fase Ativa: Energia e Membros renovados.'],
    [/ — Energy Phase: storage full \((\d+)\/(\d+)\), no Energy added\.$/, ' — Fase de Energia: zona cheia ($1/$2), nenhuma Energia adicionada.'],
    [/ — Energy Phase: no cards left in Energy deck\.$/, ' — Fase de Energia: não restam cartas no deck de Energia.'],
    [/ — Energy Phase: placed 1 Energy in storage \((\d+)\/(\d+)\)\.$/, ' — Fase de Energia: colocou 1 Energia na zona ($1/$2).'],
    [/ — Main Phase time expired \(auto end\)\.$/, ' — Fase Principal: tempo esgotado (fim automático).'],
    [/ — LIVE Phase time expired \(auto lock-in\)\.$/, ' — Fase de LIVE: tempo esgotado (confirmação automática).'],
    [/^(.+?)(?:'s|') Live Phase\.$/, 'Fase de Live de $1.'],
    [/ — \[([^\]]+)\] drew (\d+) \(Active → Wait\)\.$/, ' — [$1] comprou $2 (Ativo → Repouso).'],
    [/ — \[([^\]]+)\] optional skill skipped\.$/, ' — [$1] habilidade opcional ignorada.'],
    [/ — \[([^\]]+)\] activated\.$/, ' — [$1] ativada.'],
    [/ — \[([^\]]+)\] Live Start skipped\.$/, ' — [$1] Início de Live ignorado.'],
    [/ — \[([^\]]+)\] Live Success skipped\.$/, ' — [$1] Live Bem-Sucedida ignorada.'],
    [/Live SUCCESS/, 'Live BEM-SUCEDIDA'],
    [/Live FAIL/, 'Live FALHOU'],
    [/Live failed/, 'Live falhou'],
    [/Live succeeded/, 'Live bem-sucedida'],
    [/^(.+)'s turn — Main Phase \(Active · Energy · Draw complete\)\.$/, 'Turno de $1 — Fase Principal (Ativa · Energia · Compra concluídas).'],
    [/^(.+) turn — Main Phase \(Active · Energy · Draw complete\)\.$/, 'Turno de $1 — Fase Principal (Ativa · Energia · Compra concluídas).'],
    [/^(.+) turn — Main Phase…$/, 'Turno de $1 — Fase Principal…'],
    [/^🪙 Coin flip: (.+) won and chose to go first!$/, '🪙 Cara ou coroa: $1 venceu e escolheu ir primeiro!'],
    [/^🪙 Coin flip: (.+) won and chose (.+) to go first!$/, '🪙 Cara ou coroa: $1 venceu e escolheu $2 para ir primeiro!'],
    [/^🎉 (.+) WINS with 3 successful Lives!$/, '🎉 $1 VENCEU com 3 Lives Bem-Sucedidas!'],
    [/ disconnected\. (.+) wins!$/, ' desconectou. $1 venceu!'],
    [/ had no card in hand to discard\.$/, ' não tinha carta na mão para descartar.'],
    [/ had no cards in hand to discard\.$/, ' não tinha cartas na mão para descartar.'],
    [/ drew (\d+) but had no cards in hand to discard\.$/, ' comprou $1 mas não tinha cartas na mão para descartar.'],
    [/ is performing Live with (.+)\.$/, ' está realizando Live com $1.'],
    [/Both players put (\d+) cards? into the Waiting Room\.$/, 'Ambos os jogadores colocaram $1 carta(s) na Sala de Espera.'],
    [/Both players drew \(([^)]+)\)\.$/, 'Ambos os jogadores compraram ($1).'],
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
    [/from your hand/g, '손패에서'],
    [/your hand/g, '손패'],
    [/your deck/g, '덱'],
    [/your Stage/g, '스테이지'],
    [/Member card/g, '멤버 카드'],
    [/Live card/g, 'Live 카드'],
    [/Energy/g, '에너지'],
    [/Member/g, '멤버'],
    [/ overplayed onto (.+)\.$/, ' $1 위에 겹쳐 플레이함.'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      return ' ' + card + '를 ' + (SLOT_KO[slot] || slot) + ' 구역에 플레이함.';
    }],
  ];

  /** Shared yes/no skill-prompt templates (server pending_prompt.prompt). */
  var PROMPT_QUESTION_RULES_JA = [
    [/^Put 1 card from your hand into the Waiting Room: look at the top (\d+) cards of your deck, add 1 to your hand, and put the rest into the Waiting Room[?.]?$/,
      '手札1枚を控え室に置く：デッキの上から$1枚を見て、1枚を手札に加え、残りを控え室に置く。'],
    [/^Put 1 card from your hand into the Waiting Room: add 1 (.+?) from your Waiting Room to your hand[?.]?$/,
      '手札1枚を控え室に置く：控え室から$1を1枚手札に加える。'],
    [/^Put 1 card from your hand into the Waiting Room: add (\d+) Energy[?.]?$/,
      '手札1枚を控え室に置く：エネルギーを$1枚追加する。'],
    [/^Choose (up to )?(\d+) (Member|Live|card) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var type = kind === 'Member' ? 'メンバーカード' : kind === 'Live' ? 'ライブカード' : 'カード';
        return '控え室から' + (upTo ? count + '枚までの' : count + '枚の') + type + 'を選び、手札に加える。';
      }],
    [/^Choose a (?:matching )?(?:Member )?card from your Waiting Room to add to your hand\.?$/,
      '控え室から手札に加えるカードを1枚選んでください。'],
    [/^Choose (\d+) (.+?) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      '控え室から$2カードを$1枚選び、手札に加える。'],
    [/^Choose (up to )?(\d+) (Member |Live )?card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var type = kind === 'Member ' ? 'メンバーカード' : kind === 'Live ' ? 'ライブカード' : 'カード';
        return '控え室から' + (upTo ? count + '枚までの' : count + '枚の') + type + 'を選び、手札に加える。';
      }],
    [/^Choose 1 card from your Waiting Room to add to your hand \(the rest go to the Waiting Room\)\.?$/,
      '控え室からカードを1枚選んで手札に加え、残りを控え室に置く。'],
    [/^Choose 1 card revealed by Yell to add to your hand\.?$/, 'エールで公開したカードを1枚選び、手札に加える。'],
    [/^Choose (?:up to )?(\d+) Member(?:s)? on your Stage\.?$/, '自分のステージのメンバーを$1体まで選ぶ。'],
    [/^Choose (\d+) card(?:s|\(s\))? from your hand to (?:send to|put into) the Waiting Room\.?$/, '手札から$1枚選び、控え室に置く。'],
    [/^Discard (\d+) card(?:s|\(s\))? from your hand\.?$/, '手札を$1枚捨てる。'],
    [/^Look at the top (\d+) cards? of your deck\.?$/, 'デッキの上から$1枚を見る。'],
    [/^Choose (?:an effect|one effect|one):?$/, '効果を1つ選ぶ。'],
    [/^Choose a heart color\.?$/, 'ハートの色を選ぶ。'],
    [/^Choose (?:yourself|you) or your opponent\.?$/, '自分または相手を選ぶ。'],
    [/^Choose a Live card for Success Live\.?$/, '成功ライブにするライブカードを選ぶ。'],
    [/^Choose 1 card to add to your hand \(the rest go to the Waiting Room\)\.?$/, 'カードを1枚選んで手札に加え、残りを控え室に置く。'],
    [/^Ask your opponent: "(.+)"$/, '相手に質問：「$1」'],
  ];

  /**
   * Engine / client fallback "Choose …" prompts (group names like Aqours stay Latin).
   * Applied before per-locale PROMPT_QUESTION_RULES to avoid PHRASE_RULES word-swap leaks
   * (e.g. English "Choose" + Spanish "Miembro").
   */
  var PROMPT_CHOOSE_PATTERNS = [
    [/^Choose 1 (.+?) Member for \+Blade until Live ends\.?$/, {
      ja: 'ライブ終了まで$1メンバー1体を選び、刃+1。',
      es: 'Elige 1 Miembro $1 para +Blade hasta que termine el Live.',
      pt: 'Escolha 1 Membro $1 para +Blade até o fim desta Live.',
      ko: 'Live가 끝날 때까지 $1 멤버 1명을 선택해 +Blade.',
      zh: '选择1名$1成员，直到Live结束前+刃。',
      th: 'เลือกสมาชิก $1 1 คนเพื่อ +Blade จน Live จบ',
    }],
    [/^Choose 1 (.+?) Member for \+(\d+) Blade\.?$/, {
      ja: '$1メンバー1体を選び、刃+$2。',
      es: 'Elige 1 Miembro $1 para +$2 Blade.',
      pt: 'Escolha 1 Membro $1 para +$2 Blade.',
      ko: '$1 멤버 1명을 선택해 +$2 Blade.',
      zh: '选择1名$1成员，+刃$2。',
      th: 'เลือกสมาชิก $1 1 คนเพื่อ +Blade $2',
    }],
    [/^Choose 1 other (.+?) Member for \+Blade\.?$/, {
      ja: '他の$1メンバー1体を選び、刃+1。',
      es: 'Elige 1 otro Miembro $1 para +Blade.',
      pt: 'Escolha 1 outro Membro $1 para +Blade.',
      ko: '다른 $1 멤버 1명을 선택해 +Blade.',
      zh: '选择1名其他$1成员，+刃。',
      th: 'เลือกสมาชิก $1 อีก 1 คนเพื่อ +Blade',
    }],
    [/^Choose 1 other (.+?) Member for bonus hearts\.?$/, {
      ja: '他の$1メンバー1体を選び、ボーナスハート。',
      es: 'Elige 1 otro Miembro $1 para corazones extra.',
      pt: 'Escolha 1 outro Membro $1 para corações extras.',
      ko: '다른 $1 멤버 1명을 선택해 보너스 하트.',
      zh: '选择1名其他$1成员，获得额外心形。',
      th: 'เลือกสมาชิก $1 อีก 1 คนเพื่อหัวใจโบนัส',
    }],
    [/^Choose 1 other (.+?) Member to put into WR\.?$/, {
      ja: '他の$1メンバー1体を選び、控え室へ。',
      es: 'Elige 1 otro Miembro $1 para enviarlo a la Sala de espera.',
      pt: 'Escolha 1 outro Membro $1 para colocar na Sala de Espera.',
      ko: '다른 $1 멤버 1명을 선택해 대기실로 보냅니다.',
      zh: '选择1名其他$1成员放入等候室。',
      th: 'เลือกสมาชิก $1 อีก 1 คนเพื่อส่งไปห้องรอ',
    }],
    [/^Choose 1 other Liella! Member for \+Blade\.?$/, {
      ja: '他のLiella!メンバー1体を選び、刃+1。',
      es: 'Elige 1 otro Miembro Liella! para +Blade.',
      pt: 'Escolha 1 outro Membro Liella! para +Blade.',
      ko: '다른 Liella! 멤버 1명을 선택해 +Blade.',
      zh: '选择1名其他Liella!成员，+刃。',
      th: 'เลือกสมาชิก Liella! อีก 1 คนเพื่อ +Blade',
    }],
    [/^Choose 1 other Liella! Member for bonus hearts\.?$/, {
      ja: '他のLiella!メンバー1体を選び、ボーナスハート。',
      es: 'Elige 1 otro Miembro Liella! para corazones extra.',
      pt: 'Escolha 1 outro Membro Liella! para corações extras.',
      ko: '다른 Liella! 멤버 1명을 선택해 보너스 하트.',
      zh: '选择1名其他Liella!成员，获得额外心形。',
      th: 'เลือกสมาชิก Liella! อีก 1 คนเพื่อหัวใจโบนัส',
    }],
    [/^Choose 1 (.+?) Member to position-change\.?$/i, {
      ja: '$1メンバー1体を選び、ポジションチェンジ。',
      es: 'Elige 1 Miembro $1 para cambiar de posición.',
      pt: 'Escolha 1 Membro $1 para mudar de posição.',
      ko: '$1 멤버 1명을 선택해 포지션 체인지.',
      zh: '选择1名$1成员进行位置变更。',
      th: 'เลือกสมาชิก $1 1 คนเพื่อเปลี่ยนตำแหน่ง',
    }],
    [/^Choose 1 Saint Snow Member to position-change\.?$/i, {
      ja: 'Saint Snowメンバー1体を選び、ポジションチェンジ。',
      es: 'Elige 1 Miembro Saint Snow para cambiar de posición.',
      pt: 'Escolha 1 Membro Saint Snow para mudar de posição.',
      ko: 'Saint Snow 멤버 1명을 선택해 포지션 체인지.',
      zh: '选择1名Saint Snow成员进行位置变更。',
      th: 'เลือกสมาชิก Saint Snow 1 คนเพื่อเปลี่ยนตำแหน่ง',
    }],
    [/^Choose 1 Member in Wait to activate \(\+Blade until Live ends\)\.?$/, {
      ja: 'ウェイトのメンバー1体を選び、起動（ライブ終了まで刃+1）。',
      es: 'Elige 1 Miembro en Espera para activar (+Blade hasta que termine el Live).',
      pt: 'Escolha 1 Membro em Repouso para ativar (+Blade até o fim desta Live).',
      ko: 'Wait 상태 멤버 1명을 선택해 발동(+Blade, Live 종료까지).',
      zh: '选择1名Wait成员发动（直到Live结束前+刃）。',
      th: 'เลือกสมาชิกใน Wait 1 คนเพื่อเปิดใช้ (+Blade จน Live จบ)',
    }],
    [/^Choose 1 Stage Member to gain \+(\d+) Blade until this Live ends\.?$/, {
      ja: 'ステージのメンバー1体を選び、このライブ終了まで刃+$1。',
      es: 'Elige 1 Miembro en el Escenario para ganar +$1 Blade hasta que termine este Live.',
      pt: 'Escolha 1 Membro no Palco para ganhar +$1 Blade até o fim desta Live.',
      ko: '스테이지 멤버 1명을 선택해 이번 Live 종료까지 +$1 Blade.',
      zh: '选择1名舞台成员，直到本次Live结束前+刃$1。',
      th: 'เลือกสมาชิกบนเวที 1 คนเพื่อ +Blade $1 จน Live นี้จบ',
    }],
    [/^Choose 1 active Member on your Stage to put into Wait\.?$/, {
      ja: 'ステージのアクティブなメンバー1体を選び、ウェイトに。',
      es: 'Elige 1 Miembro activo en tu Escenario para ponerlo en Espera.',
      pt: 'Escolha 1 Membro ativo no seu Palco para colocar em Repouso.',
      ko: '스테이지의 액티브 멤버 1명을 선택해 Wait로 보냅니다.',
      zh: '选择你舞台上1名活跃成员放入Wait。',
      th: 'เลือกสมาชิก Active บนเวที 1 คนเพื่อใส่ Wait',
    }],
    [/^Choose 1 Member on your Stage\.?$/, {
      ja: '自分のステージのメンバー1体を選ぶ。',
      es: 'Elige 1 Miembro en tu Escenario.',
      pt: 'Escolha 1 Membro no seu Palco.',
      ko: '자신의 스테이지에서 멤버 1명을 선택합니다.',
      zh: '选择你舞台上的1名成员。',
      th: 'เลือกสมาชิกบนเวที 1 คน',
    }],
    [/^Choose a Member on your Stage\.?$/, {
      ja: '自分のステージのメンバー1体を選ぶ。',
      es: 'Elige un Miembro en tu Escenario.',
      pt: 'Escolha um Membro no seu Palco.',
      ko: '자신의 스테이지에서 멤버를 선택합니다.',
      zh: '选择你舞台上的一名成员。',
      th: 'เลือกสมาชิกบนเวที',
    }],
    [/^Choose 1 Member to Position Change\.?$/i, {
      ja: 'メンバー1体を選び、ポジションチェンジ。',
      es: 'Elige 1 Miembro para cambiar de posición.',
      pt: 'Escolha 1 Membro para mudar de posição.',
      ko: '멤버 1명을 선택해 포지션 체인지.',
      zh: '选择1名成员进行位置变更。',
      th: 'เลือกสมาชิก 1 คนเพื่อเปลี่ยนตำแหน่ง',
    }],
    [/^Choose an area to Position Change into\.?$/i, {
      ja: 'ポジションチェンジ先のエリアを選ぶ。',
      es: 'Elige un área a la que cambiar de posición.',
      pt: 'Escolha uma área para mudar de posição.',
      ko: '포지션 체인지할 구역을 선택합니다.',
      zh: '选择要进行位置变更的区域。',
      th: 'เลือกพื้นที่เพื่อเปลี่ยนตำแหน่ง',
    }],
    [/^Choose an area to position-change this Member to\.?$/i, {
      ja: 'このメンバーのポジションチェンジ先エリアを選ぶ。',
      es: 'Elige un área a la que mover este Miembro.',
      pt: 'Escolha uma área mover este Membro.',
      ko: '이 멤버를 포지션 체인지할 구역을 선택합니다.',
      zh: '选择要将此成员移动到的区域。',
      th: 'เลือกพื้นที่เพื่อย้ายสมาชิกคนนี้',
    }],
    [/^Choose an area for this Member\.?$/, {
      ja: 'このメンバーのエリアを選ぶ。',
      es: 'Elige un área para este Miembro.',
      pt: 'Escolha uma área para este Membro.',
      ko: '이 멤버의 구역을 선택합니다.',
      zh: '为此成员选择一个区域。',
      th: 'เลือกพื้นที่สำหรับสมาชิกคนนี้',
    }],
    [/^Choose 1 (.+?) Member from your Waiting Room\.?$/, {
      ja: '控え室から$1メンバー1体を選ぶ。',
      es: 'Elige 1 Miembro $1 de tu Sala de espera.',
      pt: 'Escolha 1 Membro $1 da sua Sala de Espera.',
      ko: '대기실에서 $1 멤버 1명을 선택합니다.',
      zh: '从你的等候室选择1名$1成员。',
      th: 'เลือกสมาชิก $1 1 คนจากห้องรอ',
    }],
    [/^Choose 1 Member from your Waiting Room\.?$/, {
      ja: '控え室からメンバー1体を選ぶ。',
      es: 'Elige 1 Miembro de tu Sala de espera.',
      pt: 'Escolha 1 Membro da sua Sala de Espera.',
      ko: '대기실에서 멤버 1명을 선택합니다.',
      zh: '从你的等候室选择1名成员。',
      th: 'เลือกสมาชิก 1 คนจากห้องรอ',
    }],
    [/^Choose 1 Live card in your Live\.?$/, {
      ja: '自分のライブ置き場のライブカード1枚を選ぶ。',
      es: 'Elige 1 carta Live en tu Live.',
      pt: 'Escolha 1 carta Live na sua Live.',
      ko: 'Live에 있는 Live 카드 1장을 선택합니다.',
      zh: '选择你Live中的1张Live卡。',
      th: 'เลือกการ์ด Live 1 ใบใน Live ของคุณ',
    }],
    [/^Choose 1 Live card from your hand\.?$/, {
      ja: '手札からライブカード1枚を選ぶ。',
      es: 'Elige 1 carta Live de tu mano.',
      pt: 'Escolha 1 carta Live da sua mão.',
      ko: '손패에서 Live 카드 1장을 선택합니다.',
      zh: '从手牌选择1张Live卡。',
      th: 'เลือกการ์ด Live 1 ใบจากมือ',
    }],
    [/^Choose 1 Live card from your hand to reveal\.?$/, {
      ja: '手札からライブカード1枚を選び、公開する。',
      es: 'Elige 1 carta Live de tu mano para revelarla.',
      pt: 'Escolha 1 carta Live da sua mão para revelar.',
      ko: '손패에서 Live 카드 1장을 선택해 공개합니다.',
      zh: '从手牌选择1张Live卡并公开。',
      th: 'เลือกการ์ด Live 1 ใบจากมือเพื่อเปิดเผย',
    }],
    [/^Choose 1 Live from Waiting Room for Success\.?$/, {
      ja: '控え室から成功ライブ用のライブ1枚を選ぶ。',
      es: 'Elige 1 Live de la Sala de espera para el Live exitoso.',
      pt: 'Escolha 1 Live da Sala de Espera para ser colocada nas Lives Bem-Sucedidas.',
      ko: '대기실에서 성공 Live용 Live 1장을 선택합니다.',
      zh: '从等候室选择1张Live作为成功Live。',
      th: 'เลือก Live 1 ใบจากห้องรอสำหรับ Live สำเร็จ',
    }],
    [/^Choose 1 ability to activate\.?$/, {
      ja: '発動する能力を1つ選ぶ。',
      es: 'Elige 1 habilidad para activar.',
      pt: 'Escolha 1 habilidade para ativar.',
      ko: '발동할 능력 1개를 선택합니다.',
      zh: '选择1个要发动的能力。',
      th: 'เลือกความสามารถ 1 อย่างเพื่อเปิดใช้',
    }],
    [/^Position-change this Member\?$/i, {
      ja: 'このメンバーをポジションチェンジしますか？',
      es: '¿Cambiar de posición a este Miembro?',
      pt: 'Mudar de posição com este Membro?',
      ko: '이 멤버를 포지션 체인지하시겠습니까?',
      zh: '要对此成员进行位置变更吗？',
      th: 'เปลี่ยนตำแหน่งสมาชิกคนนี้ไหม?',
    }],
    [/^Optional Wait effect$/, {
      ja: '任意のウェイト効果',
      es: 'Efecto de Espera opcional',
      pt: 'Efeito de Repouso opcional',
      ko: '선택적 Wait 효과',
      zh: '可选Wait效果',
      th: 'เอฟเฟกต์ Wait ทางเลือก',
    }],
    [/^Choose a card\.?$/, {
      ja: 'カードを1枚選ぶ。',
      es: 'Elige una carta.',
      pt: 'Escolha uma carta.',
      ko: '카드를 선택합니다.',
      zh: '选择一张卡。',
      th: 'เลือกการ์ด',
    }],
    [/^Discard from hand\.?$/, {
      ja: '手札から捨てる。',
      es: 'Descarta de la mano.',
      pt: 'Descarte de sua mão.',
      ko: '손패에서 버립니다.',
      zh: '从手牌弃置。',
      th: 'ทิ้งจากมือ',
    }],
    [/^Choose 1 card from your Waiting Room to put on top of your deck\.?$/, {
      ja: '控え室からカード1枚を選び、デッキの上に置く。',
      es: 'Elige 1 carta de tu Sala de espera para ponerla en la parte superior de tu mazo.',
      pt: 'Escolha 1 carta da sua Sala de Espera para colocar no topo do seu deck.',
      ko: '대기실에서 카드 1장을 선택해 덱 위에 둡니다.',
      zh: '从等候室选择1张卡放到牌组顶。',
      th: 'เลือกการ์ด 1 ใบจากห้องรอเพื่อวางบนสุดของเด็ค',
    }],
    [/^Choose a card to send to the Waiting Room\.?$/, {
      ja: '控え室に送るカードを選ぶ。',
      es: 'Elige una carta para enviarla a la Sala de espera.',
      pt: 'Escolha uma carta para colocar na Sala de Espera.',
      ko: '대기실로 보낼 카드를 선택합니다.',
      zh: '选择一张卡放入等候室。',
      th: 'เลือกการ์ดเพื่อส่งไปห้องรอ',
    }],
    [/^Choose 1 card to send to the Waiting Room\.?$/, {
      ja: '控え室に送るカード1枚を選ぶ。',
      es: 'Elige 1 carta para enviarla a la Sala de espera.',
      pt: 'Escolha 1 carta para colocar na Sala de Espera.',
      ko: '대기실로 보낼 카드 1장을 선택합니다.',
      zh: '选择1张卡放入等候室。',
      th: 'เลือกการ์ด 1 ใบเพื่อส่งไปห้องรอ',
    }],
    [/^Choose card\(s\) to send to the Waiting Room\.?$/, {
      ja: '控え室に送るカードを選ぶ。',
      es: 'Elige carta(s) para enviarlas a la Sala de espera.',
      pt: 'Escolha carta(s) para colocar na Sala de Espera.',
      ko: '대기실로 보낼 카드를 선택합니다.',
      zh: '选择要放入等候室的卡。',
      th: 'เลือกการ์ดเพื่อส่งไปห้องรอ',
    }],
    [/^Choose a matching Member from your hand to stack under this Member\.?$/, {
      ja: '手札から一致するメンバー1体を選び、このメンバーの下に重ねる。',
      es: 'Elige un Miembro coincidente de tu mano para apilarlo bajo este Miembro.',
      pt: 'Escolha um Membro correspondente da sua mão para colocar debaixo deste Membro.',
      ko: '손패에서 일치하는 멤버를 선택해 이 멤버 아래에 쌓습니다.',
      zh: '从手牌选择1名匹配成员叠在此成员下方。',
      th: 'เลือกสมาชิกที่ตรงกันจากมือเพื่อวางใต้สมาชิกคนนี้',
    }],
    [/^Choose a Member from your Waiting Room to stack under this Member\.?$/, {
      ja: '控え室からメンバー1体を選び、このメンバーの下に重ねる。',
      es: 'Elige un Miembro de tu Sala de espera para apilarlo bajo este Miembro.',
      pt: 'Escolha um Membro da sua Sala de Espera para colocar debaixo deste Membro.',
      ko: '대기실에서 멤버를 선택해 이 멤버 아래에 쌓습니다.',
      zh: '从等候室选择1名成员叠在此成员下方。',
      th: 'เลือกสมาชิกจากห้องรอเพื่อวางใต้สมาชิกคนนี้',
    }],
    [/^Choose 1 card without looking\.?$/, {
      ja: '見ずにカード1枚を選ぶ。',
      es: 'Elige 1 carta sin mirar.',
      pt: 'Escolha 1 carta sem olhar.',
      ko: '보지 않고 카드 1장을 선택합니다.',
      zh: '不查看地选择1张卡。',
      th: 'เลือกการ์ด 1 ใบโดยไม่ดู',
    }],
    [/^Choose a Member with stacked Energy to return\.?$/, {
      ja: 'エネルギーが重なったメンバー1体を選び、返す。',
      es: 'Elige un Miembro con Energía apilada para devolverla.',
      pt: 'Escolha um Membro com Energia embaixo dele para retornar.',
      ko: '겹쳐진 에너지가 있는 멤버를 선택해 반환합니다.',
      zh: '选择1名有叠放能量的成员并返还。',
      th: 'เลือกสมาชิกที่มีพลังงานซ้อนเพื่อคืน',
    }],
    [/^Add to hand or play to an empty Stage area\?$/, {
      ja: '手札に加えるか、空のステージエリアにプレイしますか？',
      es: '¿Añadir a la mano o jugar en un área vacía del Escenario?',
      pt: 'Adicionar à mão ou jogar em uma área vazia do Palco?',
      ko: '손패에 추가하거나 빈 스테이지 구역에 플레이하시겠습니까?',
      zh: '加入手牌或打到一个空的舞台区域？',
      th: 'เพิ่มเข้ามือหรือเล่นลงพื้นที่เวทีว่าง?',
    }],
    [/^What do you like\?$/, {
      ja: '好きなものは？',
      es: '¿Qué te gusta?',
      pt: 'Do que você mais gosta?',
      ko: '무엇을 좋아하나요?',
      zh: '你喜欢什么？',
      th: 'คุณชอบอะไร?',
    }],
    [/^Choose 1 Kasumi Nakasu card revealed\.?$/, {
      ja: '公開された中野かすみのカード1枚を選ぶ。',
      es: 'Elige 1 carta de Kasumi Nakasu revelada.',
      pt: 'Escolha 1 carta Kasumi Nakasu revelada.',
      ko: '공개된 카스미 나카스 카드 1장을 선택합니다.',
      zh: '选择1张已公开的中须霞卡。',
      th: 'เลือกการ์ด Kasumi Nakasu ที่เปิดเผย 1 ใบ',
    }],
    [/^Choose another Live \(or confirm done\)\.?$/, {
      ja: '別のライブを選ぶ（または完了を確認）。',
      es: 'Elige otro Live (o confirma que terminaste).',
      pt: 'Escolha outra Live (ou confirme que terminou).',
      ko: '다른 Live를 선택하거나 완료를 확인합니다.',
      zh: '选择另一张Live（或确认完成）。',
      th: 'เลือก Live อื่น (หรือยืนยันว่าเสร็จแล้ว)',
    }],
    [/^Choose 1 heart color for Members that moved this turn\.?$/, {
      ja: 'このターンに移動したメンバーのハート色を1つ選ぶ。',
      es: 'Elige 1 color de corazón para los Miembros que se movieron este turno.',
      pt: 'Escolha 1 cor de coração para os Membros que se moveram este turno.',
      ko: '이번 턴에 이동한 멤버의 하트 색 1개를 선택합니다.',
      zh: '为本回合移动的成员选择1种心形颜色。',
      th: 'เลือกสีหัวใจ 1 สีสำหรับสมาชิกที่ย้ายในเทิร์นนี้',
    }],
    [/^Choose a heart color for non-Aqours Members that entered this turn\.?$/, {
      ja: 'このターンに登場したAqours以外のメンバーのハート色を選ぶ。',
      es: 'Elige un color de corazón para los Miembros que no son Aqours que entraron este turno.',
      pt: 'Escolha uma cor de coração para os Membros Não-Aqours que entraram neste turno.',
      ko: '이번 턴에 등장한 Aqours가 아닌 멤버의 하트 색을 선택합니다.',
      zh: '为本回合登场的非Aqours成员选择心形颜色。',
      th: 'เลือกสีหัวใจสำหรับสมาชิกที่ไม่ใช่ Aqours ที่เข้าในเทิร์นนี้',
    }],
    [/^Choose an area with an Aqours or Saint Snow Member to move to\.?$/, {
      ja: 'AqoursまたはSaint Snowメンバーがいるエリアへ移動先を選ぶ。',
      es: 'Elige un área con un Miembro Aqours o Saint Snow al que moverte.',
      pt: 'Escolha uma área com um Membro Aqours ou Saint Snow para mover esta carta.',
      ko: 'Aqours 또는 Saint Snow 멤버가 있는 구역으로 이동할 곳을 선택합니다.',
      zh: '选择要移动到有Aqours或Saint Snow成员的区域。',
      th: 'เลือกพื้นที่ที่มีสมาชิก Aqours หรือ Saint Snow เพื่อย้ายไป',
    }],
    [/^Choose 1 card to add to hand \(rest to Waiting Room\)\.?$/, {
      ja: '手札に加えるカード1枚を選ぶ（残りは控え室へ）。',
      es: 'Elige 1 carta para añadir a la mano (el resto a la Sala de espera).',
      pt: 'Escolha 1 carta para adicionar à mão (restante ficará na Sala de Espera).',
      ko: '손패에 추가할 카드 1장을 선택합니다(나머지는 대기실).',
      zh: '选择1张卡加入手牌（其余放入等候室）。',
      th: 'เลือกการ์ด 1 ใบเพื่อเพิ่มเข้ามือ (ที่เหลือไปห้องรอ)',
    }],
    [/^Choose any number of subunit Members to discard, then draw that many \+1\.?$/, {
      ja: '任意枚数のサブユニットメンバーを捨て、その枚数+1枚ドローする。',
      es: 'Elige cualquier cantidad de Miembros del subgrupo para descartar y roba esa cantidad +1.',
      pt: 'Escolha qualquer quantidade de Membros da Subunit para descartar, e então comprar esta mesma quantidade +1.',
      ko: '임의의 서브유닛 멤버를 버리고 그 수+1장 드로우합니다.',
      zh: '选择任意数量的子团体成员弃置，然后抽该数量+1张。',
      th: 'เลือกสมาชิกย่อยจำนวนเท่าใดก็ได้เพื่อทิ้ง แล้วจั่วเพิ่มอีก 1 ใบ',
    }],
    [/^Choose a number \(0 or higher\), then reveal your deck top\.?$/, {
      ja: '0以上の数字を選び、デッキの上を公開する。',
      es: 'Elige un número (0 o más) y revela la parte superior de tu mazo.',
      pt: 'Escolha um número (0 ou mais) e revele o topo do seu deck.',
      ko: '0 이상의 숫자를 선택한 뒤 덱 위를 공개합니다.',
      zh: '选择一个数字（0或更高），然后公开牌组顶。',
      th: 'เลือกตัวเลข (0 ขึ้นไป) แล้วเปิดเผยการ์ดบนสุดของเด็ค',
    }],
    [/^Select Member cards to reveal from hand\.?$/, {
      ja: '手札から公開するメンバーカードを選ぶ。',
      es: 'Selecciona cartas de Miembro de tu mano para revelarlas.',
      pt: 'Escolha cartas Membro da sua mão para revelar.',
      ko: '손패에서 공개할 멤버 카드를 선택합니다.',
      zh: '从手牌选择要公开的成员卡。',
      th: 'เลือกการ์ดสมาชิกจากมือเพื่อเปิดเผย',
    }],
    [/^Choose a matching Member to add to your hand, or skip\.?$/, {
      ja: '一致するメンバーを手札に加えるか、スキップする。',
      es: 'Elige un Miembro coincidente para añadir a tu mano, u omite.',
      pt: 'Escolha um Membro correspondente para adicionar à sua mão, ou pular.',
      ko: '일치하는 멤버를 손패에 추가하거나 건너뜁니다.',
      zh: '选择1名匹配成员加入手牌，或跳过。',
      th: 'เลือกสมาชิกที่ตรงกันเพื่อเพิ่มเข้ามือ หรือข้าม',
    }],
    [/^Choose 1 (.+?) Member on Stage to grant bonus hearts\.?$/, {
      ja: 'ステージの$1メンバー1体を選び、ボーナスハートを付与。',
      es: 'Elige 1 Miembro $1 en el Escenario para conceder corazones extra.',
      pt: 'Escolha 1 Membro $1 no Palco para conceder corações extras.',
      ko: '스테이지의 $1 멤버 1명을 선택해 보너스 하트를 부여합니다.',
      zh: '选择舞台上1名$1成员给予额外心形。',
      th: 'เลือกสมาชิก $1 บนเวที 1 คนเพื่อให้หัวใจโบนัส',
    }],
    [/^Choose 1 Member card from your Waiting Room to add to your hand\.?$/, {
      ja: '控え室からメンバーカード1枚を選び、手札に加える。',
      es: 'Elige 1 carta de Miembro de tu Sala de espera para añadirla a tu mano.',
      pt: 'Escolha 1 carta de Membro da seu Sala de Espera para adicionar à sua mão.',
      ko: '대기실에서 멤버 카드 1장을 선택해 손패에 추가합니다.',
      zh: '从等候室选择1张成员卡加入手牌。',
      th: 'เลือกการ์ดสมาชิก 1 ใบจากห้องรอเพื่อเพิ่มเข้ามือ',
    }],
    [/^Choose 1 matching Live card from your Waiting Room to add to your hand\.?$/, {
      ja: '控え室から一致するライブカード1枚を選び、手札に加える。',
      es: 'Elige 1 carta Live coincidente de tu Sala de espera para añadirla a tu mano.',
      pt: 'Escolha 1 carta Live correspondente da sua Sala de Espera para adicionar à sua mão.',
      ko: '대기실에서 일치하는 Live 카드 1장을 선택해 손패에 추가합니다.',
      zh: '从等候室选择1张匹配的Live卡加入手牌。',
      th: 'เลือกการ์ด Live ที่ตรงกัน 1 ใบจากห้องรอเพื่อเพิ่มเข้ามือ',
    }],
    [/^Choose 1 (.+?) Live card from your Waiting Room to add to your hand\.?$/, {
      ja: '控え室から$1ライブカード1枚を選び、手札に加える。',
      es: 'Elige 1 carta Live $1 de tu Sala de espera para añadirla a tu mano.',
      pt: 'Escolha 1 carta Live $1 da sua Sala de Espera para adicionar à sua mão.',
      ko: '대기실에서 $1 Live 카드 1장을 선택해 손패에 추가합니다.',
      zh: '从等候室选择1张$1 Live卡加入手牌。',
      th: 'เลือกการ์ด Live $1 1 ใบจากห้องรอเพื่อเพิ่มเข้ามือ',
    }],
    [/^Choose 1 Live card from your Waiting Room\.?$/, {
      ja: '控え室からライブカード1枚を選ぶ。',
      es: 'Elige 1 carta Live de tu Sala de espera.',
      pt: 'Escolha 1 carta Live da sua Sala de Espera.',
      ko: '대기실에서 Live 카드 1장을 선택합니다.',
      zh: '从等候室选择1张Live卡。',
      th: 'เลือกการ์ด Live 1 ใบจากห้องรอ',
    }],
    [/^Choose 1 card just put into your Waiting Room\.?$/, {
      ja: '控え室に置いたばかりのカード1枚を選ぶ。',
      es: 'Elige 1 carta que acabas de poner en tu Sala de espera.',
      pt: 'Escolha 1 carta que acabou de ser colocada na sua Sala de Espera.',
      ko: '방금 대기실에 둔 카드 1장을 선택합니다.',
      zh: '选择刚放入等候室的1张卡。',
      th: 'เลือกการ์ด 1 ใบที่เพิ่งวางลงห้องรอ',
    }],
    [/^Choose 1 μ's Member to put into the Waiting Room\.?$/, {
      ja: 'μ\'sメンバー1体を選び、控え室へ。',
      es: 'Elige 1 Miembro μ\'s para enviarlo a la Sala de espera.',
      pt: 'Escolha 1 Membro μ\'s para colocar na Sala de Espera.',
      ko: 'μ\'s 멤버 1명을 선택해 대기실로 보냅니다.',
      zh: '选择1名μ\'s成员放入等候室。',
      th: 'เลือกสมาชิก μ\'s 1 คนเพื่อส่งไปห้องรอ',
    }],
    [/^Choose 1 (.+?) Member to put into Wait\.?$/, {
      ja: '$1メンバー1体を選び、ウェイトに。',
      es: 'Elige 1 Miembro $1 para ponerlo en Espera.',
      pt: 'Escolha 1 Membro $1 para colocar em Repouso.',
      ko: '$1 멤버 1명을 선택해 Wait로 보냅니다.',
      zh: '选择1名$1成员放入Wait。',
      th: 'เลือกสมาชิก $1 1 คนเพื่อใส่ Wait',
    }],
    [/^Choose an empty Stage area\.?$/, {
      ja: '空のステージエリアを選ぶ。',
      es: 'Elige un área vacía del Escenario.',
      pt: 'Escolha uma área vazia do Palco.',
      ko: '빈 스테이지 구역을 선택합니다.',
      zh: '选择一个空的舞台区域。',
      th: 'เลือกพื้นที่เวทีว่าง',
    }],
    [/^Choose 1 (.+?) Member \(cost ≤(\d+)\) from your Waiting Room\.?$/, {
      ja: '控え室からコスト$2以下の$1メンバー1体を選ぶ。',
      es: 'Elige 1 Miembro $1 (coste ≤$2) de tu Sala de espera.',
      pt: 'Escolha 1 Membro $1 (coste ≤$2) da sua Sala de Espera.',
      ko: '대기실에서 코스트 $2 이하의 $1 멤버 1명을 선택합니다.',
      zh: '从等候室选择1名费用≤$2的$1成员。',
      th: 'เลือกสมาชิก $1 (Cost ≤$2) 1 คนจากห้องรอ',
    }],
    [/^Choose 1 Member with Red, Green, and Blue hearts to add to hand\.?$/, {
      ja: '赤・緑・青ハートを持つメンバー1体を選び、手札に加える。',
      es: 'Elige 1 Miembro con corazones Rojo, Verde y Azul para añadir a la mano.',
      pt: 'Escolha 1 Membro com corações Vermelho, Verde e Azul para adicionar à sua mão.',
      ko: '빨강·초록·파랑 하트를 가진 멤버 1명을 선택해 손패에 추가합니다.',
      zh: '选择1名拥有红、绿、蓝心的成员加入手牌。',
      th: 'เลือกสมาชิกที่มีหัวใจแดง เขียว และน้ำเงิน 1 คนเพื่อเพิ่มเข้ามือ',
    }],
    [/^Choose 1 (.+?) Member that entered via Baton Touch this turn to gain 1 Red heart\.?$/, {
      ja: 'このターンにバトンタッチで登場した$1メンバー1体を選び、赤ハート1つ。',
      es: 'Elige 1 Miembro $1 que entró por Baton Touch este turno para ganar 1 corazón Rojo.',
      pt: 'Escolha 1 Membro $1 que entrou via Passe de Bastão neste turno para ganhar 1 Coração Vermelho.',
      ko: '이번 턴 Baton Touch로 등장한 $1 멤버 1명을 선택해 빨간 하트 1개.',
      zh: '选择本回合通过Baton Touch登场的1名$1成员，获得1颗红心。',
      th: 'เลือกสมาชิก $1 ที่เข้าผ่าน Baton Touch ในเทิร์นนี้ 1 คนเพื่อได้หัวใจแดง 1',
    }],
    [/^Choose a Member on your Stage to swap with Waiting Room\.?$/, {
      ja: '控え室と入れ替えるステージのメンバー1体を選ぶ。',
      es: 'Elige un Miembro en tu Escenario para intercambiarlo con la Sala de espera.',
      pt: 'Escolha um Membro no seu Palco para revezar da Sala de Espera.',
      ko: '대기실과 교환할 스테이지 멤버를 선택합니다.',
      zh: '选择舞台上1名成员与等候室交换。',
      th: 'เลือกสมาชิกบนเวทีเพื่อสลับกับห้องรอ',
    }],
    [/^Choose a Stage Member and how many stacked Energy to return to your Energy deck\.?$/, {
      ja: 'ステージのメンバー1体と、エネルギーデッキに戻す重ねエネルギー枚数を選ぶ。',
      es: 'Elige un Miembro del Escenario y cuánta Energía apilada devolver al mazo de Energía.',
      pt: 'Escolha um Membro do Palco e quantas Energias empilhadas para retornar ao seu Deck de Energia.',
      ko: '스테이지 멤버와 에너지 덱으로 돌릴 겹쳐진 에너지 수를 선택합니다.',
      zh: '选择1名舞台成员以及要返还到能量牌组的叠放能量数量。',
      th: 'เลือกสมาชิกบนเวทีและจำนวนพลังงานที่ซ้อนเพื่อคืนไปเด็คพลังงาน',
    }],
    [/^Choose one effect:$/, {
      ja: '効果を1つ選ぶ：',
      es: 'Elige un efecto:',
      pt: 'Escolha um efeito:',
      ko: '효과를 하나 선택:',
      zh: '选择一个效果：',
      th: 'เลือกเอฟเฟกต์หนึ่งอย่าง:',
    }],
    [/^Choose an effect:$/, {
      ja: '効果を1つ選ぶ：',
      es: 'Elige un efecto:',
      pt: 'Escolha um efeito:',
      ko: '효과를 하나 선택:',
      zh: '选择一个效果：',
      th: 'เลือกเอฟเฟกต์หนึ่งอย่าง:',
    }],
  ];

  function applyPromptChoosePatterns(msg, loc) {
    var out = String(msg);
    PROMPT_CHOOSE_PATTERNS.forEach(function (row) {
      var tpl = row[1][loc];
      if (!tpl) return;
      out = out.replace(row[0], function () {
        var args = arguments;
        return String(tpl).replace(/\$(\d+)/g, function (_m, n) {
          var idx = Number(n);
          return args[idx] != null ? args[idx] : '';
        });
      });
    });
    return out;
  }

  var PROMPT_QUESTION_RULES_ES = [
    [/^Put 1 card from your hand into the Waiting Room: look at the top (\d+) cards of your deck, add 1 to your hand, and put the rest into the Waiting Room[?.]?$/,
      'Pon 1 carta de tu mano en la Sala de espera: mira las $1 cartas superiores de tu mazo, añade 1 a tu mano y pon el resto en la Sala de espera?'],
    [/^Put 1 card from your hand into the Waiting Room: add 1 (.+?) from your Waiting Room to your hand[?.]?$/,
      'Pon 1 carta de tu mano en la Sala de espera: añade 1 $1 de tu Sala de espera a tu mano.'],
    [/^Put 1 card from your hand into the Waiting Room: add (\d+) Energy[?.]?$/, 'Pon 1 carta de tu mano en la Sala de espera: añade $1 Energía.'],
    [/^Choose (up to )?(\d+) (Member|Live|card) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var plural = count !== '1';
        var type = kind === 'Member' ? (plural ? 'cartas de Miembro' : 'carta de Miembro') :
          kind === 'Live' ? (plural ? 'cartas Live' : 'carta Live') : (plural ? 'cartas' : 'carta');
        return 'Elige ' + (upTo ? 'hasta ' : '') + count + ' ' + type + ' de tu Sala de espera y añádela(s) a tu mano.';
      }],
    [/^Choose a (?:matching )?(?:Member )?card from your Waiting Room to add to your hand\.?$/,
      'Elige una carta de tu Sala de espera para añadirla a tu mano.'],
    [/^Choose (\d+) (.+?) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      'Elige $1 carta(s) $2 de tu Sala de espera y añádela(s) a tu mano.'],
    [/^Choose (up to )?(\d+) (Member |Live )?card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var plural = count !== '1';
        var type = kind === 'Member ' ? (plural ? 'cartas de Miembro' : 'carta de Miembro') :
          kind === 'Live ' ? (plural ? 'cartas Live' : 'carta Live') : (plural ? 'cartas' : 'carta');
        return 'Elige ' + (upTo ? 'hasta ' : '') + count + ' ' + type + ' de tu Sala de espera y añádela(s) a tu mano.';
      }],
    [/^Choose 1 card from your Waiting Room to add to your hand \(the rest go to the Waiting Room\)\.?$/,
      'Elige 1 carta de tu Sala de espera para añadirla a tu mano; el resto va a la Sala de espera.'],
    [/^Choose 1 card revealed by Yell to add to your hand\.?$/, 'Elige 1 carta revelada por Yell para añadirla a tu mano.'],
    [/^Choose (?:up to )?(\d+) Member(?:s)? on your Stage\.?$/, 'Elige hasta $1 Miembro(s) en tu Escenario.'],
    [/^Choose (\d+) card(?:s|\(s\))? from your hand to (?:send to|put into) the Waiting Room\.?$/, 'Elige $1 carta(s) de tu mano para ponerla(s) en la Sala de espera.'],
    [/^Discard (\d+) card(?:s|\(s\))? from your hand\.?$/, 'Descarta $1 carta(s) de tu mano.'],
    [/^Look at the top (\d+) cards? of your deck\.?$/, 'Mira las $1 cartas superiores de tu mazo.'],
    [/^Choose (?:an effect|one effect|one):?$/, 'Elige un efecto.'],
    [/^Choose a heart color\.?$/, 'Elige un color de corazón.'],
    [/^Choose (?:yourself|you) or your opponent\.?$/, 'Elígete a ti o a tu oponente.'],
    [/^Choose a Live card for Success Live\.?$/, 'Elige una carta Live para el Live exitoso.'],
    [/^Choose 1 card to add to your hand \(the rest go to the Waiting Room\)\.?$/, 'Elige 1 carta para añadirla a tu mano; el resto va a la Sala de espera.'],
    [/^Ask your opponent: "(.+)"$/, 'Pregunta a tu oponente: «$1»'],
    [/^Put 1 card from your hand into the Waiting Room\?$/, '¿Pones 1 carta de tu mano en la Sala de espera?'],
    [/^Use optional Live Start effect\?$/, '¿Usar este efecto de Inicio de Live?'],
    [/^Use optional effect\?$/, '¿Usar este efecto?'],
  ];

  var PROMPT_QUESTION_RULES_PT = [
    [/^Put 1 card from your hand into the Waiting Room: look at the top (\d+) cards of your deck, add 1 to your hand, and put the rest into the Waiting Room[?.]?$/,
      'Coloque 1 carta da sua mão na Sala de Espera: olhar as $1 cartas do topo de seu deck, adicionar 1 a sua mão e colocar o restante na Sala de Espera?'],
    [/^Put 1 card from your hand into the Waiting Room: add 1 (.+?) from your Waiting Room to your hand[?.]?$/,
      'Coloque 1 carta da sua mão na Sala de Espera: adicione 1 $1 de sua Sala de Espera à sua mão.'],
    [/^Put 1 card from your hand into the Waiting Room: add (\d+) Energy[?.]?$/, 'Coloque 1 carta da sua mão na Sala de Espera: adicione $1 Energia.'],
    [/^Choose (up to )?(\d+) (Member|Live|card) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var plural = count !== '1';
        var type = kind === 'Member' ? (plural ? 'cartas Membro' : 'carta Membro') :
          kind === 'Live' ? (plural ? 'cartas Live' : 'carta Live') : (plural ? 'cartas' : 'carta');
        return 'Escolha ' + (upTo ? 'até ' : '') + count + ' ' + type + ' de sua Sala de Espera e adicione-as a sua mão.';
      }],
    [/^Choose a (?:matching )?(?:Member )?card from your Waiting Room to add to your hand\.?$/,
      'Escolha uma carta da sua Sala de Espera para adicionar à sua mão.'],
    [/^Choose (\d+) (.+?) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      'Escolha $1 carta(s) $2 da sua Sala de Espera e adicione-as à sua mão.'],
    [/^Choose (up to )?(\d+) (Member |Live )?card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var plural = count !== '1';
        var type = kind === 'Member ' ? (plural ? 'cartas Membro' : 'carta Membro') :
          kind === 'Live ' ? (plural ? 'cartas Live' : 'carta Live') : (plural ? 'cartas' : 'carta');
        return 'Escolha ' + (upTo ? 'até ' : '') + count + ' ' + type + ' da sua Sala de Espera e adicione-as à sua mão.';
      }],
    [/^Choose 1 card from your Waiting Room to add to your hand \(the rest go to the Waiting Room\)\.?$/,
      'Escolha 1 carta da sua Sala de Espera para adicionar à sua mão; o restante irá para a Sala de Espera.'],
    [/^Choose 1 card revealed by Yell to add to your hand\.?$/, 'Escolha 1 carta revelada pelo Grito para adicionar à sua mão.'],
    [/^Choose (?:up to )?(\d+) Member(?:s)? on your Stage\.?$/, 'Escolha até $1 Membro(s) no seu Palco.'],
    [/^Choose (\d+) card(?:s|\(s\))? from your hand to (?:send to|put into) the Waiting Room\.?$/, 'Escolha $1 carta(s) da sua mão para colocar na Sala de Espera.'],
    [/^Discard (\d+) card(?:s|\(s\))? from your hand\.?$/, 'Descarte $1 carta(s) da sua mão.'],
    [/^Look at the top (\d+) cards? of your deck\.?$/, 'Olhe as $1 cartas do topo do seu deck.'],
    [/^Choose (?:an effect|one effect|one):?$/, 'Escolha um efeito.'],
    [/^Choose a heart color\.?$/, 'Escolha uma cor de coração.'],
    [/^Choose (?:yourself|you) or your opponent\.?$/, 'Escolha você mesmo ou seu adversário.'],
    [/^Choose a Live card for Success Live\.?$/, 'Escolha uma carta Live para colocar na Zona de Lives Bem-Sucedidas.'],
    [/^Choose 1 card to add to your hand \(the rest go to the Waiting Room\)\.?$/, 'Escolha 1 carta para adicionar à sua mão; o restante irá para a Sala de Espera.'],
    [/^Ask your opponent: "(.+)"$/, 'Pergunte ao seu adversário: "$1"'],
    [/^Put 1 card from your hand into the Waiting Room\?$/, 'Colocar 1 carta da sua mão na Sala de Espera?'],
    [/^Use optional Live Start effect\?$/, 'Usar este efeito de Início de Live?'],
    [/^Use optional effect\?$/, 'Usar este efeito?'],
  ];

  var PROMPT_QUESTION_RULES_KO = [
    [/^Put 1 card from your hand into the Waiting Room: look at the top (\d+) cards of your deck, add 1 to your hand, and put the rest into the Waiting Room[?.]?$/,
      '손패 1장을 대기실에 두고: 덱 위 $1장을 보고 1장을 손으로, 나머지를 대기실로 보낼까요?'],
    [/^Put 1 card from your hand into the Waiting Room: add 1 (.+?) from your Waiting Room to your hand[?.]?$/,
      '손패 1장을 대기실에 두고: 대기실의 $1 1장을 손패에 추가합니다.'],
    [/^Put 1 card from your hand into the Waiting Room: add (\d+) Energy[?.]?$/, '손패 1장을 대기실에 두고: 에너지를 $1장 추가합니다.'],
    [/^Choose (up to )?(\d+) (Member|Live|card) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var type = kind === 'Member' ? '멤버 카드' : kind === 'Live' ? 'Live 카드' : '카드';
        return '대기실에서 ' + type + ' ' + count + '장' + (upTo ? '까지 선택하여' : '을 선택하여') + ' 손패에 추가합니다.';
      }],
    [/^Choose a (?:matching )?(?:Member )?card from your Waiting Room to add to your hand\.?$/,
      '대기실에서 손패에 추가할 카드를 선택하세요.'],
    [/^Choose (\d+) (.+?) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      '대기실에서 $2 카드 $1장을 선택하여 손패에 추가합니다.'],
    [/^Choose (up to )?(\d+) (Member |Live )?card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var type = kind === 'Member ' ? '멤버 카드' : kind === 'Live ' ? 'Live 카드' : '카드';
        return '대기실에서 ' + type + ' ' + count + '장' + (upTo ? '까지 선택하여' : '을 선택하여') + ' 손패에 추가합니다.';
      }],
    [/^Choose 1 card from your Waiting Room to add to your hand \(the rest go to the Waiting Room\)\.?$/,
      '대기실에서 카드 1장을 선택하여 손패에 추가하고, 나머지는 대기실로 보냅니다.'],
    [/^Choose 1 card revealed by Yell to add to your hand\.?$/, 'Yell로 공개된 카드 1장을 선택하여 손패에 추가합니다.'],
    [/^Choose (?:up to )?(\d+) Member(?:s)? on your Stage\.?$/, '자신의 스테이지에서 멤버를 $1명까지 선택합니다.'],
    [/^Choose (\d+) card(?:s|\(s\))? from your hand to (?:send to|put into) the Waiting Room\.?$/, '손패에서 카드 $1장을 선택하여 대기실로 보냅니다.'],
    [/^Discard (\d+) card(?:s|\(s\))? from your hand\.?$/, '손패에서 카드 $1장을 버립니다.'],
    [/^Look at the top (\d+) cards? of your deck\.?$/, '덱 위에서 $1장을 봅니다.'],
    [/^Choose (?:an effect|one effect|one):?$/, '효과를 하나 선택합니다.'],
    [/^Choose a heart color\.?$/, '하트 색을 선택합니다.'],
    [/^Choose (?:yourself|you) or your opponent\.?$/, '자신 또는 상대를 선택합니다.'],
    [/^Choose a Live card for Success Live\.?$/, '성공 Live로 할 Live 카드를 선택합니다.'],
    [/^Choose 1 card to add to your hand \(the rest go to the Waiting Room\)\.?$/, '카드 1장을 선택하여 손패에 추가하고, 나머지는 대기실로 보냅니다.'],
    [/^Ask your opponent: "(.+)"$/, '상대에게 질문: "$1"'],
    [/^Put 1 card from your hand into the Waiting Room\?$/, '손패 1장을 대기실에 둘까요?'],
    [/^Use optional Live Start effect\?$/, '이 라이브 개시 효과를 사용하시겠습니까?'],
    [/^Use optional effect\?$/, '이 효과를 사용하시겠습니까?'],
  ];

  var PROMPT_QUESTION_RULES_ZH = [
    [/^Put 1 card from your hand into the Waiting Room: look at the top (\d+) cards of your deck, add 1 to your hand, and put the rest into the Waiting Room[?.]?$/,
      '将1张手牌放入等候室：查看牌组顶$1张卡，将1张加入手牌，其余放入等候室？'],
    [/^Put 1 card from your hand into the Waiting Room: add 1 (.+?) from your Waiting Room to your hand[?.]?$/,
      '将1张手牌放入等候室：将等候室中的1张$1加入手牌。'],
    [/^Put 1 card from your hand into the Waiting Room: add (\d+) Energy[?.]?$/, '将1张手牌放入等候室：添加$1点能量。'],
    [/^Choose (up to )?(\d+) (Member|Live|card) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var type = kind === 'Member' ? '成员卡' : kind === 'Live' ? 'Live卡' : '卡';
        return '从等候室选择' + (upTo ? '至多' : '') + count + '张' + type + '加入手牌。';
      }],
    [/^Choose a (?:matching )?(?:Member )?card from your Waiting Room to add to your hand\.?$/,
      '从你的等候室中选择一张卡添加到你的手上。'],
    [/^Choose (\d+) (.+?) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      '从等候室选择$1张$2卡加入手牌。'],
    [/^Choose (up to )?(\d+) (Member |Live )?card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var type = kind === 'Member ' ? '成员卡' : kind === 'Live ' ? 'Live卡' : '卡';
        return '从等候室选择' + (upTo ? '至多' : '') + count + '张' + type + '加入手牌。';
      }],
    [/^Choose 1 card from your Waiting Room to add to your hand \(the rest go to the Waiting Room\)\.?$/,
      '从等候室选择1张卡加入手牌，其余放入等候室。'],
    [/^Choose 1 card revealed by Yell to add to your hand\.?$/, '选择Yell公开的1张卡加入手牌。'],
    [/^Choose (?:up to )?(\d+) Member(?:s)? on your Stage\.?$/, '选择你舞台上的至多$1名成员。'],
    [/^Choose (\d+) card(?:s|\(s\))? from your hand to (?:send to|put into) the Waiting Room\.?$/, '从手牌选择$1张卡放入等候室。'],
    [/^Discard (\d+) card(?:s|\(s\))? from your hand\.?$/, '弃置$1张手牌。'],
    [/^Look at the top (\d+) cards? of your deck\.?$/, '查看牌组顶的$1张卡。'],
    [/^Choose (?:an effect|one effect|one):?$/, '选择一个效果。'],
    [/^Choose a heart color\.?$/, '选择一种心形颜色。'],
    [/^Choose (?:yourself|you) or your opponent\.?$/, '选择你自己或对手。'],
    [/^Choose a Live card for Success Live\.?$/, '选择1张Live卡作为成功Live。'],
    [/^Choose 1 card to add to your hand \(the rest go to the Waiting Room\)\.?$/, '选择1张卡加入手牌，其余放入等候室。'],
    [/^Ask your opponent: "(.+)"$/, '询问对手：“$1”'],
    [/^Put 1 card from your hand into the Waiting Room\?$/, '将1张手牌放入等候室？'],
    [/^Use optional Live Start effect\?$/, '使用此Live开始效果吗？'],
    [/^Use optional effect\?$/, '使用此效果吗？'],
  ];

  var PROMPT_QUESTION_RULES_TH = [
    [/^Put 1 card from your hand into the Waiting Room: look at the top (\d+) cards of your deck, add 1 to your hand, and put the rest into the Waiting Room[?.]?$/,
      'วางการ์ด 1 ใบจากมือลงห้องรอ: ดูการ์ดบนสุดของเด็ค $1 ใบ เพิ่ม 1 ใบเข้ามือ และที่เหลือไปห้องรอ?'],
    [/^Put 1 card from your hand into the Waiting Room: add 1 (.+?) from your Waiting Room to your hand[?.]?$/,
      'วางการ์ด 1 ใบจากมือลงห้องรอ: เพิ่ม $1 1 ใบจากห้องรอเข้ามือ'],
    [/^Put 1 card from your hand into the Waiting Room: add (\d+) Energy[?.]?$/, 'วางการ์ด 1 ใบจากมือลงห้องรอ: เพิ่มพลังงาน $1'],
    [/^Choose (up to )?(\d+) (Member|Live|card) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var type = kind === 'Member' ? 'การ์ดสมาชิก' : kind === 'Live' ? 'การ์ด Live' : 'การ์ด';
        return 'เลือก' + type + ' จากห้องรอ' + (upTo ? 'ได้สูงสุด ' : ' ') + count + ' ใบเพื่อเพิ่มเข้ามือ';
      }],
    [/^Choose a (?:matching )?(?:Member )?card from your Waiting Room to add to your hand\.?$/,
      'เลือกการ์ดจากห้องรอเพื่อเพิ่มเข้ามือ'],
    [/^Choose (\d+) (.+?) card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      'เลือกการ์ด $2 จากห้องรอ $1 ใบเพื่อเพิ่มเข้ามือ'],
    [/^Choose (up to )?(\d+) (Member |Live )?card(?:s|\(s\))? from your Waiting Room to add to your hand(?:,? or skip)?\.?$/,
      function (_m, upTo, count, kind) {
        var type = kind === 'Member ' ? 'การ์ดสมาชิก' : kind === 'Live ' ? 'การ์ด Live' : 'การ์ด';
        return 'เลือก' + type + ' จากห้องรอ' + (upTo ? 'ได้สูงสุด ' : ' ') + count + ' ใบเพื่อเพิ่มเข้ามือ';
      }],
    [/^Choose 1 card from your Waiting Room to add to your hand \(the rest go to the Waiting Room\)\.?$/,
      'เลือกการ์ด 1 ใบจากห้องรอเพื่อเพิ่มเข้ามือ และนำที่เหลือไปห้องรอ'],
    [/^Choose 1 card revealed by Yell to add to your hand\.?$/, 'เลือกการ์ดที่ Yell เปิดเผย 1 ใบเพื่อเพิ่มเข้ามือ'],
    [/^Choose (?:up to )?(\d+) Member(?:s)? on your Stage\.?$/, 'เลือกสมาชิกบนเวทีของคุณได้สูงสุด $1 คน'],
    [/^Choose (\d+) card(?:s|\(s\))? from your hand to (?:send to|put into) the Waiting Room\.?$/, 'เลือกการ์ดจากมือ $1 ใบเพื่อนำไปห้องรอ'],
    [/^Discard (\d+) card(?:s|\(s\))? from your hand\.?$/, 'ทิ้งการ์ดจากมือ $1 ใบ'],
    [/^Look at the top (\d+) cards? of your deck\.?$/, 'ดูการ์ดบนสุดของเด็ค $1 ใบ'],
    [/^Choose (?:an effect|one effect|one):?$/, 'เลือกเอฟเฟกต์หนึ่งอย่าง'],
    [/^Choose a heart color\.?$/, 'เลือกสีหัวใจ'],
    [/^Choose (?:yourself|you) or your opponent\.?$/, 'เลือกตัวคุณหรือคู่ต่อสู้'],
    [/^Choose a Live card for Success Live\.?$/, 'เลือกการ์ด Live สำหรับ Live สำเร็จ'],
    [/^Choose 1 card to add to your hand \(the rest go to the Waiting Room\)\.?$/, 'เลือกการ์ด 1 ใบเพื่อเพิ่มเข้ามือ และนำที่เหลือไปห้องรอ'],
    [/^Ask your opponent: "(.+)"$/, 'ถามคู่ต่อสู้: "$1"'],
    [/^Put 1 card from your hand into the Waiting Room\?$/, 'วางการ์ด 1 ใบจากมือลงห้องรอ?'],
    [/^Use optional Live Start effect\?$/, 'ใช้เอฟเฟกต์เริ่ม Live ทางเลือกนี้ไหม?'],
    [/^Use optional effect\?$/, 'ใช้เอฟเฟกต์นี้ไหม?'],
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
  ].concat(PROMPT_QUESTION_RULES_ES);
  /** Effect-detail suffix rules for Brazilian Portuguese. */
  var EFFECT_RULES_PT = [
    [/drew a card\./, 'comprou uma carta.'],
    [/drew (.+)\./, 'comprou $1.'],
    [/discarded a card\./, 'descartou uma carta.'],
    [/put (.+) into the Waiting Room\./, 'enviou $1 para a Sala de Espera.'],
    [/put a card into the Waiting Room\./, 'enviou uma carta para a Sala de Espera.'],
    [/optional Live Start \(choose\)\./, 'Início de Live opcional (escolha).'],
    [/optional Live Start effect \(choose\)\./, 'efeito de Início de Live opcional (escolha).'],
    [/Live Success choice\./, 'escolha de Live Bem-Sucedida.'],
  ].concat(PROMPT_QUESTION_RULES_PT);


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
  ].concat(PROMPT_QUESTION_RULES_KO);

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
    [/optional On Enter skipped \(no legal targets\)\./, '登場時スキップ（対象なし）。'],
    [/optional Wait skipped \(no legal targets\)\./, 'ウェイト効果スキップ（対象なし）。'],
    [/choice skipped \(no legal targets\)\./, '選択スキップ（対象なし）。'],
    [/applied the only remaining choice\./, '残った効果のみ適用。'],
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
  ].concat(PROMPT_QUESTION_RULES_JA);


  var PHRASE_RULES_ZH = [
    [/Success Live card storage/g, '成功Live卡区'],
    [/Live storage/g, 'Live存放区'],
    [/Success Live/g, '成功Live'],
    [/Waiting Room/g, '等候室'],
    [/Energy deck/g, '能量牌组'],
    [/Main Deck/g, '主牌组'],
    [/Stage Member/g, '舞台成员'],
    [/from your hand/g, '从你的手牌'],
    [/your hand/g, '你的手牌'],
    [/your deck/g, '你的牌组'],
    [/your Stage/g, '你的舞台'],
    [/Member card/g, '成员卡'],
    [/Live card/g, 'Live卡'],
    [/Energy/g, '能量'],
    [/Member/g, '成员'],
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
  ].concat(PROMPT_QUESTION_RULES_ZH);

  var PHRASE_RULES_TH = [
    [/Success Live card storage/g, 'ที่เก็บการ์ด Live สำเร็จ'],
    [/Live storage/g, 'ที่เก็บ Live'],
    [/Success Live/g, 'Live สำเร็จ'],
    [/Waiting Room/g, 'ห้องรอ'],
    [/Energy deck/g, 'เด็คพลังงาน'],
    [/Main Deck/g, 'เด็คหลัก'],
    [/Stage Member/g, 'สมาชิกบนเวที'],
    [/from your hand/g, 'จากมือของคุณ'],
    [/your hand/g, 'มือของคุณ'],
    [/your deck/g, 'เด็คของคุณ'],
    [/your Stage/g, 'เวทีของคุณ'],
    [/Member card/g, 'การ์ดสมาชิก'],
    [/Live card/g, 'การ์ด Live'],
    [/Energy/g, 'พลังงาน'],
    [/Member/g, 'สมาชิก'],
    [/Baton Touch/g, 'บาตองทัช'],
    [/ overplayed onto (.+)\.$/, ' วางทับบน $1'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      return ' เล่น ' + card + ' ลงพื้นที่' + (SLOT_TH[slot] || slot);
    }],
  ];

  var EFFECT_RULES_TH = [
    [/drew a card\./, 'จั่วการ์ด 1 ใบ'],
    [/drew (.+)\./, 'จั่ว $1'],
    [/discarded a card\./, 'ทิ้งการ์ด 1 ใบ'],
    [/put (.+) into the Waiting Room\./, 'วาง $1 ลงห้องรอ'],
    [/put a card into the Waiting Room\./, 'วางการ์ด 1 ใบลงห้องรอ'],
    [/optional Live Start \(choose\)\./, 'เริ่ม Live ทางเลือก (เลือก)'],
    [/optional Live Start effect \(choose\)\./, 'เอฟเฟกต์เริ่ม Live ทางเลือก (เลือก)'],
    [/Live Success choice\./, 'ตัวเลือก Live สำเร็จ'],
  ].concat(PROMPT_QUESTION_RULES_TH);

  function clearLogNameCache() {
    namePairs = null;
    namePairsKo = null;
    namePairsZh = null;
    namePairsTh = null;
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

  /**
   * English name → Thai name pairs, built from `LLTCG_I18N.cardLocaleName()`
   * (TH_NAME_MAP / TH_SONG_MAP). Cards with no Thai mapping fall back to their
   * English name inside `cardLocaleName`, so they are skipped here (no-op).
   */
  function buildNamePairsTh(catalog) {
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
      var th = String(i18n.cardLocaleName(c) || '').trim();
      if (!th || th === en) return;
      var key = en.toLowerCase();
      if (seen[key]) return;
      seen[key] = 1;
      pairs.push([en, th]);
    });
    pairs.sort(function (a, b) { return b[0].length - a[0].length; });
    return pairs;
  }

  function getNamePairsTh(catalog) {
    if (!namePairsTh) namePairsTh = buildNamePairsTh(catalog);
    return namePairsTh;
  }

  function replaceCardNamesTh(msg, catalog) {
    if (!msg) return msg;
    getNamePairsTh(catalog).forEach(function (pair) {
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
    out = applyPromptChoosePatterns(out, 'ja');
    out = replaceCardNames(out, catalog);
    out = replaceSkillBrackets(out);
    out = applyRules(out, PROMPT_QUESTION_RULES_JA);
    out = applyRules(out, PHRASE_RULES);
    out = applyRules(out, EFFECT_RULES);
    return out;
  }

  function localizePromptTextEs(msg, catalog) {
    if (!msg) return msg;
    var out = String(msg);
    out = applyPromptChoosePatterns(out, 'es');
    out = replaceSkillBrackets(out, SKILL_BRACKETS_ES);
    out = applyRules(out, PROMPT_QUESTION_RULES_ES);
    out = applyRules(out, PHRASE_RULES_ES);
    out = applyRules(out, EFFECT_RULES_ES);
    return out;
  }

  function localizePromptTextKo(msg, catalog) {
    if (!msg) return msg;
    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = applyPromptChoosePatterns(out, 'ko');
    out = replaceCardNamesKo(out, catalog);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_KO);
    out = applyRules(out, PROMPT_QUESTION_RULES_KO);
    out = applyRules(out, PHRASE_RULES_KO);
    out = applyRules(out, EFFECT_RULES_KO);
    return out;
  }

  function localizePromptTextZh(msg, catalog) {
    if (!msg) return msg;
    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = applyPromptChoosePatterns(out, 'zh');
    out = replaceCardNamesZh(out, catalog);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_ZH);
    out = applyRules(out, PROMPT_QUESTION_RULES_ZH);
    out = applyRules(out, PHRASE_RULES_ZH);
    out = applyRules(out, EFFECT_RULES_ZH);
    return out;
  }

  function localizePromptTextTh(msg, catalog) {
    if (!msg) return msg;
    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = applyPromptChoosePatterns(out, 'th');
    out = replaceCardNamesTh(out, catalog);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_TH);
    out = applyRules(out, PROMPT_QUESTION_RULES_TH);
    out = applyRules(out, PHRASE_RULES_TH);
    out = applyRules(out, EFFECT_RULES_TH);
    return out;
  }


  function localizePromptTextPt(msg, catalog) {
    if (!msg) return msg;
    var out = String(msg);
    out = applyPromptChoosePatterns(out, 'pt');
    out = replaceSkillBrackets(out, SKILL_BRACKETS_PT);
    out = applyRules(out, PROMPT_QUESTION_RULES_PT);
    out = applyRules(out, PHRASE_RULES_PT);
    out = applyRules(out, EFFECT_RULES_PT);
    return out;
  }

  function localizeLogMessagePt(msg, catalog) {
    if (!msg) return msg;

    var exact = translateExact(msg);
    if (exact != null) return exact;

    var structured = translateStructuredLinePt(msg);
    if (structured != null) return structured;

    var out = String(msg);
    out = applyRules(out, STRUCTURAL_PHRASE_RULES_PT);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_PT);
    out = applyRules(out, PHRASE_RULES_PT);
    out = applyRules(out, EFFECT_RULES_PT);
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
    if (loc === 'th') return localizePromptTextTh(msg, catalog);
    if (loc === 'pt') return localizePromptTextPt(msg, catalog);
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
    out = replaceSkillBrackets(out, SKILL_BRACKETS_ES);
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

  function localizeLogMessageTh(msg, catalog) {
    if (!msg) return msg;

    var exact = translateExact(msg);
    if (exact != null) return exact;

    var structured = translateStructuredLineTh(msg);
    if (structured != null) return structured;

    catalog = catalog || (global.G && global.G.allCards);
    var out = String(msg);
    out = applyRules(out, STRUCTURAL_PHRASE_RULES_TH);
    out = replaceCardNamesTh(out, catalog);
    out = replaceSkillBrackets(out, SKILL_BRACKETS_TH);
    out = applyRules(out, PHRASE_RULES_TH);
    out = applyRules(out, EFFECT_RULES_TH);
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
    if (loc === 'th') return localizeLogMessageTh(msg, catalog);
    if (loc === 'pt') return localizeLogMessagePt(msg, catalog);
    return msg;
  }

  global.LLTCG_LOG_I18N = {
    clearLogNameCache: clearLogNameCache,
    localizeLogMessage: localizeLogMessage,
    localizePromptText: localizePromptText,
  };
})(typeof window !== 'undefined' ? window : globalThis);
