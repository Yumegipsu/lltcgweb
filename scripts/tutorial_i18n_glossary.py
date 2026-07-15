"""Shared glossary + leak detection for tutorial locale JSON (es / future locales)."""

from __future__ import annotations

import re

# UI keys inside i18n "tutorial" block — not step dialogue.
TUTORIAL_UI_KEYS = frozenset({"exitTitle", "back", "next", "finish"})

# Skill bracket labels (EN → es-419), aligned with i18n.js skillKw.*.title.
SKILL_BRACKET_EN_TO_ES: dict[str, str] = {
    "[On Enter]": "[Al entrar]",
    "[On Leave]": "[Al salir]",
    "[Live Start]": "[Inicio de Live]",
    "[Live Success]": "[Éxito de Live]",
    "[Activated]": "[Activada]",
    "[Always]": "[Siempre]",
    "[Automatic]": "[Automático]",
    "[Auto]": "[Automático]",
    "[Once per Turn]": "[Una vez por turno]",
    "[Once per turn]": "[Una vez por turno]",
    "[Center]": "[Centro]",
    "[Yell]": "[Yell]",
}

# Longest-first EN → es-419 game terms for tutorial dialogue (**bold** and plain).
TUTORIAL_EN_TO_ES: list[tuple[str, str]] = [
    ("two-player", "dos jugadores"),
    ("You'll recruit", "Reclutarás"),
    ("outshine", "superar a"),
    ("your opponent", "tu oponente"),
    ("Win condition", "Condición de victoria"),
    ("Successfully perform", "Presenta con éxito"),
    ("successfully", "con éxito"),
    ("This game uses three types of cards", "Este juego usa tres tipos de cartas"),
    ("This game uses", "Este juego usa"),
    ("Activatable skills", "Habilidades activables"),
    ("success storage", "almacenamiento de éxito"),
    ("Live successful card storage", "almacenamiento de cartas Live exitosas"),
    ("Live exitoso card storage", "almacenamiento de cartas Live exitosas"),
    ("End Main Phase", "Terminar Fase principal"),
    ("End Main", "Terminar Fase principal"),
    ("Main Phase", "Fase principal"),
    ("main phase", "Fase principal"),
    ("Live Phase", "Fase Live"),
    ("live phase", "Fase Live"),
    ("Performance Phase", "Fase de presentación"),
    ("Performance!", "¡Presentación!"),
    ("Performance", "Presentación"),
    ("LIVE START", "Inicio de Live"),
    ("Live Judge", "Juez de Live"),
    ("Success pile", "Pila de éxito"),
    ("Success Live", "Live exitoso"),
    ("Success Lives", "Lives exitosos"),
    ("Success", "éxito"),
    ("Live storage", "almacenamiento Live"),
    ("Live cards", "cartas Live"),
    ("Live card", "carta Live"),
    ("Member cards", "cartas de Miembro"),
    ("Member card", "carta de Miembro"),
    ("Energy cards", "cartas de Energía"),
    ("Energy card", "carta de Energía"),
    ("Energy deck", "Mazo de Energía"),
    ("Main deck", "Mazo principal"),
    ("Waiting Room", "Sala de espera"),
    ("Blade Hearts", "Corazones de Blade"),
    ("Blade Heart", "Corazón de Blade"),
    ("school idols", "idols escolares"),
    ("coin flip", "lanzamiento de moneda"),
    ("baton pass", "relevo"),
    ("Go first", "Ir primero"),
    ("You go first", "Ir primero"),
    ("Keep Hand", "Conservar mano"),
    ("Keep hand", "Conservar mano"),
    ("Members", "Miembros"),
    ("Member", "Miembro"),
    ("Hearts", "Corazones"),
    ("Heart", "Corazón"),
    ("Energy", "Energía"),
    ("Blades", "Blades"),
    ("Blade", "Blade"),
    ("Stage", "Escenario"),
    ("mulligan", "muligan"),
    ("Yell", "Yell"),
    ("Lives", "Lives"),
    ("Live", "Live"),
    ("Center", "Centro"),
    ("Left", "Izquierda"),
    ("Right", "Derecha"),
    ("Turn", "Turno"),
    ("score", "puntuación"),
    ("You may", "Puedes"),
    ("you may", "puedes"),
]

# Skill bracket labels (EN → ko), aligned with i18n.js skillKw.*.title (ko) and the
# cards.json text_ko bracket convention ([등장 시], [라이브 개시], [기동], [상시], [자동], …).
SKILL_BRACKET_EN_TO_KO: dict[str, str] = {
    "[On Enter]": "[등장 시]",
    "[On Leave]": "[퇴장 시]",
    "[Live Start]": "[라이브 개시]",
    "[Live Success]": "[라이브 성공]",
    "[Activated]": "[기동]",
    "[Always]": "[상시]",
    "[Automatic]": "[자동]",
    "[Auto]": "[자동]",
    "[Once per Turn]": "[턴당 1회]",
    "[Once per turn]": "[턴당 1회]",
    "[Center]": "[센터]",
    "[Yell]": "[Yell]",
    "[Left]": "[왼쪽]",
    "[Left Side]": "[왼쪽]",
    "[Right]": "[오른쪽]",
    "[Right Side]": "[오른쪽]",
}

SKILL_BRACKET_EN_TO_ZH: dict[str, str] = {
    "[On Enter]": "[入场时]",
    "[On Leave]": "[离场时]",
    "[Live Start]": "[Live开始]",
    "[Live Success]": "[Live成功]",
    "[Activated]": "[起动]",
    "[Always]": "[永续]",
    "[Automatic]": "[自动]",
    "[Auto]": "[自动]",
    "[Once per Turn]": "[每回合1次]",
    "[Once per turn]": "[每回合1次]",
    "[Twice per Turn]": "[每回合2次]",
    "[Twice per turn]": "[每回合2次]",
    "[Center]": "[中央]",
    "[Yell]": "[Yell]",
    "[Left]": "[左侧]",
    "[Left Side]": "[左侧]",
    "[Right]": "[右侧]",
    "[Right Side]": "[右侧]",
}

# Longest-first EN → zh-Hans game terms for tutorial dialogue.
TUTORIAL_EN_TO_ZH: list[tuple[str, str]] = [
    ("two-player", "双人"),
    ("You'll recruit", "你要招募"),
    ("outshine", "胜过"),
    ("your opponent", "对手"),
    ("Win condition", "胜利条件"),
    ("Successfully perform", "成功演出"),
    ("successfully", "成功"),
    ("This game uses three types of cards", "本游戏使用三种卡牌"),
    ("This game uses", "本游戏使用"),
    ("Activatable skills", "可发动的技能"),
    ("Success Live card storage", "成功Live区"),
    ("success storage", "成功区"),
    ("Live successful card storage", "成功Live卡区"),
    ("Live card storage", "Live存放区"),
    ("Live Storage", "Live存放区"),
    ("Live storage", "Live存放区"),
    ("End Main Phase", "结束主要阶段"),
    ("End Main", "结束主要阶段"),
    ("Main Phase", "主要阶段"),
    ("main phase", "主要阶段"),
    ("Live Phase", "Live阶段"),
    ("live phase", "Live阶段"),
    ("Performance Phase", "表演阶段"),
    ("Performance!", "表演！"),
    ("Performance", "表演"),
    ("LIVE START", "Live开始"),
    ("Live Judge", "Live判定"),
    ("Success pile", "成功区"),
    ("Success Lives", "成功Live"),
    ("Success Live", "成功Live"),
    ("Success", "成功"),
    ("Baton Passes", "进行接棒"),
    ("Baton Pass", "接棒"),
    ("baton pass", "接棒"),
    ("Member cards", "成员卡"),
    ("Member card", "成员卡"),
    ("Energy cards", "能量卡"),
    ("Energy card", "能量卡"),
    ("Energy deck", "能量牌组"),
    ("Energy Deck", "能量牌组"),
    ("Main deck", "主牌组"),
    ("Main Deck", "主牌组"),
    ("Waiting Room", "等候室"),
    ("Blade hearts", "Blade心形"),
    ("Blade Hearts", "Blade心形"),
    ("Blade heart", "Blade心形"),
    ("school idols", "学园偶像"),
    ("coin flip", "抛硬币"),
    ("You go first", "你先攻"),
    ("Go first", "先攻"),
    ("Keep Hand", "保留手牌"),
    ("Keep hand", "保留手牌"),
    ("Members", "成员"),
    ("Member", "成员"),
    ("Hearts", "心形"),
    ("Heart", "心形"),
    ("Energy", "能量"),
    ("Blades", "Blade"),
    ("Blade", "Blade"),
    ("Stage", "舞台"),
    ("mulligan", "换牌"),
    ("Yell", "Yell"),
    ("Lives", "Live"),
    ("Live", "Live"),
    ("Center", "中央"),
    ("Left", "左侧"),
    ("Right", "右侧"),
    ("Turn", "回合"),
    ("score", "分数"),
    ("You may", "你可以"),
    ("you may", "你可以"),
]

# Longest-first EN → ko game terms for tutorial dialogue (**bold** and plain).
# Mirrors the curated locales/ko.json tutorial.* conventions: Live / Blade / Yell stay
# as Latin loanwords, other game terms are translated.
TUTORIAL_EN_TO_KO: list[tuple[str, str]] = [
    ("two-player", "2인용"),
    ("You'll recruit", "영입하는"),
    ("outshine", "앞서 나가는"),
    ("your opponent", "상대"),
    ("Win condition", "승리 조건"),
    ("Successfully perform", "성공적으로 클리어하는"),
    ("successfully", "성공적으로"),
    ("This game uses three types of cards", "이 게임에는 세 종류의 카드가 있어요"),
    ("This game uses", "이 게임은"),
    ("Activatable skills", "발동 가능한 스킬"),
    ("Success Live card storage", "성공 Live 더미"),
    ("success storage", "성공 더미"),
    ("Live successful card storage", "성공 Live 카드 더미"),
    ("Live card storage", "Live 카드 스토리지"),
    ("Live Storage", "Live 스토리지"),
    ("Live storage", "Live 스토리지"),
    ("End Main Phase", "메인 페이즈 종료"),
    ("End Main", "메인 종료"),
    ("Main Phase", "메인 페이즈"),
    ("main phase", "메인 페이즈"),
    ("Live Phase", "Live 페이즈"),
    ("live phase", "Live 페이즈"),
    ("Performance Phase", "퍼포먼스 페이즈"),
    ("Performance!", "퍼포먼스!"),
    ("Performance", "퍼포먼스"),
    ("Live Judge", "Live 심판"),
    ("Success pile", "성공 더미"),
    ("Success Lives", "성공 Live"),
    ("Success Live", "성공 Live"),
    ("Success", "성공"),
    ("Baton Passes", "바톤 터치를 해요"),
    ("Baton Pass", "바톤 터치"),
    ("baton pass", "바톤 터치"),
    ("Member cards", "멤버 카드"),
    ("Member card", "멤버 카드"),
    ("Energy cards", "에너지 카드"),
    ("Energy card", "에너지 카드"),
    ("Energy deck", "에너지 덱"),
    ("Energy Deck", "에너지 덱"),
    ("Main deck", "메인 덱"),
    ("Main Deck", "메인 덱"),
    ("Waiting Room", "대기실"),
    ("Blade hearts", "Blade 하트"),
    ("Blade heart", "Blade 하트"),
    ("school idols", "스쿨 아이돌"),
    ("coin flip", "코인 플립"),
    ("You go first", "선공이에요"),
    ("Go first", "선공"),
    ("Keep Hand", "손패 유지"),
    ("Keep hand", "손패 유지"),
    ("Members", "멤버"),
    ("Member", "멤버"),
    ("Hearts", "하트"),
    ("Heart", "하트"),
    ("Energy", "에너지"),
    ("Blades", "Blade"),
    ("Blade", "Blade"),
    ("Stage", "스테이지"),
    ("mulligan", "멀리건"),
    ("Yell", "Yell"),
    ("Lives", "Live"),
    ("Live", "Live"),
    ("Center", "센터"),
    ("Left", "왼쪽"),
    ("Right", "오른쪽"),
    ("Turn", "턴"),
    ("score", "스코어"),
    ("You may", "해도 돼요"),
    ("you may", "해도 돼요"),
]

# Substrings that must stay as-is (character / group / song / product names).
PROTECTED_LITERALS: tuple[str, ...] = (
    "Shibuya Kanon",
    "Shiki Wakana",
    "Rin Hoshizora",
    "Mei Yoneme",
    "Love Live! Official Card Game",
    "Liella!",
    "Liella",
    "μ's",
    "WE WILL!!",
    "START:DASH!!",
    "Watashi no Symphony ~Shibuya Kanon Ver.~",
    "Mirai wa Kaze no You ni",
    "Kitto Seishun ga Kikoeru",
    "Korekara no Someday",
    "Kinako",
    "Honoka",
    "Umi Sonoda",
    "Nico",
    "Ren",
    "Keke",
    "Mei",
    "Shiki",
    "Yell",
    "Blade",
    "LIVE",
)

_PLACEHOLDER = "\ufffd"

MIXED_EN_RE = re.compile(
    r"\b(?:This is|You'll|You'll|When you|Normally your|Both players|During your|"
    r"Some Members|Choose wisely|Keep track|Completing a|face-down in storage)\b",
    re.I,
)


def has_mixed_english(text: str) -> bool:
    return bool(MIXED_EN_RE.search(text or ""))


EN_TUTORIAL_LEAK_RE = re.compile(
    r"\*\*(?:Member|Members|Energy|Hearts?|Blade|Main Phase|Live Phase|Performance|Stage|"
    r"Success pile|mulligan|baton pass|Go first|End Main|Confirm Live|LIVE START|Live Judge|Waiting Room)\*\*"
    r"|\b(?:Member cards?|Members|Hearts?|Blade Hearts?|Main Phase|Live Phase|Performance Phase|"
    r"Success pile|baton pass|Live Judge|LIVE START|End Main|Confirm Live|Go first|Waiting Room|"
    r"school idols|Win condition|This game uses|Activatable skills)\b",
    re.I,
)


def _protect(text: str) -> tuple[str, list[str]]:
    saved: list[str] = []
    out = text
    for lit in sorted(PROTECTED_LITERALS, key=len, reverse=True):
        if lit in out:
            idx = len(saved)
            saved.append(lit)
            out = out.replace(lit, f"{_PLACEHOLDER}{idx}{_PLACEHOLDER}")
    return out, saved


def _restore(text: str, saved: list[str]) -> str:
    out = text
    for idx, lit in enumerate(saved):
        out = out.replace(f"{_PLACEHOLDER}{idx}{_PLACEHOLDER}", lit)
    return out


SKILL_BRACKETS_BY_LOCALE: dict[str, dict[str, str]] = {
    "es": SKILL_BRACKET_EN_TO_ES,
    "ko": SKILL_BRACKET_EN_TO_KO,
    "zh": SKILL_BRACKET_EN_TO_ZH,
}

TUTORIAL_GLOSSARY_BY_LOCALE: dict[str, list[tuple[str, str]]] = {
    "es": TUTORIAL_EN_TO_ES,
    "ko": TUTORIAL_EN_TO_KO,
    "zh": TUTORIAL_EN_TO_ZH,
}


def localize_skill_brackets(text: str, locale: str = "es") -> str:
    brackets = SKILL_BRACKETS_BY_LOCALE.get(locale, SKILL_BRACKET_EN_TO_ES)
    out = text
    for en, loc in sorted(brackets.items(), key=lambda p: len(p[0]), reverse=True):
        out = out.replace(en, loc)
    return out


def translate_tutorial_line(text: str, locale: str = "es") -> str:
    if not text or not text.strip():
        return text
    glossary = TUTORIAL_GLOSSARY_BY_LOCALE.get(locale, TUTORIAL_EN_TO_ES)
    protected, vault = _protect(text)
    out = protected
    for en, loc in sorted(glossary, key=lambda p: len(p[0]), reverse=True):
        out = re.sub(re.escape(en), loc, out, flags=re.IGNORECASE)
    out = _restore(out, vault)
    return localize_skill_brackets(out, locale)


def has_english_tutorial_leak(text: str) -> bool:
    if not text:
        return False
    protected, vault = _protect(text)
    leak = bool(EN_TUTORIAL_LEAK_RE.search(protected))
    _restore(protected, vault)
    return leak


# Hand-curated step dialogue when glossary auto-pass from EN still leaves English prose.
# Character / group / song names stay as-is; game terms must be localized.
TUTORIAL_EXACT_OVERRIDES: dict[str, dict[str, str]] = {
    "es": {
        "t1_perf_intro": (
            "Ambos jugadores terminan la Fase Live — ¡comienza la **Presentación**! "
            "Si alguno colocó Lives, verás la pantalla **Inicio de Live**. "
            "¡Aquí se decide si los Lives tienen éxito o no!"
        ),
        "t1_hearts_check": (
            "Aquí puedes comprobar si las cartas del Escenario cumplen los Corazones requeridos de este Live. "
            "**Shiki Wakana** solo aporta **1 Corazón morado**, así que aún faltan **1 Corazón rojo** y **1 Corazón de cualquier color**."
        ),
        "t1_hearts_grey": (
            'Los Corazones **grises / cualquier color** cuentan como **cualquier color** — con rojo y morado cubiertos, '
            'los Corazones restantes pueden llenar el espacio "cualquier" de **WE WILL!!**.'
        ),
        "t1_yell": (
            'Aunque un Live parezca perdido, aún no termina… aquí entra **Yell**. '
            "Usa el valor **Blade** de las cartas — Liella! hace \"Yell\" y roba cartas extra del mazo según el total de **Blade** en el Escenario. "
            "**Shiki Wakana** tiene **Blade 2**, ¡así que roba 2 cartas del mazo!"
        ),
        "t1_yell_hearts": (
            "¡Las cartas de Natsumi sumaron 2! Las cartas de Yell añaden Corazones de lado (**Blade Corazones**) al total. "
            "Dos **Corazones rojos** cubren **rojo 1** y **cualquier 1**. **¡El Live tiene éxito!**"
        ),
        "t1_success": (
            "Piensa en **Yell** como el grito de apoyo del público — es clave para cumplir Corazones difíciles. "
            "Ten en cuenta los valores **Blade** del Escenario — ¡el Live **WE WILL!!** **tuvo éxito**!"
        ),
        "t1_yell_opp": (
            "Siguiente **Yell** de μ's — **Blade 1** en el Escenario voltea **1** carta. "
            "**Nico** no tiene Blade Corazones — **Kitto Seishun ga Kikoeru** aún no puede tener éxito."
        ),
        "t1_fail": (
            "μ's no pudo cumplir el costo de Corazones de **Kitto Seishun ga Kikoeru** — ese **Live** va a la **Sala de espera**."
        ),
        "t1_judge": "¡Liella! gana por puntuación y obtiene un **Live exitoso**!",
        "t2_perf_intro": "Ambos confirmaron — ¡otra **Presentación**! Los Lives se revelan de nuevo.",
        "t2_live_start_offer": (
            "**[Inicio de Live]** — **START:DASH!!** permite a μ's **mirar las 3 cartas superiores** de su mazo y reordenarlas "
            "antes de continuar la Presentación. Este aviso demuestra el efecto **[Inicio de Live]**."
        ),
        "t2_yell_mine": (
            "Primero tu **Yell** — **Blade 3** en el Escenario voltea **3** cartas. Aparecieron **Ren**, **Keke** y **Mei**, "
            "pero ninguna tiene **Blade Corazones** — **Watashi no Symphony ~Shibuya Kanon Ver.~** necesita **rojo 4**, **morado 4** y **cualquier 3**, "
            "así que ese **Live** **falla**."
        ),
        "t2_yell_opp": (
            "Siguiente **Yell** de μ's — la primera carta es **Korekara no Someday** con **Blade Corazones** de TODOS los colores "
            "(cuentan como cualquier color y cubren lo que falta de **START:DASH!!** — aquí **amarillo**). "
            "La segunda, **Rin**, no tiene Blade Corazones."
        ),
        "t2_outcomes": (
            "Ambos Lives se resolvieron — los exitosos quedan en la pila de éxito y los fallidos van a la **Sala de espera**."
        ),
        "t2_judge": (
            "Solo **μ's** logró **START:DASH!!** con éxito — tu Live falló, así que **Watashi no Symphony ~Shibuya Kanon Ver.~** "
            "va a la **Sala de espera**. ¡μ's obtiene un **Live exitoso**!"
        ),
        "t3_perf_intro": "Ambos confirmaron — ¡la **Presentación** final!",
        "t3_yell_mine": (
            "Primero el **Yell** de μ's — voltea cartas del mazo por **Blade Corazones**. "
            "**START:DASH!!** ya coincide con el Escenario (**rosa**, **amarillo** y **morado** de **Rin Hoshizora**, **Honoka** y **Umi**)."
        ),
        "t3_yell_opp": (
            "Siguiente tu **Yell** — **Shiki Wakana** en **Centro** (**Blade 2**) y **Mei Yoneme** en **Derecha** (**Blade 1**) voltean **3** cartas. "
            "**Mirai wa Kaze no You ni** trata esos Corazones de Yell como **cualquier** color — ¡el **Live** **tiene éxito** junto con el Escenario!"
        ),
        "t3_outcomes": (
            "¡Ambos Lives **tuvieron éxito**! En este caso un desempate decide al ganador — ¡hora del **Juez de Live**!"
        ),
        "t3_judge": (
            "**Juez de Live** — ¡μ's gana con puntuación potenciada! Otro **Live exitoso** más cerca de la victoria en la partida."
        ),
    },
    # Korean tutorial dialogue is fully curated in locales/ko.json (tutorial.* block) — no
    # exact overrides needed. Add step-specific overrides here only if the glossary pass
    # of a curated locales/ko.json line ever leaves an English leak.
    "ko": {},
    "zh": {},
}


def tutorial_exact_override(locale: str, step_id: str) -> str | None:
    block = TUTORIAL_EXACT_OVERRIDES.get(locale) or {}
    val = block.get(step_id)
    return val if val and val.strip() else None
