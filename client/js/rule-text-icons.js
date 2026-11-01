/**
 * Inline icon tags in card skill text (<energy>, <pinkH>, <score+1>, …).
 * Used by renderCardRulesText and prompt localization comparisons.
 */
'use strict';

(function (global) {
  const ICON = 'icons/';

  const HEART_TAG_COLOR = {
    pinkH: 'pink',
    redH: 'red',
    yellowH: 'yellow',
    greenH: 'green',
    blueH: 'blue',
    purpleH: 'purple',
    anyH: 'any',
    allH: 'all',
  };

  const BLADE_HEART_TAG_COLOR = {
    pinkBH: 'pink',
    redBH: 'red',
    yellowBH: 'yellow',
    greenBH: 'green',
    blueBH: 'blue',
    purpleBH: 'purple',
    allBH: 'all',
  };

  const HEART_ICON = {
    pink: 'heart01.png',
    red: 'heart02.png',
    yellow: 'heart03.png',
    green: 'heart04.png',
    blue: 'heart05.png',
    purple: 'heart06.png',
    any: 'heart00.png',
    all: 'icon_all.png',
  };

  const BLADE_HEART_ICON = {
    pink: 'blade_heart01.png',
    red: 'blade_heart02.png',
    yellow: 'blade_heart03.png',
    green: 'blade_heart04.png',
    blue: 'blade_heart05.png',
    purple: 'blade_heart06.png',
    all: 'icon_b_all.png',
    any: 'heart00.png',
  };

  const ALLOWED_TAGS = new Set([
    'energy',
    'blade',
    ...Object.keys(HEART_TAG_COLOR),
    ...Object.keys(BLADE_HEART_TAG_COLOR),
    'score+1',
    'card+1',
  ]);

  const TAG_NAMES = [...ALLOWED_TAGS].map(t => t.replace(/[+]/g, '\\+')).join('|');
  global.RULE_INLINE_TAG_RE = new RegExp(`<(${TAG_NAMES})>`, 'g');

  function iconSrc(file) {
    return ICON + file;
  }

  function mkInlineGameIcon(file, cls) {
    const img = document.createElement('img');
    img.className = cls || 'rule-inline-icon ticon';
    img.src = iconSrc(file);
    img.alt = '';
    img.decoding = 'async';
    return img;
  }

  function mkInlineHeartIcon(tag) {
    const key = HEART_TAG_COLOR[tag] || 'any';
    const img = document.createElement('img');
    img.className = 'rule-inline-icon hicon';
    img.src = iconSrc(HEART_ICON[key] || HEART_ICON.any);
    img.alt = '';
    img.decoding = 'async';
    return img;
  }

  function mkInlineBladeHeartIcon(tag) {
    const key = BLADE_HEART_TAG_COLOR[tag] || 'any';
    const img = document.createElement('img');
    img.className = 'rule-inline-icon hicon';
    img.src = iconSrc(BLADE_HEART_ICON[key] || BLADE_HEART_ICON.any);
    img.alt = '';
    img.decoding = 'async';
    return img;
  }

  function iconNodeForTag(tag) {
    if (tag === 'energy') return mkInlineGameIcon('icon_energy.png');
    if (tag === 'blade') return mkInlineGameIcon('icon_blade.png', 'rule-inline-icon bicon');
    if (tag === 'score+1') return mkInlineGameIcon('sp_score.png');
    if (tag === 'card+1') return mkInlineGameIcon('sp_draw.png');
    if (HEART_TAG_COLOR[tag]) return mkInlineHeartIcon(tag);
    if (BLADE_HEART_TAG_COLOR[tag]) return mkInlineBladeHeartIcon(tag);
    return null;
  }

  /** Strip tags to prose tokens for prompt / line matching. */
  function stripRuleInlineTags(text) {
    if (!text) return '';
    return String(text)
      .replace(/(\d)<energy>/g, '$1 Energy')
      .replace(/<energy>/g, 'Energy')
      .replace(/(\d)<blade>/g, '+$1 Blade')
      .replace(/<blade>/g, 'Blade')
      .replace(/<score\+1>/g, 'Score +1')
      .replace(/<card\+1>/g, 'Card +1')
      .replace(/<pinkH>/g, 'Pink heart')
      .replace(/<redH>/g, 'Red heart')
      .replace(/<yellowH>/g, 'Yellow heart')
      .replace(/<greenH>/g, 'Green heart')
      .replace(/<blueH>/g, 'Blue heart')
      .replace(/<purpleH>/g, 'Purple heart')
      .replace(/<(anyH|allH)>/g, 'any-color heart')
      .replace(/<pinkBH>/g, 'Pink Blade heart')
      .replace(/<redBH>/g, 'Red Blade heart')
      .replace(/<yellowBH>/g, 'Yellow Blade heart')
      .replace(/<greenBH>/g, 'Green Blade heart')
      .replace(/<blueBH>/g, 'Blue Blade heart')
      .replace(/<purpleBH>/g, 'Purple Blade heart')
      .replace(/<allBH>/g, 'ALL Blade heart');
  }

  function normalizeRuleTextForCompare(text) {
    return stripRuleInlineTags(text)
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function appendPlainTextWithInlineIcons(container, text, appendYellFn) {
    if (!text) return;
    const re = new RegExp(global.RULE_INLINE_TAG_RE.source, 'g');
    let last = 0;
    let match;
    while ((match = re.exec(text)) !== null) {
      if (match.index > last && typeof appendYellFn === 'function') {
        appendYellFn(container, text.slice(last, match.index));
      } else if (match.index > last) {
        container.appendChild(document.createTextNode(text.slice(last, match.index)));
      }
      const tag = match[1];
      const node = iconNodeForTag(tag);
      if (node) {
        container.appendChild(node);
      } else {
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[rule-text-icons] unknown tag:', tag);
        }
        container.appendChild(document.createTextNode(match[0]));
      }
      last = match.index + match[0].length;
    }
    if (last < text.length) {
      if (typeof appendYellFn === 'function') {
        appendYellFn(container, text.slice(last));
      } else {
        container.appendChild(document.createTextNode(text.slice(last)));
      }
    }
  }

  global.LLTCG_RULE_TEXT_ICONS = {
    ALLOWED_TAGS,
    iconNodeForTag,
    stripRuleInlineTags,
    normalizeRuleTextForCompare,
    appendPlainTextWithInlineIcons,
  };
  global.stripRuleInlineTags = stripRuleInlineTags;
  global.normalizeRuleTextForCompare = normalizeRuleTextForCompare;
  global.appendPlainTextWithInlineIcons = appendPlainTextWithInlineIcons;
})(typeof window !== 'undefined' ? window : globalThis);
