/* Client-side game log localization (English server log → Japanese display) */
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

  /** Full-line / anchored regex rules (order matters). */
  var PHRASE_RULES = [
    [/^=== LIVE Phase ===$/, '=== ライブフェイズ ==='],
    [/^=== Performance Phase ===$/, '=== パフォーマンスフェイズ ==='],
    [/^=== Live Show ===$/, '=== ライブショー ==='],
    [/^=== Live Start Effects ===$/, '=== ライブ開始効果 ==='],
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
    [/^Coin flip — continued automatically \(player did not respond in time\)\.$/, 'コイントス — 応答がなかったため自動で続行。'],
    [/^CPU deck: (.+)$/, 'CPUデッキ：$1'],
    [/^Live Scores: (.+) = (\d+) \| (.+) = (\d+)$/, 'ライブスコア：$1 = $2 | $3 = $4'],
    [/^(.+) disconnected\. (.+) wins!$/, '$1 が切断。$2 の勝利！'],
    [/^(.+) wins the Live — (.+) failed\.$/, '$1 のライブ勝利 — $2 は失敗。'],
    [/^(.+) wins this Live! "(.+)" added to successes\.$/, '$1 ライブ勝利！「$2」を成功ライブに追加。'],
    [/^(.+) placed (\d+) card\(s\) face-down in storage \((\d+)\/3\)\.$/, '$1 が置き場に$2枚を裏向きで配置（$3/3）。'],
    [/^(.+) — locked in LIVE selection \((\d+) card\(s\) in storage\)\.$/, '$1 — ライブ選択を確定（置き場$2枚）。'],
    [/^(.+) — locked in LIVE selection\.$/, '$1 — ライブ選択を確定。'],
    [/^(.+) is performing Live with (.+)\.$/, '$1 がライブ実施：$2。'],
    [/^(.+) — Yell retry: drew (\d+) card\(s\) for Blade\.$/, '$1 — エールリトライ：刃$2枚分ドロー。'],
    [/^(.+) — Yell retry reduced by (\d+) \(drew 0 of (\d+) Blade\)\.$/, '$1 — エールリトライを$2減少（刃$3枚中0枚ドロー）。'],
    [/^(.+) — choose a Live card for Success Live\.$/, '$1 — 成功ライブに置くライブカードを選択。'],
    [/^(.+) — Main Phase time expired \(auto end\)\.$/, '$1 — メインフェイズ時間切れ（自動終了）。'],
    [/^(.+) — LIVE Phase time expired \(auto lock-in\)\.$/, '$1 — ライブフェイズ時間切れ（自動確定）。'],
    [/^(.+) — CPU hung on skill; auto-skipped optional effect\.$/, '$1 — CPUがスキルで停止；任意効果を自動スキップ。'],
    [/^(.+) — Anti-softlock: skipped optional skill\.$/, '$1 — ソフトロック防止：任意スキルをスキップ。'],
    [/^(.+) — score tied; Success Live blocked; Live cards sent to Waiting Room\.$/, '$1 — スコア同点；成功ライブ不可；ライブカードを控え室へ。'],
    [/^(.+) — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room\.$/, '$1 — スコア同点だが成功ライブ2枚済み；ライブカードを控え室へ。'],
    [/^(.+) — (\d+) other successful Live\(s\) in storage cannot be placed \(only 1 Success Live per Judge win\); sent to Waiting Room\.$/, '$1 — 置き場の他の成功ライブ$2枚は配置不可（判定勝利ごとに成功ライブ1枚のみ）；控え室へ。'],
    [/^(.+) has no valid Live cards!$/, '$1 に有効なライブカードがありません！'],
    [/^(.+) performed Live! Blades: (\d+) \| Live success: (yes|no)$/i, function (_m, who, blades, ok) {
      return who + ' ライブ実施！刃：' + blades + ' | ライブ成功：' + (String(ok).toLowerCase() === 'yes' ? '成功' : '失敗');
    }],
    [/^🪙 Coin flip: (.+) won — first player chosen automatically \(time expired\)\.$/, '🪙 コイントス：$1 の勝ち — 先攻を自動選択（時間切れ）。'],
    [/ — End Main Phase\.$/, ' — メインフェイズ終了。'],
    [/ completed mulligan\.$/, ' マリガン完了。'],
    [/ resigned\. (.+) wins!$/, ' リタイア。$1 の勝利！'],
    [/ WINS with 3 successful Lives!$/, ' ライブ3回成功で勝利！'],
    [/ used Baton Touch! Cost reduced to (\d+)\./, ' バトンタッチ！コストが$1に減少。'],
    [/ used second Baton Touch! Cost reduced to (\d+)\./, ' 2枚目のバトンタッチ！コストが$1に減少。'],
    [/ overplayed onto (.+)\.$/, ' $1の上に上書きプレイ。'],
    [/ played (.+) to (left|center|right) area\.$/, function (_m, card, slot) {
      return ' ' + card + 'を' + (SLOT_JA[slot] || slot) + 'エリアにプレイ。';
    }],
    [/ \((\d+) Energy under replaced Member carried over\.\)/, '（置き換えメンバー下のエネルギー$1枚を引き継ぎ）'],
    [/ — Draw Phase: could not draw \(deck and Waiting Room empty\)\.$/, ' — ドローフェイズ：ドロー不可（デッキと控え室が空）。'],
    [/ — Draw Phase\.$/, ' — ドローフェイズ。'],
    [/ — Active Phase: Energy and Members refreshed\.$/, ' — アクティブフェイズ：エネルギーとメンバーをアクティブに。'],
    [/ — Energy Phase: storage full \((\d+)\/(\d+)\), no Energy added\.$/, ' — エネルギーフェイズ：置き場満杯（$1/$2）、エネルギー追加なし。'],
    [/ — Energy Phase: no cards left in Energy deck\.$/, ' — エネルギーフェイズ：エネルギーデッキにカードなし。'],
    [/ — Energy Phase: placed 1 Energy in storage \((\d+)\/(\d+)\)\.$/, ' — エネルギーフェイズ：エネルギー1枚を置き場に（$1/$2）。'],
    [/ — \[([^\]]+)\] drew (\d+) \(Active → Wait\)\.$/, ' — [$1] $2枚ドロー（アクティブ→ウェイト）。'],
    [/ — \[([^\]]+)\] optional skill skipped\.$/, ' — [$1] スキルをスキップ。'],
    [/ — \[([^\]]+)\] activated\.$/, ' — [$1] 起動。'],
    [/ — \[([^\]]+)\] Live Start skipped\.$/, ' — [$1] ライブ開始スキップ。'],
    [/ — \[([^\]]+)\] Live Success skipped\.$/, ' — [$1] ライブ成功スキップ。'],
    [/ — \[([^\]]+)\] Yell cards to Waiting Room; Yell again \(Blade hearts from prior Yell lost\)\.$/, ' — [$1] エールカードを控え室へ；エール再実行（前回エールの刃ハート消失）。'],
    [/ — kept Yell cards \(declined retry\)\.$/, ' — エールカードを維持（リトライ拒否）。'],
    [/put 1 Energy from Energy deck into Wait\./, 'エネルギーデッキからエネルギー1枚をウェイトに。'],
    [/could not put Energy into Wait \(Energy deck empty\)\./, 'エネルギーをウェイトに置けません（エネルギーデッキが空）。'],
    [/added (\d+) Member cards? from Waiting Room to hand\./, '控え室からメンバーカード$1枚を手札に加えた。'],
    [/added (\d+) Member card from Waiting Room to hand\./, '控え室からメンバーカード$1枚を手札に加えた。'],
    [/no Member card in Waiting Room to add to hand\./, '控え室に手札へ加えるメンバーカードがない。'],
    [/Live SUCCESS/i, 'ライブ成功'],
    [/Live FAIL/i, 'ライブ失敗'],
    [/Live failed/i, 'ライブ失敗'],
    [/Live succeeded/i, 'ライブ成功'],
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
    [/(\d+) non-Live card\(s\) from storage sent to Waiting Room\./, '置き場の非ライブカード$1枚を控え室へ。'],
    [/ — discarded Live card \((.+)\)\./, ' — ライブカードを捨てた（$1）。'],
    [/ — discarded (\d+) card \((.+)\)\./, ' — カード$1枚を捨てた（$2）。'],
    [/ — Yell: gained \+(\d+) Blade\./, ' — エール：刃+$1。'],
    [/ — named Member gained \+(\d+) Blade\./, ' — 指定メンバーが刃+$1。'],
  ];

  /** Fragment glossary for effect tails (longest match first). */
  var DETAIL_GLOSSARY = [
    ['Success Live card storage', '成功ライブ置き場'],
    ['into the Waiting Room', '控え室へ'],
    ['from the Waiting Room', '控え室から'],
    ['into Waiting Room', '控え室へ'],
    ['from Waiting Room', '控え室から'],
    ['to Waiting Room', '控え室へ'],
    ['Waiting Room', '控え室'],
    ['Success Live area', '成功ライブ置き場'],
    ['Success Live', '成功ライブ'],
    ['Success Lives', '成功ライブ'],
    ['successful Lives', '成功ライブ'],
    ['Live storage', 'ライブ置き場'],
    ['Energy deck', 'エネルギーデッキ'],
    ['Main Deck', 'メインデッキ'],
    ['main deck', 'メインデッキ'],
    ['on the bottom of the deck', 'デッキの下に'],
    ['on top of deck', 'デッキの上に'],
    ['on deck bottom', 'デッキ下に'],
    ['on deck top', 'デッキ上に'],
    ['deck bottom', 'デッキ下'],
    ['deck top', 'デッキ上'],
    ['Stage Members', 'ステージのメンバー'],
    ['Stage Member', 'ステージのメンバー'],
    ['opponent Stage Members', '相手ステージのメンバー'],
    ['opponent Stage Member', '相手ステージのメンバー'],
    ['opponent hand', '相手の手札'],
    ['active Stage Members', 'アクティブなステージメンバー'],
    ['active Energy', 'アクティブなエネルギー'],
    ['until this Live ends', 'このライブ終了まで'],
    ['until Live ends', 'ライブ終了まで'],
    ['Required Gray Hearts', '必要グレーハート'],
    ['Required any-color hearts', '必要任意色ハート'],
    ['Required Hearts', '必要ハート'],
    ['Blade hearts', '刃ハート'],
    ['bonus hearts', 'ボーナスハート'],
    ['bonus heart', 'ボーナスハート'],
    ['surplus hearts', '余剰ハート'],
    ['excess hearts', '超過ハート'],
    ['Wild heart', 'ワイルドハート'],
    ['Live Score', 'ライブスコア'],
    ['Live Success', 'ライブ成功'],
    ['Live Start', 'ライブ開始'],
    ['Live card', 'ライブカード'],
    ['Live cards', 'ライブカード'],
    ['Member cards', 'メンバーカード'],
    ['Member card', 'メンバーカード'],
    ['Yell Live cards', 'エールライブカード'],
    ['Yell Live card', 'エールライブカード'],
    ['Yell Live', 'エールライブ'],
    ['Yell cards', 'エールカード'],
    ['Yell card', 'エールカード'],
    ['Yell retry', 'エールリトライ'],
    ['Yell reveal', 'エール公開'],
    ['Yell again', 'エール再実行'],
    ['Yell member', 'エールメンバー'],
    ['Yell Member', 'エールメンバー'],
    ['Position Change', 'ポジションチェンジ'],
    ['position change', 'ポジションチェンジ'],
    ['position-changed', 'ポジションチェンジ'],
    ['formation-changed', 'フォーメーション変更'],
    ['formation change', 'フォーメーション変更'],
    ['Baton Touch', 'バトンタッチ'],
    ['looked at deck top', 'デッキ上を確認'],
    ['searched the deck', 'デッキをサーチ'],
    ['no cards left in deck', 'デッキにカードなし'],
    ['no cards in hand', '手札にカードなし'],
    ['no Stage Members', 'ステージにメンバーなし'],
    ['no matching', '一致なし'],
    ['no valid', '有効なものなし'],
    ['no effect', '効果なし'],
    ['not a Live card', 'ライブカードではない'],
    ['not in Center', 'センターにいない'],
    ['no longer in Center', 'センターにいない'],
    ['must be in Center', 'センターである必要あり'],
    ['in Center', 'センターに'],
    ['left Stage', 'ステージを離脱'],
    ['entered Stage', 'ステージに登場'],
    ['on Stage', 'ステージに'],
    ['from Stage', 'ステージから'],
    ['into hand', '手札へ'],
    ['to hand', '手札へ'],
    ['from hand', '手札から'],
    ['in hand', '手札に'],
    ['into Wait', 'ウェイトへ'],
    ['to Wait', 'ウェイトへ'],
    ['into Wait.', 'ウェイトへ。'],
    ['put self into Wait', '自身をウェイトに'],
    ['optional skill skipped', '任意スキルをスキップ'],
    ['optional effect skipped', '任意効果をスキップ'],
    ['optional On Enter skipped', '任意登場時をスキップ'],
    ['skipped optional', '任意をスキップ'],
    ['skipped formation change', 'フォーメーション変更をスキップ'],
    ['skipped position change', 'ポジションチェンジをスキップ'],
    ['skipped optional effect', '任意効果をスキップ'],
    ['skipped optional negate', '任意無効化をスキップ'],
    ['skipped optional reposition', '任意再配置をスキップ'],
    ['skipped optional Wait effect', '任意ウェイト効果をスキップ'],
    ['skipped Yell member pick', 'エールメンバー選択をスキップ'],
    ['skipped Yell Live pick', 'エールライブ選択をスキップ'],
    ['skipped Blade bonus', '刃ボーナスをスキップ'],
    ['effect skipped', '効果スキップ'],
    ['effect cancelled', '効果キャンセル'],
    ['could not discard', '捨て札不可'],
    ['could not match', '一致せず'],
    ['could not put', '置けず'],
    ['could not', 'できず'],
    ['auto-skipped', '自動スキップ'],
    ['time expired', '時間切れ'],
    ['disconnected', '切断'],
    ['activated:', '起動：'],
    ['activated', '起動'],
    ['negated', '無効化'],
    ['cancelled', 'キャンセル'],
    ['revealed', '公開'],
    ['discarded', '捨てた'],
    ['performed Live', 'ライブ実施'],
    ['added to successes', '成功ライブに追加'],
    ['added to hand', '手札に追加'],
    ['added itself to hand', '自身を手札に追加'],
    ['carried over', '引き継ぎ'],
    ['face-down', '裏向き'],
    ['locked in', '確定'],
    ['this turn', 'このターン'],
    ['opponent', '相手'],
    ['optional', '任意'],
    ['skipped', 'スキップ'],
    ['choose', '選択'],
    ['granted', '付与'],
    ['gained', '獲得'],
    ['granted bonus', 'ボーナス付与'],
    ['gained bonus', 'ボーナス獲得'],
    ['Members', 'メンバー'],
    ['Member', 'メンバー'],
    ['hearts', 'ハート'],
    ['heart', 'ハート'],
    ['Blade', '刃'],
    ['Blades', '刃'],
    ['Energy', 'エネルギー'],
    ['storage', '置き場'],
    ['Waited', 'ウェイトに'],
    ['Wait.', 'ウェイト。'],
    ['Wait', 'ウェイト'],
    ['drew', 'ドロー'],
    ['draw', 'ドロー'],
    ['placed', '配置'],
    ['resigned', 'リタイア'],
    ['mulligan', 'マリガン'],
    ['surveil', 'サーベイル'],
    ['(choose)', '（選択）'],
    ['(deck empty)', '（デッキが空）'],
    ['(no Success Live)', '（成功ライブなし）'],
    ['(pay Energy)', '（エネルギー支払い）'],
    ['(pay or discard)', '（支払いまたは捨て札）'],
    ['(discard)', '（捨て札）'],
    ['(no effect)', '（効果なし）'],
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

  function applyPhraseRules(msg) {
    var out = msg;
    PHRASE_RULES.forEach(function (rule) {
      var re = rule[0];
      var rep = rule[1];
      if (typeof rep === 'function') out = out.replace(re, rep);
      else out = out.replace(re, rep);
    });
    return out;
  }

  function applyGlossaryToTail(tail) {
    var out = tail;
    DETAIL_GLOSSARY.forEach(function (pair) {
      if (out.indexOf(pair[0]) === -1) return;
      out = out.split(pair[0]).join(pair[1]);
    });
    return out;
  }

  /** Translate effect/action tails without touching player names in the prefix. */
  function applyDetailGlossary(msg) {
    var parts = msg.split(' — ');
    if (parts.length === 1) {
      var only = parts[0];
      var bi = only.indexOf('] ');
      if (bi >= 0) {
        return only.slice(0, bi + 2) + applyGlossaryToTail(only.slice(bi + 2));
      }
      return only;
    }
    return parts.map(function (seg, i) {
      if (i === 0) return seg;
      var bracketIdx = seg.indexOf('] ');
      if (bracketIdx >= 0) {
        return seg.slice(0, bracketIdx + 2) + applyGlossaryToTail(seg.slice(bracketIdx + 2));
      }
      return applyGlossaryToTail(seg);
    }).join(' — ');
  }

  function localizeLogMessage(msg, catalog) {
    if (!msg) return msg;
    var i18n = global.LLTCG_I18N;
    if (!i18n || i18n.getLocale() !== 'ja') return msg;
    catalog = catalog || (global.G && global.G.allCards);
    var out = replaceCardNames(String(msg), catalog);
    out = replaceSkillBrackets(out);
    out = applyPhraseRules(out);
    out = applyDetailGlossary(out);
    return out;
  }

  global.LLTCG_LOG_I18N = {
    clearLogNameCache: clearLogNameCache,
    localizeLogMessage: localizeLogMessage,
  };
})(typeof window !== 'undefined' ? window : globalThis);
