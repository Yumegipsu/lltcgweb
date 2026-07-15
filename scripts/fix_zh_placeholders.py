#!/usr/bin/env python3
"""Repair zh.json: restore EN i18n placeholders corrupted by MT + improve menu copy."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
EN = json.loads((ROOT / "locales" / "en_extracted.json").read_text(encoding="utf-8"))
ZH_PATH = ROOT / "locales" / "zh.json"
zh = json.loads(ZH_PATH.read_text(encoding="utf-8"))

# Hand overrides for hub/menu player-facing strings (native SC).
OVERRIDES = {
    "menu.unrankedPlay": "非排名对战",
    "menu.unrankedSub": "开房、与朋友对战，或练习对战 CPU",
    "menu.deckExperiment": "牌组实验",
    "menu.deckExperimentSub": "用全部卡牌构筑——仅限访客，不计入排名",
    "menu.howToPlay": "怎么玩",
    "menu.howToPlaySub": "与香音一起的新手实战教学",
    "hub.options": "选项",
    "hub.signOut": "退出登录",
    "hub.openBoosters": "开启补充包",
    "hub.openBoostersSub": "打开卡包",
    "hub.deckBuilder": "牌组构筑",
    "hub.deckBuilderSub": "编辑预设并设置排名牌组",
    "hub.rankedPvp": "排名对战",
    "hub.rankedPvpSub": "匹配对战并提升 ELO",
    "hub.leaderboard": "排行榜",
    "hub.leaderboardSub": "查看在线排名",
    "hub.unranked": "非排名对战",
    "hub.unrankedSub": "开房、好友对战、CPU 练习",
    "hub.tournamentMode": "锦标赛模式",
    "hub.tournamentModeSub": "即将推出",
    "hub.howToPlay": "怎么玩",
    "hub.howToPlaySub": "与香音一起的新手实战教学",
    "hub.missions": "任务",
    "hub.dailyBoosters": "每日补充包：今日剩余 {remaining} / {limit}（JST）",
    "hub.daily": "每日补充包：今日剩余 {remaining} / {limit}（JST）",
    "hub.dailyWelcomeBonus": "（欢迎奖励！）",
    "hub.dailyBonus": "（欢迎奖励！）",
    "hub.rankLine": "ELO {elo} · {wins}胜-{losses}负 · {winPct}% 胜率",
    "hub.rankedPrCount": "{remaining} / {limit}",
    "hub.rankedPrTitle": "今日剩余排名 PR 奖励：{remaining} / {limit}（JST）",
    "hub.signedIn": "已登录",
    "hub.signedInAs": "已登录",
    "hub.signedInAsHtml": "以 <b>{name}</b> 登录",
    "auth.signInDiscord": "使用 Discord 登录",
    "auth.checking": "正在检查 Discord 登录…",
    "auth.signingIn": "正在登录…",
    "language.zh": "简体中文",
    "language.label": "语言",
}


def leaves(d, p=""):
    for k, v in d.items():
        path = f"{p}.{k}" if p else k
        if isinstance(v, dict):
            yield from leaves(v, path)
        else:
            yield path, str(v)


def set_leaf(root: dict, dotted: str, value: str) -> None:
    parts = dotted.split(".")
    cur = root
    for p in parts[:-1]:
        cur = cur.setdefault(p, {})
    cur[parts[-1]] = value


def restore_placeholders(en_v: str, zh_v: str) -> str:
    """Replace MT-translated {placeholders} with English keys from en_v, by order."""
    en_ph = re.findall(r"\{([^}]+)\}", en_v)
    zh_ph = re.findall(r"\{([^}]+)\}", zh_v)
    if not en_ph or en_ph == zh_ph:
        return zh_v
    if len(en_ph) != len(zh_ph):
        # Fall back: put EN string structure with naive translate left as-is where possible
        # Prefer exact EN template and try simple surrounding swap later
        out = zh_v
        for ep, zp in zip(en_ph, zh_ph):
            out = out.replace("{" + zp + "}", "{" + ep + "}", 1)
        # If counts differ, also force-insert missing by rewriting from EN skeleton later
        remaining_en = [p for p in en_ph if "{" + p + "}" not in out]
        if remaining_en or set(re.findall(r"\{([^}]+)\}", out)) != set(en_ph):
            # Rebuild: take zh text and substitute placeholders positionally
            parts = re.split(r"\{[^}]+\}", zh_v)
            out = parts[0]
            for i, ep in enumerate(en_ph):
                out += "{" + ep + "}"
                if i + 1 < len(parts):
                    out += parts[i + 1]
                elif i + 1 == len(parts) - 0:
                    pass
            # Append leftover zh tails if zh had fewer placeholders
            if len(parts) > len(en_ph) + 1:
                out += "".join(parts[len(en_ph) + 1 :])
        return out
    out = zh_v
    for ep, zp in zip(en_ph, zh_ph):
        if ep != zp:
            out = out.replace("{" + zp + "}", "{" + ep + "}", 1)
    return out


def main() -> None:
    en_m = dict(leaves(EN))
    for k, en_v in en_m.items():
        # ensure path exists
        cur = zh
        missing = False
        for p in k.split(".")[:-1]:
            if p not in cur or not isinstance(cur[p], dict):
                missing = True
                break
            cur = cur[p]
        if missing:
            continue
        leaf = k.split(".")[-1]
        if leaf not in cur:
            continue
        cur[leaf] = restore_placeholders(en_v, str(cur[leaf]))

    for k, v in OVERRIDES.items():
        set_leaf(zh, k, v)

    ZH_PATH.write_text(json.dumps(zh, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # Verify placeholders
    zh2 = json.loads(ZH_PATH.read_text(encoding="utf-8"))
    zh_m = dict(leaves(zh2))
    bad = []
    for k, en_v in en_m.items():
        if k not in zh_m:
            continue
        if set(re.findall(r"\{([^}]+)\}", en_v)) != set(re.findall(r"\{([^}]+)\}", zh_m[k])):
            bad.append(k)
    print("wrote", ZH_PATH, "placeholder mismatches left", len(bad))
    if bad:
        print(bad[:20])


if __name__ == "__main__":
    main()
