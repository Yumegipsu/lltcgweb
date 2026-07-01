/* Love Live TCG web — i18n module */
(function () {
  'use strict';

  var LLTCG_LOCALE_KEY = 'lltcg_locale';
  var LOCALES = ['en', 'ja'];
  var localeChangeCallbacks = [];
  var _tutorialJa = null;

  var STRINGS = {
  "en": {
    "logo": {
      "tagline": "The Unofficial Web Player"
    },
    "auth": {
      "checking": "Checking Discord sign-in…",
      "signInDiscord": "Sign in with Discord",
      "guestTimeout": "Sign-in check timed out — play unranked, or retry Discord sign-in."
    },
    "menu": {
      "unrankedPlay": "Unranked Play",
      "unrankedSub": "Rooms, friends, or practice vs CPU",
      "deckExperiment": "Deck Experiment",
      "deckExperimentSub": "Build with every card — guests only, unranked",
      "howToPlay": "How to Play",
      "howToPlaySub": "Interactive rules walkthrough"
    },
    "hub": {
      "signedIn": "Signed in",
      "signedInAs": "Signed in as",
      "signedInAsHtml": "Signed in as <b>{name}</b>",
      "dailyBoosters": "Daily boosters: {remaining} / {limit} remaining today (JST)",
      "dailyWelcomeBonus": " (welcome bonus!)",
      "daily": "Daily boosters: {remaining} / {limit} remaining today (JST)",
      "dailyBonus": " (welcome bonus!)",
      "rankLine": "ELO {elo} · {wins}W-{losses}L · {winPct}% win",
      "options": "Options",
      "signOut": "Sign out",
      "openBoosters": "Open Boosters",
      "openBoostersSub": "Open card booster packs",
      "deckBuilder": "Deck Builder",
      "deckBuilderSub": "Edit presets and ranked loadout",
      "rankedPvp": "Ranked PvP",
      "rankedPvpSub": "Climb ELO in matchmade games",
      "leaderboard": "Leaderboard",
      "leaderboardSub": "View the online rankings",
      "unranked": "Unranked Play",
      "unrankedSub": "Rooms, friends, or practice vs CPU",
      "howToPlay": "How to Play",
      "howToPlaySub": "Interactive rules walkthrough",
      "backHub": "← Hub"
    },
    "language": {
      "label": "Language"
    },
    "lobby": {
      "title": "Unranked Play",
      "yourName": "Your Name",
      "namePlaceholder": "Idol name…",
      "deck": "Deck",
      "createRoom": "Create Room",
      "joinRoom": "Join Room",
      "roomCode": "Room Code",
      "vsPlayer": "VS Player",
      "vsCpu": "VS CPU",
      "practiceCpu": "Practice vs CPU",
      "cpuDifficulty": "CPU Difficulty",
      "cpuEasy": "Easy — random starter deck",
      "cpuNormal": "Normal — smarter skills & Lives",
      "cpuHard": "Hard — strong deck & skill priority",
      "findRandomMatch": "Find Random Match",
      "spectate": "Spectate Match",
      "cancelSearch": "Cancel search",
      "phaseTimer": "Phase timer (Main & LIVE)",
      "phaseTimerSec": "Seconds per phase (10–120)",
      "backHub": "← Hub",
      "orJoinFriend": "or join a friend",
      "orMatchRandomly": "or match randomly",
      "casualHint": "Casual PvP — no ELO or ranked record",
      "experimentDeckPassword": "Experiment deck password",
      "experimentPasswordPlaceholder": "8-letter code",
      "experimentDeckBtn": "Deck Experiment",
      "experimentDeckHint": "Build in Deck Experiment, generate a password, then enter it here — or pick a saved deck below.",
      "secondsLabel": "{n}s",
      "casualQueueStats": "{waiting} waiting · {inGame} in casual games",
      "casualSearching": "Searching for opponent… ({seconds}s)"
    },
    "deck": {
      "title": "Deck Builder",
      "experimentTitle": "Deck Experiment",
      "deckName": "Deck name",
      "presetSlot": "Preset slot (max 10)",
      "search": "Search cards",
      "searchPlaceholder": "Name, ID, or rules text…",
      "collection": "Collection",
      "currentDeck": "Current deck",
      "savePreset": "Save preset",
      "equipRanked": "Equip ranked",
      "autoBuild": "Auto-build",
      "clear": "Clear",
      "hint": "Auto-build optimizes from your collection · tap to add/remove · hover to preview",
      "hoverEmpty": "Hover a deck card to preview it here.",
      "backHub": "← Hub",
      "backMenu": "← Menu",
      "deckPassword": "Deck password",
      "deckPasswordPlaceholder": "Enter password to load",
      "load": "Load",
      "savedPassword": "Saved password:",
      "copy": "Copy",
      "cardPool": "Card pool",
      "resetStarter": "Reset starter",
      "useStarter": "Use starter",
      "randomDeck": "Random deck",
      "updateSavedDeck": "Update saved deck",
      "generatePassword": "Generate password",
      "experimentHint": "Full card pool · build a legal deck to generate a password · hold or right-click cards for details",
      "collectionOwned": "Total cards owned · {count}",
      "collectionLoading": "Full card pool · loading…",
      "collectionMatch": "Collection · {match} match",
      "deckStats": "Total {total}/72 · Members {members}/48 · Lives {lives}/12 · Energy {energy}/12",
      "deckEmpty": "Tap cards from your collection to build a deck.",
      "deckEmptyExperiment": "Tap cards from the pool to build a legal 60+12 deck.",
      "experimentStarterTitle": "Choose starter deck",
      "experimentStarterLead": "Load an official starter list as your base — edit freely from the full card pool.",
      "accountStarterHint": "Your account starter: {starter} · preset #1 starts from this list.",
      "noStarterOnAccount": "No starter deck on this account.",
      "loadedStarterIntoPreset": "Loaded {name} into preset #{slot}.",
      "loadedStarterFallbackName": "starter deck",
      "chooseStarterFirst": "Choose a starter deck first.",
      "building": "Building…",
      "autoBuiltSuccess": "Auto-built a legal deck from your collection.",
      "starterDecksNotLoaded": "Starter decks not loaded yet.",
      "equippedRanked": "Equipped for ranked.",
      "filters": {
        "title": "Filters",
        "showAdvanced": "Show Advanced Filters",
        "hideAdvanced": "Hide Advanced Filters",
        "all": "All",
        "allTypes": "All types",
        "allFields": "All fields",
        "allProducts": "All products",
        "allSets": "All sets",
        "any": "Any",
        "min": "Min",
        "max": "Max",
        "notIncluded": "Not included",
        "heartAll": "All",
        "drawIcon": "Draw icon",
        "scoreIcon": "Score icon",
        "label": {
          "type": "Type",
          "group": "Group",
          "rarity": "Rarity",
          "keywordIn": "Keyword in",
          "product": "Product",
          "productSet": "Product set",
          "subunit": "Subunit",
          "parallel": "Parallel",
          "printedHearts": "Printed hearts",
          "requiredHearts": "Required hearts",
          "bladeHearts": "Blade hearts",
          "blade": "Blade",
          "cost": "Cost",
          "score": "Score"
        },
        "type": {
          "member": "Member",
          "live": "Live",
          "energy": "Energy"
        },
        "searchMode": {
          "all": "All fields",
          "name": "Name",
          "text": "Text",
          "id": "Card ID"
        },
        "searchPlaceholder": {
          "all": "Name, ID, or rules text…",
          "name": "Card name…",
          "text": "Rules text…",
          "id": "Card ID e.g. PL!N-sd1-021-SD"
        },
        "productKind": {
          "bp": "Booster Pack",
          "pb": "Premium Booster",
          "pb_duo": "Premium Booster (DUO)",
          "sd": "Starter Deck",
          "collection": "Collection",
          "pr": "PR"
        },
        "parallel": {
          "normal": "Normal only",
          "parallel": "Parallel only"
        },
        "groups": {
          "mus": "μ's",
          "nijigasaki": "Nijigasaki",
          "sunshine": "Sunshine",
          "superstar": "Superstar",
          "hasunosora": "Hasunosora",
          "saintsnow": "Saint Snow",
          "arise": "A-RISE",
          "sunnypassion": "Sunny Passion"
        },
        "sort": {
          "aria": "Collection sort and order",
          "sortBy": "Sort by",
          "order": "Order",
          "asc": "Ascending",
          "desc": "Descending",
          "id": "Card ID",
          "rarity": "Rarity",
          "name": "Name (idol)",
          "type": "Card type",
          "group": "Group / school",
          "recent": "Recently acquired"
        }
      }
    },
    "booster": {
      "title": "Open Boosters",
      "openPack": "Open Pack (5 cards)",
      "packOpened": "Pack Opened",
      "godPack": "GOD PACK!",
      "openAnother": "Open another pack",
      "openSameAgain": "Open same again",
      "packsLeft": "{n} pack(s) left today (JST)",
      "mainMenu": "Main menu",
      "backHub": "← Hub"
    },
    "ranked": {
      "title": "Ranked PvP",
      "findMatch": "Find Match",
      "cancelSearch": "Cancel search",
      "spectate": "Spectate Match",
      "timerNote": "Main & LIVE phases use a 120s timer.",
      "deckLabel": "Ranked deck",
      "matchSound": "Play sound when match is found",
      "leaderboard": "Leaderboard",
      "leaderboardTitle": "Ranked Leaderboard",
      "backHub": "← Hub",
      "infoLine": "ELO {elo} · {record}",
      "record": "{wins}W-{losses}L · {winPct}% win",
      "recordFull": "{wins}W-{losses}L · {winPct}% win · {lossPct}% loss",
      "queueStats": "{waiting} waiting · {inGame} in ranked games",
      "searching": "Searching… ({seconds}s)",
      "readySearch": "Ready to search"
    },
    "leaderboard": {
      "title": "Ranked Leaderboard",
      "lead": "Highest ELO from ranked PvP. Set a card banner for your profile row.",
      "empty": "No ranked games yet — play ranked PvP to appear here.",
      "editBanner": "Edit profile banner",
      "eloSuffix": " ELO",
      "eloLabel": "{elo} ELO",
      "profileBanner": "Profile banner",
      "bannerLead": "Choose a card you own, then drag the strip vertically to pick the art shown on your leaderboard card.",
      "bannerSearchPlaceholder": "Search by card name…",
      "bannerNoMatch": "No cards match your search.",
      "bannerPreview": "Preview",
      "saveBanner": "Save banner",
      "selectCardFirst": "Select a card first"
    },
    "options": {
      "title": "Options",
      "enhancedTextures": "Enhanced textures on high rarity cards",
      "soundEffects": "Sound effects",
      "sfxVolume": "SFX volume",
      "stuckTitle": "Stuck in a match?",
      "stuckLead": "If ranked reconnects you to a broken or finished game, leave the active match record here. This counts as a resign if the game is still in progress.",
      "resetTitle": "Reset account",
      "resetLead": "Delete all collection cards, deck presets, ranked stats, and booster progress. You will choose a new starter deck and begin again. This cannot be undone.",
      "resetAccount": "Reset account",
      "backHub": "← Hub"
    },
    "starter": {
      "title": "Choose Your Starter Deck",
      "lead": "Pick one official start deck as your collection base. This choice is permanent.",
      "confirm": "Confirm Starter"
    },
    "waiting": {
      "roomCreated": "Room Created!",
      "shareCode": "Share this code with your opponent:",
      "tapCopy": "Tap to copy 📋",
      "clickCopy": "Click to copy",
      "waitingOpponent": "Waiting for opponent to join…",
      "cancel": "Cancel",
      "phaseTimerInfo": "Phase timer: {sec}s per Main & LIVE turn"
    },
    "game": {
      "you": "You",
      "opponent": "Opponent",
      "opp": "Opp",
      "gameLog": "Game Log",
      "resign": "🏳 Resign",
      "enableRadio": "📻 Enable Radio",
      "endMainPhase": "End Main Phase",
      "endLivePhase": "End LIVE Phase",
      "setLiveCards": "Set Live Cards",
      "waitingOpponent": "Waiting for Opponent",
      "resolveSkillFirst": "Resolve skill first",
      "waitingSkill": "Waiting for skill",
      "yourHand": "Your hand",
      "mainDeck": "Main Deck",
      "waitingRoom": "Waiting Room",
      "oppWaitingRoom": "Opponent Waiting Room",
      "deckHidden": "Opponent's deck is hidden.",
      "energyDeck": "Energy Deck",
      "liveStorage": "Live Storage",
      "successStorage": "Success Live Storage",
      "stageBoard": "Stage Board",
      "activatableSkills": "Activatable skills",
      "activeEffects": "Active effects",
      "hoverHandEmpty": "Hover a card in your hand to preview it here.",
      "starting": "Starting…",
      "hand": "Hand",
      "wr": "WR",
      "spectating": "Spectating — {p1} vs {p2} (read only)",
      "oppActivatingSkill": "Opponent is activating a skill…",
      "activeEnergy": "active",
      "pickSlot": "Pick a slot",
      "batonPassHint": "Baton Pass — pay {cost} Active Energy",
      "overplayHint": "Overplay — pay {cost} Active Energy",
      "slotLeft": "Left Side",
      "slotCenter": "Center",
      "slotRight": "Right Side",
      "baton": "Baton",
      "batonToggleOn": "Tap for overplay mode",
      "batonToggleOff": "Tap for Baton Pass",
      "opponentSkillWait": "{name} is activating a skill…",
      "perfYou": "You",
      "perfOpp": "Opponent",
      "sidebarInfo": "{turn}<span class=\"turn-sep\">·</span>Phase: {phase}<span class=\"turn-sep\">·</span>Active: {active}<span class=\"turn-sep\">·</span>First: {first}"
    },
    "slot": {
      "left": "Left",
      "center": "Center",
      "right": "Right"
    },
    "phase": {
      "waiting": "Waiting",
      "setup": "Preparation (Mulligan)",
      "main": "Main Phase",
      "main_first": "Main Phase",
      "main_second": "Main Phase",
      "live": "LIVE Phase",
      "live_set": "LIVE Phase",
      "live_set_first": "LIVE Phase",
      "live_set_second": "LIVE Phase",
      "live_start_effects": "Live Start",
      "live_success_effects": "Live Success",
      "performance": "Performance Phase",
      "live_performance_first": "Performance Phase",
      "live_performance_second": "Performance Phase",
      "coinFlip": "Coin Flip",
      "preparation": "Preparation",
      "active": "Active Phase",
      "active_first": "Active Phase",
      "active_second": "Active Phase",
      "live_judge": "Live Win/Loss Check"
    },
    "phaseId": {
      "waiting": "Waiting",
      "coin_flip": "Coin Flip",
      "setup": "Preparation (Mulligan)",
      "active_first": "Active Phase",
      "active_second": "Active Phase",
      "main_first": "Main Phase",
      "main_second": "Main Phase",
      "live_set": "LIVE Phase",
      "live_set_first": "LIVE Phase",
      "live_set_second": "LIVE Phase",
      "live_start_effects": "Live Start",
      "live_success_effects": "Live Success",
      "live_performance_first": "Performance Phase",
      "live_performance_second": "Performance Phase",
      "live_judge": "Live Win/Loss Check"
    },
    "phaseBar": {
      "spectating": "Spectating — {p1} vs {p2} (read only)",
      "setupWaitMulligan": "Waiting for opponent to finish mulligan…",
      "setupMulligan": "Preparation — review your opening hand, mulligan any cards, then confirm.",
      "coinFlip": "Coin flip — winner chooses who goes first…",
      "mainYour": "Your Main Phase — play Members ({energy} available). End Main Phase when ready.",
      "mainOpp": "{name}'s turn — Main Phase…",
      "mainOppS": "{name}' turn — Main Phase…",
      "liveRaised": "LIVE Phase — {count} card raised · tap hand to adjust · confirm with the button below the log",
      "liveRaisedPlural": "LIVE Phase — {count} cards raised · tap hand to adjust · confirm with the button below the log",
      "liveStored": "LIVE Phase — {stored} in storage · place up to {slots} more (Live or Member), or end LIVE Phase below the log",
      "livePlace": "LIVE Phase — place 0–{slots} cards (Live or Member), then end LIVE Phase · button below the log",
      "liveBothLocked": "Both players locked in — Performance starting…",
      "liveYouLocked": "You locked in — waiting for opponent to finish LIVE selection…",
      "liveStartEffects": "Resolve Live Start prompts — optional effects will appear as overlays.",
      "liveSuccessEffects": "Resolve Live Success prompts — optional effects will appear as overlays.",
      "performance": "Performance Phase — Yell · hearts · Live success check",
      "liveJudge": "Live Win/Loss Check Phase…"
    },
    "phaseBanner": {
      "coinFlipTitle": "Coin Flip",
      "coinFlipSub": "Winner chooses who goes first",
      "setupTitle": "Preparation",
      "setupSub": "Optional mulligan (one swap)",
      "activeTitle": "Active Phase",
      "activeSub": "Refresh Energy & Members",
      "mainYour": "Your Main Phase",
      "mainOpp": "{name}'s Main Phase",
      "mainOppS": "{name}' Main Phase",
      "liveTitle": "LIVE Phase",
      "liveSub": "Place 0–3 cards (Live or Member), then end LIVE Phase",
      "liveStartTitle": "Live Start",
      "liveStartSub": "Optional effects before Performance",
      "liveSuccessTitle": "Live Success",
      "liveSuccessSub": "Optional effects after hearts",
      "performanceTitle": "Performance Phase",
      "performanceSub": "Reveal · Yell · Hearts",
      "liveJudgeTitle": "Live Win/Loss Check",
      "liveJudgeSub": "Comparing Live scores…",
      "yourMain": "Your Main Phase",
      "theirMain": "{name}'s Main Phase",
      "theirMainS": "{name}' Main Phase"
    },
    "splash": {
      "turn": "Turn {turn}",
      "turnBegin": "Turn {turn} begins",
      "noLives": "No Lives played this turn",
      "gameStart": "Game Start",
      "deckRefresh": "Deck Refresh",
      "deckRefreshOpp": "{name} — Deck Refresh",
      "deckRefreshSub": "{n} card(s) shuffled from Waiting Room",
      "youAttemptLive": "You Attempt Live!",
      "theyAttemptLive": "{name} Attempts Live",
      "attemptSub": "Drawing Yell · checking hearts",
      "youWait": "You Wait",
      "theyWait": "{name} Waits",
      "youWaitSub": "Live cards stay in storage",
      "theyWaitSub": "Live cards stay in their storage",
      "perfRoundFailed": "{ok} passed hearts · round failed (all Lives must succeed)",
      "perfCleared": "{ok} Live card(s) cleared the round",
      "perfMixed": "{ok} succeeded · {fail} failed hearts → Waiting Room",
      "yourLivePerformance": "Your Live Performance",
      "theirLive": "{name} Live",
      "perfSubYell": "Yell {blades} · {sub}",
      "successLiveYou": "Success Live!",
      "successLiveThey": "{name} — Success Live!",
      "successLiveSubYou": "A Live card joins your successes",
      "successLiveSubThey": "A Live card joins their successes",
      "bothWait": "Both Players Wait",
      "bothWaitSub": "Live cards remain in storage",
      "liveStartFlash": "LIVE START"
    },
    "mulligan": {
      "title": "Opening Hand 🌸",
      "hint": "Tap cards to mark for replacement. Hold a card to view its details. Tap again to unmark.",
      "keepHand": "Keep Hand",
      "replaceCard": "Replace {n} card",
      "replaceCards": "Replace {n} cards"
    },
    "coin": {
      "title": "First Player",
      "flipping": "Flipping coin…",
      "goFirst": "I'll go first",
      "escortFirst": "Escort goes first",
      "opponentFirst": "Opponent goes first",
      "waitingOppFlip": "Waiting for opponent to finish watching the flip…",
      "waitingOpp": "Waiting for opponent…",
      "wonFlip": "{name} won the coin flip!",
      "wonFlipShort": "{name} won the coin flip",
      "winnerChoosing": "Choosing who goes first…",
      "chooseFirst": "Choose who goes first",
      "youWon": "You won the coin flip!",
      "oppGoesFirst": "{name} goes first"
    },
    "live": {
      "overlayTitle": "LIVE Phase — Set Cards",
      "overlayHint": "LIVE Phase: place 0–3 cards (Live or Member) in Live storage — yours stay face-up; opponent cards stay hidden until Performance. Draw 1 for each card placed, then end LIVE Phase. Performance reveals opponent storage at once.",
      "placeInStorage": "Place in Storage",
      "selected": "Selected",
      "inStorage": "In storage",
      "liveScore": "Live score",
      "liveJudge": "Live Judge",
      "liveWinLoss": "Live Win/Loss Check",
      "yourScore": "Your Score",
      "oppScore": "Opp Score"
    },
    "prompt": {
      "confirm": "Confirm",
      "cancel": "Cancel",
      "respond": "Respond",
      "chooseCards": "Choose cards",
      "chooseFromHand": "Choose from hand",
      "chooseHeart": "Choose a heart",
      "discardFromHand": "Discard from hand",
      "discardOne": "Choose a card to send to the Waiting Room.",
      "discardMany": "Choose {count} cards to send to the Waiting Room.",
      "selectThenConfirm": "Select cards, then tap Confirm.",
      "tapCardConfirm": "Tap a card to confirm.",
      "yes": "Yes",
      "noSkip": "No — Skip",
      "skip": "Skip",
      "tapOption": "Tap an option below.",
      "useLiveStart": "Use this Live Start effect?",
      "useEffect": "Use this effect?",
      "answer": "Answer",
      "typeAnswer": "Type your answer…",
      "typeAnswerHint": "Type your answer — spelling and wording can vary.",
      "confirmArrangement": "Confirm arrangement",
      "selectedCount": "Selected: {n}/{max}",
      "activateSub": "Choose whether to activate this effect."
    },
    "skill": {
      "alreadyUsed": "Already used this turn",
      "needEnergy": "Need {n} active Energy",
      "tutorialDemo": "Tutorial demo — use Next to continue"
    },
    "skillKw": {
      "onEnter": {
        "title": "On Enter",
        "body": "Triggers once when this Member is played from your hand onto your Stage."
      },
      "onLeave": {
        "title": "On Leave",
        "body": "Triggers when this Member leaves your Stage (sent to the Waiting Room, Baton Pass, etc.)."
      },
      "liveStart": {
        "title": "Live Start",
        "body": "Resolves during the Live Start step after a Live is attempted. Many effects are optional — look for \"you may\"."
      },
      "liveSuccess": {
        "title": "Live Success",
        "body": "Resolves when your Live Performance succeeds — required hearts were met for the attempted Live cards."
      },
      "activated": {
        "title": "Activated",
        "body": "You choose to use this during your Main Phase while the Member is active on Stage. Pay any listed costs first."
      },
      "always": {
        "title": "Always",
        "body": "Passive effect that stays on while this Member is in play and its conditions are met. Nothing to activate."
      },
      "oncePerTurn": {
        "title": "Once per turn",
        "body": "You can only use this effect one time each turn."
      },
      "automatic": {
        "title": "Automatic",
        "body": "Fires by itself when the listed condition happens — no activation required."
      },
      "center": {
        "title": "Center",
        "body": "Only applies if this Member is in the center Stage slot when the effect resolves."
      },
      "yell": {
        "title": "Yell (エール)",
        "body": "During Live Performance, draw cards from your deck equal to your total Blade (from active Stage Members). Those cards are revealed — hearts shown on them count toward meeting your Live cards' required hearts. Yell cards are sent to the Waiting Room afterward."
      }
    },
    "heart": {
      "pickColor": "Pick a heart color for this effect.",
      "yellow": "Yellow",
      "pink": "Pink",
      "purple": "Purple",
      "red": "Red",
      "green": "Green",
      "blue": "Blue"
    },
    "card": {
      "cost": "Cost",
      "blade": "Blade",
      "score": "Score",
      "requiredHearts": "Required hearts",
      "hearts": "Hearts",
      "yellIcons": "Yell icons",
      "playToSlot": "Play to slot:",
      "needEnergy": "Need",
      "haveEnergy": "have"
    },
    "pack": {
      "opened": "Pack Opened",
      "boxOpened": "Box Opened"
    },
    "log": {
      "gameStartedCoinFlip": "Game started! Coin flip — winner chooses who goes first.",
      "preparationDrawEnergy": "Preparation: each player drew 6 cards and placed 3 Energy in storage.",
      "preparationMulligan": "Preparation — Mulligan: you may replace any number of opening hand cards once.",
      "livePhaseIntro": "LIVE Phase: place 0–3 cards (Live or Member) face-down in Live storage (draw 1 per card placed), then end LIVE Phase.",
      "bothRevealLive": "Both players reveal Live storage simultaneously.",
      "noLivesThisTurn": "No Lives played this turn.",
      "remainingLiveToWr": "Remaining Live storage sent to Waiting Room.",
      "neitherWrFromHand": "Neither player had cards in hand to put into the Waiting Room.",
      "neitherCouldDraw": "Neither player could draw (deck empty).",
      "neitherLiveWinner": "Neither player succeeds — no Live winner this turn.",
      "coinFlipAuto": "Coin flip — continued automatically (player did not respond in time).",
      "cpuDeck": "CPU deck: {label}",
      "dividerLive": "=== LIVE Phase ===",
      "dividerPerformance": "=== Performance Phase ===",
      "dividerLiveShow": "=== Live Show ===",
      "dividerLiveJudge": "=== Live Win/Loss Check Phase ===",
      "dividerTurnBegin": "=== Turn {turn} begins ===",
      "dividerTurn": "--- Turn {turn} ---",
      "hasNoValidLive": " has no valid Live cards!",
      "disconnectedWin": "{loser} disconnected. {winner} wins!",
      "chooseSuccessLive": " — choose a Live card for Success Live.",
      "scoreTiedBlocked": " — score tied; Success Live blocked; Live cards sent to Waiting Room.",
      "scoreTiedCap": " — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room."
    },
    "win": {
      "youWin": "You Win!",
      "youLose": "You Lose!",
      "playAgain": "Play Again",
      "returnMenu": "Return to Menu",
      "viewLeaderboard": "View Leaderboard",
      "resigned": "You Resigned",
      "conceded": "You conceded the match.",
      "oppResigned": "{name} resigned.",
      "threeLives": "{name} achieved 3 successful Lives!",
      "findAnother": "Find Another Match",
      "disconnectedYou": "You were disconnected from the match.",
      "disconnectedOpp": "{name} disconnected.",
      "statsLine": "Turn: {turn} | Your successes: {yours}/3 | Opp successes: {opp}/3",
      "debugSaveReplay": "💾 Save replay",
      "debugSaveLog": "💾 Save debug log",
      "debugCopyLog": "📋 Copy log",
      "debugSaveBundle": "📦 Export debug bundle"
    },
    "tutorial": {
      "exitTitle": "Exit to Title",
      "back": "← Back",
      "next": "Next →",
      "finish": "Finish",
      "intro_welcome": "Hi! I'm **Shibuya Kanon**. Welcome to the **Love Live! Official Card Game** tutorial!",
      "intro_what": "This is a **two-player** card game about **school idols**! You'll recruit **Members** onto your Stage, manage **Energy**, and perform **Lives** to outshine your opponent.",
      "intro_goal": "**Win condition:** Successfully perform **3 Lives** before your opponent. When your **Live** is a success, that Live moves to the **Success Live card storage** — first to three wins the match!",
      "intro_decks": "This game uses three types of cards. **Member** cards, **Live** cards and **Energy** cards. Each player has a **Main Deck** of **60** cards (**48 Member** cards and **12 Live** cards) and an **Energy Deck** of **12 Energy** cards.",
      "intro_card_member": "**Member cards** are the idols that will perform on Stage. Pay **Energy** equal to their cost to play them from your hand. Each Member has a certain amount of colored **Hearts** (upright) that are used when performing lives. There are also **Blades** (the round penlight icons) and **Blade hearts** (The sideways hearts), but we'll focus on the upright heart for now. Shiki here has **1 purple heart**.",
      "intro_card_live": "**Live cards** are the songs you perform. You can play up to 3 at a time. Lives are cleared using the **Member** cards you've placed on your stage - we'll touch on this more later.",
      "intro_card_energy": "**Energy cards** from your **Energy deck** are placed here. You begin with **3 Energy** and gain **+1** each turn (until all **12** of your energy is in play). Energy is spent to place **Member cards** onto your **stage**.",
      "intro_demo": "I'll walk you through a demo — **Liella!** vs **μ's** on the playmat. You're at the bottom; your opponent is on top.",
      "intro_deck_piles": "The top pile is your **Main Deck**, where you draw cards from. Below is the **Energy deck**.",
      "intro_stage": "The **Stage** (Left / Center / Right) is where Members sit. Their **Heart** colors and **Blade** values fuel Lives during Performance.",
      "intro_live": "**Live Storage** holds up to 3 face-down cards during the Live Phase. You'll be able to see your own cards in this web version, but your opponent's cards will be hidden.",
      "intro_success": "Completing a **live** moves that live card to the **success storage** pile! Keep track of how close you are to winning here!",
      "intro_wr": "The **Waiting Room** is the discard pile.",
      "intro_hands": "Normally your opponent's hand will be hidden, but it's visible for this tutorial. Your hand consists of **Member** and **Live** cards from your deck.",
      "setup_coin": "Before play begins, a **coin flip** picks a winner — they **choose** who goes first. Watch for this at the start of every match!",
      "setup_coin_p1": "...**Liella** goes first!",
      "setup_coin_p2": "Now we can see our **starting hand!**",
      "setup_mulligan": "You start with **6** cards. If you aren't happy with the cards you pulled, this screen gives you an opportunity to swap out as many cards as you want and draw replacements (We refer to this as a **mulligan**).",
      "setup_mull_p1": "Game flow: **Main Phase** -> **Live Phase** -> **Performance Phase** -> Repeat.",
      "setup_mull_p2": "**Main Phase!**. A new card was drawn from your deck.",
      "t1_structure": "Each **Main Phase**, the first player acts, then the second — that's where you play Members... and use skills. You'll press **End Main Phase** here when you're done performing actions.",
      "t1_energy_refresh": "At the start of a new turn, you'll gain **+1 energy**. You'll continue to gain **1** energy with each new turn until all **12** energy cards are in play.",
      "t1_main_p1": "Liella's **Main Phase** — let's play a Member card first!",
      "t1_play_shiki_plain": "We spend **2 Energy** to play this card and send it to a free spot on our Stage! (Spent Energy is flipped sideways)",
      "t1_no_skill": "We now have a single member in the center of our stage. If you don't have the required energy to place more cards, you can end your main phase.",
      "t1_end_main_p1": "Liella ends their Main Phase — it's now the opponent's turn to set their cards!",
      "t1_main_p2": "μ's plays **Rin Hoshizora** to their Stage - with **1 pink heart**.",
      "t1_hearts": "You can see the total amount of **Hearts** and **Blades** for the cards active on your and your opponent's **Stage** up here!",
      "t1_end_main_p2": "After both players complete their Main Phase, it's time for the **Live Phase**!",
      "t1_live_intro": "Place 0–3 cards (Live or Member) in **Live card storage**. Draw 1 new card from your deck for each card you placed. Member cards placed in Live storage will be discarded in the next phase — you can replace unwanted cards this way!",
      "t1_live_p1": "When you set a **Live** card in the Live phase, it must be attempted later in the same turn, so choose wisely! Liella sets **WE WILL!!** — it needs 1 **red** heart, 1 **purple** heart, and 1 additional heart of **any color** (indicated by a grey heart) to be cleared successfully.",
      "t1_live_p1_lock": "Liella Ends their **Live Phase**, locking in their selection. Unlike the Main Phase, you and your opponent's Live Phase occurs at the same time. If your opponent's Live Phase is not over yet, you'll wait for them to finish before moving on.",
      "t1_live_p2": "μ's sets a **Live** face-down in storage - You'll see what it is in the **Performance Phase**.",
      "t1_live_p2_lock": "μ's locks in.",
      "t1_end": "Turn 1 done — you played a Member, set a Live, and learned **Heart matching** during Performance.",
      "t2_start": "**Turn 2** — A card is drawn to your hand, and you gain +1 energy.",
      "t2_skill_intro": "The cards we've played so far only give **hearts** and **blades**, but some cards also have **skills** that affect the game in various ways. Take a look at this card, it features skill text.",
      "t2_skill_preview": "This is an **[On Enter]** skill, meaning when this card enters the stage from your hand, something happens.",
      "t2_play_shiki_skill": "Liella plays Shiki to the right slot. Watch — the game will ask if you want her **On Enter** effect.",
      "t2_on_enter_offer": "If a skill says \"you may\" that means activating it is optional, and you can choose to skip the effect. Liella agrees to **activate** the skill. After paying **1 Energy**, Liella can choose a new card from the top of their deck to add to their hand!",
      "t2_on_enter_confirm": "Now they pick which card to keep. Liella will choose **1 card** to keep, and send the others back to the **Waiting Room**.",
      "t2_on_enter_result": "Shiki's skill resolves — one card joins Liella's hand, two go to the **Waiting Room**. That's an **[On Enter]** skill in action.",
      "t2_end_p1": "Liella ends Main.",
      "t2_main_p2": "μ's plays an affordable Member to add Hearts.",
      "t2_end_p2": "μ's ends Main.",
      "t2_live_skill_intro": "Live cards can have skills too! Some have **[Live Start]** — that triggers when that Live's performance begins.",
      "t2_live_p1": "Liella sets a Live card.",
      "t2_live_p1_lock": "Locked.",
      "t2_live_p2": "μ's sets **START:DASH!!** face-down.",
      "t2_live_p2_lock": "μ's locks in.",
      "t3_start": "**Turn 3** — μ's goes first this turn because they cleared the only Success Live last Performance.",
      "t3_main_p2": "μ's plays an affordable Member to add Hearts.",
      "t3_p2_end": "μ's ends Main.",
      "t3_turn": "**Turn 3** — your Main Phase. You drew a card and gained **+1 Energy** (**6** in storage).",
      "t3_baton_intro": "I'll now explain another mechanic called **Baton Pass**. By playing a card over another card already on your Stage, you can **swap** the old card with the new one. When Baton Passing, you're treated as having paid the replaced Member's cost by moving them to the **Waiting Room** — you'll only pay the **difference** in Energy.",
      "t3_baton_example": "**Mei Yoneme** costs **7** — normally **7 Energy** from hand, but Baton Pass over **Shiki** (cost **4**) on **Right** costs only **3** (7−4). **Shiki** stays on **Center** (**2 Blade**), **Mei** on **Right** adds **1 red** and **2 purple** — you'll set **Mirai wa Kaze no You ni** from **hand** in the **Live Phase**, and Stage is one Heart short of clearing it until **Yell**.",
      "t3_baton_play": "Liella **Baton Passes** Mei onto **Right**!",
      "skill_glossary_intro": "You've now seen several skill timings live. Here are common **keywords** you'll see on cards:",
      "skill_on_enter": "**[On Enter]** — fires once when the Member is played from hand onto your Stage (like Shiki just did). Many say *you may* — they're optional.",
      "skill_live_start": "**[Live Start]** — fires when a Live performance with that card begins (like START:DASH). Also often optional.",
      "skill_activated": "**[Activated]** — during your Main Phase, use the buttons under **Activatable skills**. Some Members like **Kinako** can leave Stage to add a **Live** from your **Waiting Room** to your hand.",
      "skill_wr_note": "Some **[Activated]** skills only work while the Member is **in the Waiting Room** — the list shows **WR ·** before their name. Stage skills show the slot instead.",
      "skill_always": "**[Always]** / **[Automatic]** — stays active while conditions are met; no button to press. **Automatic** triggers by itself when something happens.",
      "skill_once": "**[Once per turn]** — even if you could pay the cost again, you only get one use each turn.",
      "skill_center": "**[Center]** — only works if that Member is in the **center** Stage slot.",
      "skill_on_leave": "**[On Leave]** — fires when the Member leaves Stage (Baton Pass, removal effects, etc.).",
      "t3_stage_hearts": "With **Left** open, set **Mirai wa Kaze no You ni** from **hand** in the **Live Phase**. **Shiki** on **Center** and **Mei** on **Right** supply some Hearts — **Mirai wa Kaze no You ni** still needs some more Hearts, which will hopefully be provided by **Yell**. **Mirai wa Kaze no You ni**'s skill allows **Yell** hearts to count as **any** color, so that improves our chances.",
      "t3_end_p1": "Liella ends Main.",
      "t3_live1": "Liella sets **Mirai wa Kaze no You ni** from **hand**.",
      "t3_live1_lock": "Liella locks in.",
      "t3_live2": "μ's sets **START:DASH!!** face-down.",
      "t3_live2_lock": "μ's locks in — final Performance!",
      "outro": "Core loop: **Main → Live Set → Performance → Judge**. Skills add spice on top. Try **Practice vs CPU** next!",
      "outro_link": "Full rules: llofficial-cardgame.com/rule/ — good luck!"
    },
    "mobile": {
      "rotateTitle": "This game is played in landscape",
      "rotateSub": "Rotate your device to continue."
    },
    "common": {
      "loading": "Loading…",
      "back": "← Back",
      "hubBack": "← Hub",
      "confirm": "Confirm",
      "cancel": "Cancel",
      "copy": "Copy",
      "load": "Load",
      "preview": "Preview",
      "menu": "Main menu",
      "seconds": "{n}s",
      "ok": "OK"
    },
    "toast": {
      "reconnected": "Reconnected to your game",
      "leftActiveMatch": "Left active match",
      "noActiveMatch": "No active match found",
      "noCardId": "No card ID to copy",
      "cardIdCopied": "Card ID copied",
      "couldNotCopyCardId": "Could not copy card ID",
      "signInDeckBuilder": "Sign in to use the Deck Builder.",
      "signOutDeckExperiment": "Sign out to use Deck Experiment.",
      "rankedMatchFound": "Ranked match found!",
      "casualMatchFound": "Casual match found!",
      "passwordCopied": "Password copied",
      "copyFailed": "Copy failed",
      "copied": "Copied! 📋",
      "liveOnly": "Only Live or Member cards can go to Live storage",
      "onlyLiveOrMember": "Only Live or Member cards can go to Live storage",
      "maxLiveCards": "Max {slots} card for Live storage",
      "maxLiveCardsPlural": "Max {slots} cards for Live storage",
      "maxLiveStorage": "Max {slots} card for Live storage",
      "maxLiveStoragePlural": "Max {slots} cards for Live storage",
      "liveStorageFull": "Live storage is full",
      "logCopied": "Log copied",
      "couldNotCopyLog": "Could not copy log"
    },
    "tutorialUi": {
      "exitTitle": "Exit to Title",
      "back": "← Back",
      "next": "Next →",
      "finish": "Finish"
    }
  },
  "ja": {
    "logo": {
      "tagline": "非公式ウェブプレイヤー"
    },
    "auth": {
      "checking": "Discordサインインを確認中…",
      "signInDiscord": "Discordでサインイン",
      "guestTimeout": "サインイン確認がタイムアウトしました——非ランクでプレイするか、Discordサインインを再試行してください。"
    },
    "menu": {
      "unrankedPlay": "カジュアル対戦",
      "unrankedSub": "ルーム作成、フレンド対戦、CPU練習",
      "deckExperiment": "デッキ実験",
      "deckExperimentSub": "全カードで構築——ゲスト限定、非ランク",
      "howToPlay": "遊び方",
      "howToPlaySub": "インタラクティブなルール解説"
    },
    "hub": {
      "signedIn": "サインイン済み",
      "signedInAs": "サインイン中：",
      "signedInAsHtml": "サインイン中：<b>{name}</b>",
      "dailyBoosters": "デイリーブースター：本日（JST）残り {remaining} / {limit}",
      "dailyWelcomeBonus": "（ウェルカムボーナス！）",
      "daily": "デイリーブースター：本日（JST）残り {remaining} / {limit}",
      "dailyBonus": "（ウェルカムボーナス！）",
      "rankLine": "ELO {elo} · {wins}勝-{losses}敗 · 勝率 {winPct}%",
      "options": "オプション",
      "signOut": "サインアウト",
      "openBoosters": "ブースターを開封",
      "openBoostersSub": "カードパックを開封",
      "deckBuilder": "デッキビルダー",
      "deckBuilderSub": "プリセットとランク用デッキを編集",
      "rankedPvp": "ランクPvP",
      "rankedPvpSub": "マッチメイクでELOを競う",
      "leaderboard": "リーダーボード",
      "leaderboardSub": "オンラインランキングを見る",
      "unranked": "カジュアル対戦",
      "unrankedSub": "ルーム作成、フレンド対戦、CPU練習",
      "howToPlay": "遊び方",
      "howToPlaySub": "インタラクティブなルール解説",
      "backHub": "← ハブ"
    },
    "language": {
      "label": "言語"
    },
    "lobby": {
      "title": "カジュアル対戦",
      "yourName": "名前",
      "namePlaceholder": "アイドル名…",
      "deck": "デッキ",
      "createRoom": "ルーム作成",
      "joinRoom": "ルーム参加",
      "roomCode": "ルームコード",
      "vsPlayer": "対プレイヤー",
      "vsCpu": "対CPU",
      "practiceCpu": "CPU練習",
      "cpuDifficulty": "CPU難易度",
      "cpuEasy": "イージー——ランダムスターターデッキ",
      "cpuNormal": "ノーマル——スキルとライブを賢く使う",
      "cpuHard": "ハード——強力なデッキとスキル優先",
      "findRandomMatch": "ランダムマッチ",
      "spectate": "観戦",
      "cancelSearch": "検索キャンセル",
      "phaseTimer": "フェイズタイマー（メイン＆ライブ）",
      "phaseTimerSec": "フェイズあたりの秒数（10〜120）",
      "backHub": "← ハブ",
      "orJoinFriend": "またはフレンドのルームに参加",
      "orMatchRandomly": "またはランダムマッチ",
      "casualHint": "カジュアルPvP——ELOやランク記録なし",
      "experimentDeckPassword": "実験デッキパスワード",
      "experimentPasswordPlaceholder": "8文字コード",
      "experimentDeckBtn": "デッキ実験",
      "experimentDeckHint": "デッキ実験で構築しパスワードを発行してここに入力——または下から保存済みデッキを選択。",
      "secondsLabel": "{n}秒",
      "casualQueueStats": "待機 {waiting} · カジュアル対戦中 {inGame}",
      "casualSearching": "相手を検索中…（{seconds}秒）"
    },
    "deck": {
      "title": "デッキビルダー",
      "experimentTitle": "デッキ実験",
      "deckName": "デッキ名",
      "presetSlot": "プリセットスロット（最大10）",
      "search": "カード検索",
      "searchPlaceholder": "名前、ID、ルールテキスト…",
      "collection": "コレクション",
      "currentDeck": "現在のデッキ",
      "savePreset": "プリセット保存",
      "equipRanked": "ランクに装備",
      "autoBuild": "自動構築",
      "clear": "クリア",
      "hint": "自動構築はコレクションから最適化 · タップで追加/削除 · ホバーでプレビュー",
      "hoverEmpty": "デッキのカードにホバーしてプレビュー。",
      "backHub": "← ハブ",
      "backMenu": "← メニュー",
      "deckPassword": "デッキパスワード",
      "deckPasswordPlaceholder": "読み込み用パスワードを入力",
      "load": "読み込み",
      "savedPassword": "保存済みパスワード：",
      "copy": "コピー",
      "cardPool": "カードプール",
      "resetStarter": "スターターリセット",
      "useStarter": "スターターを使用",
      "randomDeck": "ランダムデッキ",
      "updateSavedDeck": "保存デッキを更新",
      "generatePassword": "パスワード発行",
      "experimentHint": "全カードプール · 合法デッキを組んでパスワード発行 · 長押しまたは右クリックで詳細",
      "collectionOwned": "所持カード合計 · {count}",
      "collectionLoading": "全カードプール · 読み込み中…",
      "collectionMatch": "コレクション · {match} 件一致",
      "deckStats": "合計 {total}/72 · メンバー {members}/48 · ライブ {lives}/12 · エネルギー {energy}/12",
      "deckEmpty": "コレクションからカードをタップしてデッキを構築。",
      "deckEmptyExperiment": "プールからカードをタップして合法60+12デッキを構築。",
      "experimentStarterTitle": "スターターデッキを選ぶ",
      "experimentStarterLead": "公式スターターリストを基盤に読み込み——全カードプールから自由に編集。",
      "accountStarterHint": "アカウントのスターター：{starter} · プリセット#1はこのリストをベースにしています。",
      "noStarterOnAccount": "このアカウントにスターターデッキがありません。",
      "loadedStarterIntoPreset": "{name} をプリセット#{slot}に読み込みました。",
      "loadedStarterFallbackName": "スターターデッキ",
      "chooseStarterFirst": "先にスターターデッキを選んでください。",
      "building": "構築中…",
      "autoBuiltSuccess": "コレクションから合法デッキを自動構築しました。",
      "starterDecksNotLoaded": "スターターデッキがまだ読み込まれていません。",
      "equippedRanked": "ランク用に装備しました。",
      "filters": {
        "title": "フィルター",
        "showAdvanced": "詳細フィルターを表示",
        "hideAdvanced": "詳細フィルターを隠す",
        "all": "すべて",
        "allTypes": "すべてのタイプ",
        "allFields": "すべての項目",
        "allProducts": "すべての商品",
        "allSets": "すべてのセット",
        "any": "指定なし",
        "min": "最小",
        "max": "最大",
        "notIncluded": "含まない",
        "heartAll": "すべて",
        "drawIcon": "ドローアイコン",
        "scoreIcon": "スコアアイコン",
        "label": {
          "type": "タイプ",
          "group": "グループ",
          "rarity": "レアリティ",
          "keywordIn": "キーワード検索",
          "product": "商品",
          "productSet": "商品セット",
          "subunit": "サブユニット",
          "parallel": "パラレル",
          "printedHearts": "印刷ハート",
          "requiredHearts": "必要ハート",
          "bladeHearts": "ブレードハート",
          "blade": "ブレード",
          "cost": "コスト",
          "score": "スコア"
        },
        "type": {
          "member": "メンバー",
          "live": "ライブ",
          "energy": "エネルギー"
        },
        "searchMode": {
          "all": "すべての項目",
          "name": "名前",
          "text": "テキスト",
          "id": "カードID"
        },
        "searchPlaceholder": {
          "all": "名前、ID、ルールテキスト…",
          "name": "カード名…",
          "text": "ルールテキスト…",
          "id": "カードID（例：PL!N-sd1-021-SD）"
        },
        "productKind": {
          "bp": "ブースターパック",
          "pb": "プレミアムブースター",
          "pb_duo": "プレミアムブースター（DUO）",
          "sd": "スタートデッキ",
          "collection": "コレクション",
          "pr": "PR"
        },
        "parallel": {
          "normal": "通常のみ",
          "parallel": "パラレルのみ"
        },
        "groups": {
          "mus": "μ's",
          "nijigasaki": "虹ヶ咲学園スクールアイドル同好会",
          "sunshine": "Aqours",
          "superstar": "Liella!",
          "hasunosora": "蓮ノ空女学院スクールアイドルクラブ",
          "saintsnow": "Saint Snow",
          "arise": "A-RISE",
          "sunnypassion": "Sunny Passion"
        },
        "sort": {
          "aria": "コレクションの並べ替え",
          "sortBy": "並べ替え",
          "order": "順序",
          "asc": "昇順",
          "desc": "降順",
          "id": "カードID",
          "rarity": "レアリティ",
          "name": "名前（メンバー）",
          "type": "カードタイプ",
          "group": "グループ／スクール",
          "recent": "最近入手"
        }
      }
    },
    "booster": {
      "title": "ブースターを開封",
      "openPack": "パック開封（5枚）",
      "packOpened": "パック開封完了",
      "godPack": "GOD PACK!",
      "openAnother": "もう1パック開封",
      "openSameAgain": "同じパックをもう一度",
      "packsLeft": "本日（JST）残り {n} パック",
      "mainMenu": "メインメニュー",
      "backHub": "← ハブ"
    },
    "ranked": {
      "title": "ランクPvP",
      "findMatch": "マッチを探す",
      "cancelSearch": "検索キャンセル",
      "spectate": "観戦",
      "timerNote": "メイン＆ライブフェイズは120秒タイマーです。",
      "deckLabel": "ランク用デッキ",
      "matchSound": "マッチ成立時に音を鳴らす",
      "leaderboard": "リーダーボード",
      "leaderboardTitle": "ランクリーダーボード",
      "backHub": "← ハブ",
      "infoLine": "ELO {elo} · {record}",
      "record": "{wins}勝-{losses}敗 · 勝率 {winPct}%",
      "recordFull": "{wins}勝-{losses}敗 · 勝率 {winPct}% · 敗率 {lossPct}%",
      "queueStats": "待機 {waiting} · ランク対戦中 {inGame}",
      "searching": "検索中…（{seconds}秒）",
      "readySearch": "検索可能"
    },
    "leaderboard": {
      "title": "ランクリーダーボード",
      "lead": "ランクPvPの最高ELO。プロフィール行にカードバナーを設定できます。",
      "empty": "ランク対戦の記録がまだありません——ランクPvPでプレイして掲載しましょう。",
      "editBanner": "プロフィールバナーを編集",
      "eloSuffix": " ELO",
      "eloLabel": "ELO {elo}",
      "profileBanner": "プロフィールバナー",
      "bannerLead": "所持カードを選び、縦にドラッグしてリーダーボードカードに表示するアートを選んでください。",
      "bannerSearchPlaceholder": "カード名で検索…",
      "bannerNoMatch": "検索に一致するカードがありません。",
      "bannerPreview": "プレビュー",
      "saveBanner": "バナーを保存",
      "selectCardFirst": "先にカードを選択してください"
    },
    "options": {
      "title": "オプション",
      "enhancedTextures": "高レアリティカードのテクスチャ強化",
      "soundEffects": "効果音",
      "sfxVolume": "効果音の音量",
      "stuckTitle": "マッチで固まった？",
      "stuckLead": "ランクで壊れた、または終了済みのゲームに再接続された場合、ここでアクティブなマッチ記録を離脱できます。進行中のゲームでは降参扱いになります。",
      "resetTitle": "アカウントリセット",
      "resetLead": "コレクション、デッキプリセット、ランク成績、ブースター進行をすべて削除します。新しいスターターデッキを選び直します。元に戻せません。",
      "resetAccount": "アカウントリセット",
      "backHub": "← ハブ"
    },
    "starter": {
      "title": "スターターデッキを選ぶ",
      "lead": "公式スタートデッキを1つ選び、コレクションの基盤にします。この選択は取り消せません。",
      "confirm": "スターター確定"
    },
    "waiting": {
      "roomCreated": "ルーム作成完了！",
      "shareCode": "相手にこのコードを共有：",
      "tapCopy": "タップでコピー 📋",
      "clickCopy": "クリックでコピー",
      "waitingOpponent": "相手の参加を待っています…",
      "cancel": "キャンセル",
      "phaseTimerInfo": "フェイズタイマー：メイン＆ライブ各ターン {sec}秒"
    },
    "game": {
      "you": "あなた",
      "opponent": "相手",
      "opp": "相手",
      "gameLog": "ゲームログ",
      "resign": "🏳 降参",
      "enableRadio": "📻 ラジオを有効化",
      "endMainPhase": "メインフェイズ終了",
      "endLivePhase": "ライブフェイズ終了",
      "setLiveCards": "ライブカードをセット",
      "waitingOpponent": "相手を待っています",
      "resolveSkillFirst": "先にスキルを解決",
      "waitingSkill": "スキル待ち",
      "yourHand": "あなたの手札",
      "mainDeck": "メインデッキ",
      "waitingRoom": "控え室",
      "oppWaitingRoom": "相手の控え室",
      "deckHidden": "相手のデッキは見えません。",
      "energyDeck": "エネルギーデッキ",
      "liveStorage": "ライブ置き場",
      "successStorage": "成功ライブ置き場",
      "stageBoard": "ステージボード",
      "activatableSkills": "起動可能スキル",
      "activeEffects": "有効な効果",
      "hoverHandEmpty": "手札のカードにホバーしてプレビュー。",
      "starting": "開始中…",
      "hand": "手札",
      "wr": "控え室",
      "spectating": "観戦中 — {p1} vs {p2}（閲覧のみ）",
      "oppActivatingSkill": "相手がスキルを発動中…",
      "activeEnergy": "使用可能",
      "pickSlot": "スロットを選択",
      "batonPassHint": "バトンタッチ — 使用可能エネルギー{cost}",
      "overplayHint": "上書き — 使用可能エネルギー{cost}",
      "slotLeft": "左サイド",
      "slotCenter": "センター",
      "slotRight": "右サイド",
      "baton": "バトン",
      "batonToggleOn": "タップで上書きモードに切替",
      "batonToggleOff": "タップでバトンタッチに切替",
      "opponentSkillWait": "{name}がスキルを発動中…",
      "perfYou": "あなた",
      "perfOpp": "相手",
      "sidebarInfo": "{turn}<span class=\"turn-sep\">·</span>フェイズ：{phase}<span class=\"turn-sep\">·</span>アクティブ：{active}<span class=\"turn-sep\">·</span>先攻：{first}"
    },
    "slot": {
      "left": "左",
      "center": "センター",
      "right": "右"
    },
    "phase": {
      "waiting": "待機",
      "setup": "準備（マリガン）",
      "main": "メインフェイズ",
      "main_first": "メインフェイズ",
      "main_second": "メインフェイズ",
      "live": "ライブフェイズ",
      "live_set": "ライブフェイズ",
      "live_set_first": "ライブフェイズ",
      "live_set_second": "ライブフェイズ",
      "live_start_effects": "ライブ開始時",
      "live_success_effects": "ライブ成功時",
      "performance": "パフォーマンスフェイズ",
      "live_performance_first": "パフォーマンスフェイズ",
      "live_performance_second": "パフォーマンスフェイズ",
      "coinFlip": "コイントス",
      "preparation": "準備",
      "active": "アクティブフェイズ",
      "active_first": "アクティブフェイズ",
      "active_second": "アクティブフェイズ",
      "live_judge": "ライブ勝敗判定"
    },
    "phaseId": {
      "waiting": "待機",
      "coin_flip": "コイントス",
      "setup": "準備（マリガン）",
      "active_first": "アクティブフェイズ",
      "active_second": "アクティブフェイズ",
      "main_first": "メインフェイズ",
      "main_second": "メインフェイズ",
      "live_set": "ライブフェイズ",
      "live_set_first": "ライブフェイズ",
      "live_set_second": "ライブフェイズ",
      "live_start_effects": "ライブ開始時",
      "live_success_effects": "ライブ成功時",
      "live_performance_first": "パフォーマンスフェイズ",
      "live_performance_second": "パフォーマンスフェイズ",
      "live_judge": "ライブ勝敗判定"
    },
    "phaseBar": {
      "spectating": "観戦中 — {p1} vs {p2}（閲覧のみ）",
      "setupWaitMulligan": "相手のマリガン完了を待っています…",
      "setupMulligan": "準備 — 初期手札を確認し、交換するカードを選んで確定してください。",
      "coinFlip": "コイントス — 勝者が先攻を選びます…",
      "mainYour": "あなたのメインフェイズ — メンバーを出せます（{energy} 使用可能）。終わったらメインフェイズ終了。",
      "mainOpp": "{name}のターン — メインフェイズ…",
      "mainOppS": "{name}のターン — メインフェイズ…",
      "liveRaised": "ライブフェイズ — {count}枚レイズ中 · 手札をタップで調整 · ログ下のボタンで確定",
      "liveRaisedPlural": "ライブフェイズ — {count}枚レイズ中 · 手札をタップで調整 · ログ下のボタンで確定",
      "liveStored": "ライブフェイズ — 置き場 {stored}枚 · あと{slots}枚まで（ライブまたはメンバー）、またはログ下でライブフェイズ終了",
      "livePlace": "ライブフェイズ — 0〜{slots}枚を置いてライブフェイズ終了 · ボタンはログ下",
      "liveBothLocked": "両者確定 — パフォーマンス開始…",
      "liveYouLocked": "確定済み — 相手のライブ選択完了を待っています…",
      "liveStartEffects": "ライブ開始時のプロンプトを解決 — 任意効果はオーバーレイで表示されます。",
      "liveSuccessEffects": "ライブ成功時のプロンプトを解決 — 任意効果はオーバーレイで表示されます。",
      "performance": "パフォーマンスフェイズ — エール · ハート · ライブ成功判定",
      "liveJudge": "ライブ勝敗判定フェイズ…"
    },
    "phaseBanner": {
      "coinFlipTitle": "コイントス",
      "coinFlipSub": "勝者が先攻を選びます",
      "setupTitle": "準備",
      "setupSub": "任意のマリガン（1回交換）",
      "activeTitle": "アクティブフェイズ",
      "activeSub": "エネルギーとメンバーをリフレッシュ",
      "mainYour": "あなたのメインフェイズ",
      "mainOpp": "{name}のメインフェイズ",
      "mainOppS": "{name}のメインフェイズ",
      "liveTitle": "ライブフェイズ",
      "liveSub": "0〜3枚（ライブまたはメンバー）を置き、ライブフェイズ終了",
      "liveStartTitle": "ライブ開始時",
      "liveStartSub": "パフォーマンス前の任意効果",
      "liveSuccessTitle": "ライブ成功時",
      "liveSuccessSub": "ハート判定後の任意効果",
      "performanceTitle": "パフォーマンスフェイズ",
      "performanceSub": "公開 · エール · ハート",
      "liveJudgeTitle": "ライブ勝敗判定",
      "liveJudgeSub": "ライブスコアを比較中…",
      "yourMain": "あなたのメインフェイズ",
      "theirMain": "{name}のメインフェイズ",
      "theirMainS": "{name}のメインフェイズ"
    },
    "splash": {
      "turn": "ターン {turn}",
      "turnBegin": "ターン {turn} 開始",
      "noLives": "このターンはライブなし",
      "gameStart": "ゲーム開始",
      "deckRefresh": "デッキリフレッシュ",
      "deckRefreshOpp": "{name} — デッキリフレッシュ",
      "deckRefreshSub": "控え室から{n}枚をシャッフルして新デッキに",
      "youAttemptLive": "ライブ試行！",
      "theyAttemptLive": "{name}がライブ試行",
      "attemptSub": "エール抽選 · ハート判定",
      "youWait": "ウェイト",
      "theyWait": "{name}がウェイト",
      "youWaitSub": "ライブカードは置き場に残る",
      "theyWaitSub": "ライブカードは相手の置き場に残る",
      "perfRoundFailed": "成功{ok} · ラウンド失敗（全ライブ成功が必要）",
      "perfCleared": "ライブ{ok}枚がラウンドクリア",
      "perfMixed": "成功{ok} · 失敗{fail} → 控え室",
      "yourLivePerformance": "あなたのライブ",
      "theirLive": "{name}のライブ",
      "perfSubYell": "エール {blades} · {sub}",
      "successLiveYou": "ライブ成功！",
      "successLiveThey": "{name} — ライブ成功！",
      "successLiveSubYou": "ライブカードが成功置き場へ",
      "successLiveSubThey": "ライブカードが相手の成功置き場へ",
      "bothWait": "両者ウェイト",
      "bothWaitSub": "ライブカードは置き場に残る",
      "liveStartFlash": "LIVE START"
    },
    "mulligan": {
      "title": "初期手札 🌸",
      "hint": "タップで交換マーク。長押しで詳細。もう一度タップで解除。",
      "keepHand": "この手札で開始",
      "replaceCard": "{n}枚交換",
      "replaceCards": "{n}枚交換"
    },
    "coin": {
      "title": "先攻決定",
      "flipping": "コイントス中…",
      "goFirst": "自分が先攻",
      "escortFirst": "エスコートが先攻",
      "opponentFirst": "相手が先攻",
      "waitingOppFlip": "相手がコイントス演出の完了を待っています…",
      "waitingOpp": "相手を待っています…",
      "wonFlip": "{name}がコイントスに勝利！",
      "wonFlipShort": "{name}がコイントスに勝利",
      "winnerChoosing": "先攻を選んでいます…",
      "chooseFirst": "先攻を選んでください",
      "youWon": "コイントスに勝利しました！",
      "oppGoesFirst": "{name}が先攻"
    },
    "live": {
      "overlayTitle": "ライブフェイズ——カードをセット",
      "overlayHint": "ライブフェイズ：ライブ置き場に0〜3枚（ライブまたはメンバー）を置きます——自分のカードは表向き、相手はパフォーマンスまで非表示。置いた枚数分引き、ライブフェイズ終了後、パフォーマンスで相手の置き場が一斉に公開されます。",
      "placeInStorage": "ライブ置き場に置く",
      "selected": "選択中",
      "inStorage": "置き場",
      "liveScore": "ライブスコア",
      "liveJudge": "ライブジャッジ",
      "liveWinLoss": "ライブ勝敗判定",
      "yourScore": "あなたのスコア",
      "oppScore": "相手スコア"
    },
    "prompt": {
      "confirm": "確定",
      "cancel": "キャンセル",
      "respond": "応答",
      "chooseCards": "カードを選ぶ",
      "chooseFromHand": "手札から選ぶ",
      "chooseHeart": "ハートを選ぶ",
      "discardFromHand": "手札から捨てる",
      "discardOne": "控え室に送るカードを1枚選んでください。",
      "discardMany": "控え室に送るカードを{count}枚選んでください。",
      "selectThenConfirm": "カードを選び、確定をタップしてください。",
      "tapCardConfirm": "カードをタップして確定。",
      "yes": "はい",
      "noSkip": "いいえ — スキップ",
      "skip": "スキップ",
      "tapOption": "下のオプションをタップしてください。",
      "useLiveStart": "このライブ開始時効果を使いますか？",
      "useEffect": "この効果を使いますか？",
      "answer": "回答",
      "typeAnswer": "回答を入力…",
      "typeAnswerHint": "回答を入力してください。表記や言い回しは多少異なっても構いません。",
      "confirmArrangement": "配置を確定",
      "selectedCount": "選択中：{n}/{max}",
      "activateSub": "この効果を発動するか選んでください。"
    },
    "skill": {
      "alreadyUsed": "このターンは使用済み",
      "needEnergy": "アクティブなエネルギーが{n}必要",
      "tutorialDemo": "チュートリアルデモ — 次へで続行"
    },
    "skillKw": {
      "onEnter": {
        "title": "登場時",
        "body": "手札からステージにメンバーをプレイしたときに1回発動します。"
      },
      "onLeave": {
        "title": "退場時",
        "body": "メンバーがステージを離れるとき（控え室、バトンタッチ、除去など）に発動します。"
      },
      "liveStart": {
        "title": "ライブ開始時",
        "body": "ライブが試行されたあとのライブ開始ステップで解決します。多くは任意効果です（「してもよい」）。"
      },
      "liveSuccess": {
        "title": "ライブ成功時",
        "body": "ライブパフォーマンスが成功したとき（必要ハートを満たしたとき）に解決します。"
      },
      "activated": {
        "title": "起動",
        "body": "メインフェイズ中、ステージ上のアクティブなメンバーとして自分で発動します。先にコストを支払います。"
      },
      "always": {
        "title": "常時",
        "body": "条件を満たしている間ずっと有効なパッシブ効果です。発動操作は不要です。"
      },
      "oncePerTurn": {
        "title": "ターン1回",
        "body": "この効果は1ターンに1回だけ使えます。"
      },
      "automatic": {
        "title": "自動",
        "body": "条件が満たされると自動で発動します。発動操作は不要です。"
      },
      "center": {
        "title": "センター",
        "body": "効果解決時にこのメンバーがセンタースロットにいる場合のみ適用されます。"
      },
      "yell": {
        "title": "エール",
        "body": "ライブパフォーマンス中、ステージ上のアクティブメンバーの合計ブレード枚数だけデッキからカードを公開します。公開カードのハートは必要ハート判定に使えます。エール後、公開カードは控え室へ送られます。"
      }
    },
    "heart": {
      "pickColor": "この効果のハート色を選んでください。",
      "yellow": "黄",
      "pink": "ピンク",
      "purple": "紫",
      "red": "赤",
      "green": "緑",
      "blue": "青"
    },
    "card": {
      "cost": "コスト",
      "blade": "ブレード",
      "score": "スコア",
      "requiredHearts": "必要ハート",
      "hearts": "ハート",
      "yellIcons": "エールアイコン",
      "playToSlot": "配置スロット：",
      "needEnergy": "必要",
      "haveEnergy": "所持"
    },
    "pack": {
      "opened": "パック開封",
      "boxOpened": "ボックス開封"
    },
    "log": {
      "gameStartedCoinFlip": "ゲーム開始！コイントス — 勝者が先攻を選びます。",
      "preparationDrawEnergy": "準備：各プレイヤーは6枚引き、エネルギー3枚を置きました。",
      "preparationMulligan": "準備 — マリガン：初手を任意枚数、1回だけ入れ替えできます。",
      "livePhaseIntro": "ライブフェイズ：ライブ置き場に0〜3枚（ライブまたはメンバー）を裏向きで置き（1枚につき1枚ドロー）、ライブフェイズを終了。",
      "bothRevealLive": "両プレイヤーが同時にライブ置き場を公開。",
      "noLivesThisTurn": "このターンはライブなし。",
      "remainingLiveToWr": "残りのライブ置き場のカードを控え室へ。",
      "neitherWrFromHand": "手札を控え室に置けるカードがどちらもありませんでした。",
      "neitherCouldDraw": "どちらもドローできませんでした（デッキが空）。",
      "neitherLiveWinner": "どちらも成功せず — このターンのライブ勝者なし。",
      "coinFlipAuto": "コイントス — 時間切れのため自動続行。",
      "cpuDeck": "COMデッキ：{label}",
      "dividerLive": "=== ライブフェイズ ===",
      "dividerPerformance": "=== パフォーマンスフェイズ ===",
      "dividerLiveShow": "=== ライブショー ===",
      "dividerLiveJudge": "=== ライブ勝敗判定 ===",
      "dividerTurnBegin": "=== ターン{turn} 開始 ===",
      "dividerTurn": "--- ターン {turn} ---",
      "hasNoValidLive": " 有効なライブカードがありません！",
      "disconnectedWin": "{loser}が切断。{winner}の勝利！",
      "chooseSuccessLive": " — 成功ライブにするライブカードを選択。",
      "scoreTiedBlocked": " — スコア同点のため成功ライブ不可、ライブカードを控え室へ。",
      "scoreTiedCap": " — スコア同点だが成功ライブが2枚のため追加不可、ライブカードを控え室へ。"
    },
    "win": {
      "youWin": "勝利！",
      "youLose": "敗北…",
      "playAgain": "もう一度",
      "returnMenu": "メニューに戻る",
      "viewLeaderboard": "リーダーボードを見る",
      "resigned": "降参しました",
      "conceded": "対戦を降参しました。",
      "oppResigned": "{name}が降参しました。",
      "threeLives": "{name}がライブを3回成功しました！",
      "findAnother": "別のマッチを探す",
      "disconnectedYou": "対戦から切断されました。",
      "disconnectedOpp": "{name}が切断しました。",
      "statsLine": "ターン：{turn} | あなたの成功：{yours}/3 | 相手の成功：{opp}/3",
      "debugSaveReplay": "💾 リプレイを保存",
      "debugSaveLog": "💾 デバッグログを保存",
      "debugCopyLog": "📋 ログをコピー",
      "debugSaveBundle": "📦 デバッグ一式を出力"
    },
    "tutorial": {
      "intro_welcome": "こんにちは！**渋谷かのん**です。**ラブライブ！オフィシャルカードゲーム**のチュートリアルへようこそ！",
      "intro_what": "これは**2人対戦**の**スクールアイドル**カードゲームです！**メンバー**を**ステージ**に配置し、**エネルギー**を管理して**ライブ**を成功させ、相手を上回りましょう。",
      "intro_goal": "**勝利条件：** 相手より先に**ライブを3回成功**させること。ライブが成功すると、そのカードは**成功ライブ置き場**に移動します——先に3枚成功した方が勝ち！",
      "intro_decks": "このゲームには3種類のカードがあります。**メンバー**カード、**ライブ**カード、**エネルギー**カード。各プレイヤーは**メインデッキ60枚**（**メンバー48枚**と**ライブ12枚**）と**エネルギーデッキ12枚**を持ちます。",
      "intro_card_member": "**メンバーカード**はステージでパフォーマンスするアイドルです。コスト分の**エネルギー**を支払って手札から場に出します。各メンバーには色付きの**ハート**（縦向き）があり、ライブの成功判定に使います。**ブレード**（丸いペンライトアイコン）や**ブレードハート**（横向きのハート）もありますが、今は縦向きハートに注目しましょう。こちらの四季には**紫ハート1個**があります。",
      "intro_card_live": "**ライブカード**は演奏する楽曲です。同時に最大3枚まで置けます。ライブはステージに置いた**メンバー**カードで成功させます——詳しくは後で説明します。",
      "intro_card_energy": "**エネルギーカード**は**エネルギーデッキ**からここに置きます。最初は**エネルギー3**から始まり、毎ターン**+1**（全**12**枚が場に出るまで）。エネルギーは**メンバー**を**ステージ**に出すのに使います。",
      "intro_demo": "デモを見せますね——プレイマット上の**Liella!**対**μ's**。あなたは下、相手は上です。",
      "intro_deck_piles": "上の山札が**メインデッキ**で、ここからカードを引きます。その下が**エネルギーデッキ**です。",
      "intro_stage": "**ステージ**（左／センター／右）にメンバーが座ります。**ハート**の色と**ブレード**の値がパフォーマンスフェイズのライブを支えます。",
      "intro_live": "**ライブ置き場**にはライブフェイズ中、裏向きで最大3枚まで置けます。このウェブ版では自分のカードは見えますが、相手のカードは隠れます。",
      "intro_success": "**ライブ**を成功させると、そのカードは**成功ライブ置き場**に移動します！勝利にどれだけ近いか、ここで確認しましょう！",
      "intro_wr": "**控え室**は捨て札置き場です。",
      "intro_hands": "通常は相手の手札は見えませんが、このチュートリアルでは見えるようになっています。手札はデッキから引いた**メンバー**と**ライブ**カードで構成されます。",
      "setup_coin": "対戦開始前に**コイントス**で勝者が決まり、**先攻**を選べます。毎試合の最初に注目してください！",
      "setup_coin_p1": "……**Liella!**が先攻！",
      "setup_coin_p2": "さあ、**初期手札**が見えました！",
      "setup_mulligan": "最初は**6枚**引きます。引いたカードに満足できなければ、好きな枚数を交換して引き直せます（これを**マリガン**と呼びます）。",
      "setup_mull_p1": "ゲームの流れ：**メインフェイズ** → **ライブフェイズ** → **パフォーマンスフェイズ** → 繰り返し。",
      "setup_mull_p2": "**メインフェイズ！** デッキから新しいカードを1枚引きました。",
      "t1_structure": "各**メインフェイズ**で先攻プレイヤーが行動し、次に後攻——ここでメンバーを出したりスキルを使います。行動が終わったら**メインフェイズ終了**を押します。",
      "t1_energy_refresh": "新しいターンの開始時に**エネルギー+1**します。全**12**枚のエネルギーが場に出るまで、毎ターン**1**ずつ増えます。",
      "t1_main_p1": "Liella!の**メインフェイズ**——まずメンバーカードを1枚出しましょう！",
      "t1_play_shiki_plain": "**エネルギー2**を使ってこのカードを出し、ステージの空きスロットに置きます！（使ったエネルギーは横向きになります）",
      "t1_no_skill": "ステージ中央にメンバーが1体います。これ以上出すエネルギーがなければ、メインフェイズを終了できます。",
      "t1_end_main_p1": "Liella!がメインフェイズを終了——相手のターンでカードを配置します！",
      "t1_main_p2": "μ'sが**星空凛**をステージに出しました——**ピンクハート1個**付きです。",
      "t1_hearts": "自分と相手の**ステージ**上のアクティブなカードの**ハート**と**ブレード**の合計が、ここで確認できます！",
      "t1_end_main_p2": "両プレイヤーがメインフェイズを終えると、**ライブフェイズ**の時間です！",
      "t1_live_intro": "**ライブ置き場**に0〜3枚（ライブまたはメンバー）を置きます。置いた枚数分デッキから1枚ずつ引きます。ライブ置き場に置いたメンバーは次のフェイズで控え室へ——不要なカードの入れ替えに使えます！",
      "t1_live_p1": "ライブフェイズで**ライブ**カードを置くと、同じターン中に必ず挑戦します。慎重に選びましょう！Liella!は**WE WILL!!**をセット——成功には**赤ハート1**、**紫ハート1**、**任意の色**のハート1個（グレーのハートで表示）が必要です。",
      "t1_live_p1_lock": "Liella!が**ライブフェイズ**を終了し、選択を確定しました。メインフェイズと違い、ライブフェイズは同時進行です。相手がまだ終わっていなければ、終了を待ちます。",
      "t1_live_p2": "μ'sが**ライブ**を裏向きでライブ置き場にセット——**パフォーマンスフェイズ**で正体がわかります。",
      "t1_live_p2_lock": "μ'sが確定しました。",
      "t1_end": "ターン1終了——メンバーを出し、ライブをセットし、パフォーマンスでの**ハート合わせ**を学びました。",
      "t2_start": "**ターン2**——手札に1枚引き、エネルギー+1。",
      "t2_skill_intro": "これまでのカードは**ハート**と**ブレード**だけでしたが、ゲームに影響する**スキル**を持つカードもあります。このカードを見てください——スキルテキストがあります。",
      "t2_skill_preview": "これは**[登場時]**スキルです。手札からステージに出たときに何かが起こります。",
      "t2_play_shiki_skill": "Liella!が四季を右スロットに出します。見てください——ゲームが**登場時**効果を使うか尋ねます。",
      "t2_on_enter_offer": "スキルに「してもよい」とあれば発動は任意で、効果をスキップできます。Liella!はスキルを**発動**することにしました。**エネルギー1**を支払うと、デッキの上から選んで手札に加えられます！",
      "t2_on_enter_confirm": "次に残すカードを選びます。Liella!は**1枚**を手札に残し、残りは**控え室**へ送ります。",
      "t2_on_enter_result": "四季のスキルが解決——1枚が手札に、2枚が**控え室**へ。**[登場時]**スキルの実例です。",
      "t2_end_p1": "Liella!がメインフェイズを終了。",
      "t2_main_p2": "μ'sが手頃なメンバーを出してハートを追加。",
      "t2_end_p2": "μ'sがメインフェイズを終了。",
      "t2_live_skill_intro": "ライブカードにもスキルがあります！**[ライブ開始時]**は、そのライブのパフォーマンスが始まったときに発動します。",
      "t2_live_p1": "Liella!がライブカードをセット。",
      "t2_live_p1_lock": "確定しました。",
      "t2_live_p2": "μ'sが**START:DASH!!**を裏向きでセット。",
      "t2_live_p2_lock": "μ'sが確定しました。",
      "t3_start": "**ターン3**——前のパフォーマンスで唯一成功したのがμ'sだったため、今ターンはμ'sが先攻です。",
      "t3_main_p2": "μ'sが手頃なメンバーを出してハートを追加。",
      "t3_p2_end": "μ'sがメインフェイズを終了。",
      "t3_turn": "**ターン3**——あなたのメインフェイズ。1枚引き、**エネルギー+1**（場に**6**）。",
      "t3_baton_intro": "次は**バトンタッチ**を説明します。ステージ上のカードの上に別のカードを出すと、古いカードと**入れ替え**できます。バトンタッチでは、入れ替えたメンバーを**控え室**に送ることでコストを支払った扱いになり——差額のエネルギーだけ支払います。",
      "t3_baton_example": "**米女メイ**のコストは**7**——通常は手札から**エネルギー7**ですが、**右**の**四季**（コスト**4**）の上にバトンタッチすれば**3**だけ（7−4）。**四季**は**センター**に残り（**ブレード2**）、**右**の**メイ**は**赤1**と**紫2**を追加——**ライブフェイズ**で手札から**未来は風のように**をセットします。ステージだけではハートが1足りず、**エール**で補う想定です。",
      "t3_baton_play": "Liella!が**右**にメイを**バトンタッチ**！",
      "skill_glossary_intro": "いくつかのスキルタイミングを実際に見ました。カードに出てくる主な**キーワード**を紹介します：",
      "skill_on_enter": "**[登場時]**——手札からステージに出したときに1回発動（四季の例）。多くは*してもよい*とあり、任意です。",
      "skill_live_start": "**[ライブ開始時]**——そのライブのパフォーマンスが始まったときに発動（START:DASH!!の例）。こちらも多くは任意です。",
      "skill_activated": "**[起動]**——メインフェイズ中、**起動可能スキル**の下のボタンで使用。**きな子**のようにステージを離れて**控え室**の**ライブ**を手札に戻すメンバーもいます。",
      "skill_wr_note": "一部の**[起動]**スキルはメンバーが**控え室**にいるときだけ使えます——リストには名前の前に**控え室 ·**と表示されます。ステージのスキルはスロットが表示されます。",
      "skill_always": "**[常時]**／**[自動]**——条件を満たしている間ずっと有効。ボタンは不要。**自動**は何かが起きたとき自分で発動します。",
      "skill_once": "**[ターン1回]**——コストを再支払いできても、1ターンに1回だけです。",
      "skill_center": "**[センター]**——そのメンバーが**センター**スロットにいるときだけ有効です。",
      "skill_on_leave": "**[退場時]**——メンバーがステージを離れるときに発動（バトンタッチ、除去効果など）。",
      "t3_stage_hearts": "**左**が空いているので、**ライブフェイズ**で手札から**未来は風のように**をセット。**センター**の**四季**と**右**の**メイ**がハートを供給——まだ足りない分は**エール**で補います。**未来は風のように**のスキルでエールのハートが**任意の色**として数えられるので、成功率が上がります。",
      "t3_end_p1": "Liella!がメインフェイズを終了。",
      "t3_live1": "Liella!が手札から**未来は風のように**をセット。",
      "t3_live1_lock": "Liella!が確定しました。",
      "t3_live2": "μ'sが**START:DASH!!**を裏向きでセット。",
      "t3_live2_lock": "μ'sが確定——最後のパフォーマンス！",
      "outro": "基本ループ：**メイン → ライブセット → パフォーマンス → ジャッジ**。スキルがさらに深みを加えます。次は**CPU練習**を試してみて！",
      "outro_link": "完全なルール：llofficial-cardgame.com/rule/ — 頑張って！"
    },
    "tutorialUi": {
      "exitTitle": "タイトルに戻る",
      "back": "← 戻る",
      "next": "次へ →",
      "finish": "完了"
    },
    "mobile": {
      "rotateTitle": "このゲームは横向きでプレイします",
      "rotateSub": "デバイスを回転して続行してください。"
    },
    "common": {
      "loading": "読み込み中…",
      "back": "← 戻る",
      "hubBack": "← ハブ",
      "confirm": "確定",
      "cancel": "キャンセル",
      "copy": "コピー",
      "load": "読み込み",
      "preview": "プレビュー",
      "menu": "メインメニュー",
      "seconds": "{n}秒",
      "ok": "OK"
    },
    "toast": {
      "reconnected": "ゲームに再接続しました",
      "leftActiveMatch": "進行中の対戦を退出しました",
      "noActiveMatch": "進行中の対戦が見つかりません",
      "noCardId": "コピーするカードIDがありません",
      "cardIdCopied": "カードIDをコピーしました",
      "couldNotCopyCardId": "カードIDをコピーできませんでした",
      "signInDeckBuilder": "デッキビルダーを使うにはサインインしてください。",
      "signOutDeckExperiment": "デッキ実験を使うにはサインアウトしてください。",
      "rankedMatchFound": "ランクマッチが見つかりました！",
      "casualMatchFound": "カジュアルマッチが見つかりました！",
      "passwordCopied": "パスワードをコピーしました",
      "copyFailed": "コピーに失敗しました",
      "copied": "コピーしました 📋",
      "liveOnly": "ライブ置き場にはライブまたはメンバーカードのみ置けます",
      "onlyLiveOrMember": "ライブ置き場にはライブまたはメンバーカードのみ置けます",
      "maxLiveCards": "ライブ置き場は最大{slots}枚です",
      "maxLiveCardsPlural": "ライブ置き場は最大{slots}枚です",
      "maxLiveStorage": "ライブ置き場は最大{slots}枚です",
      "maxLiveStoragePlural": "ライブ置き場は最大{slots}枚です",
      "liveStorageFull": "ライブ置き場がいっぱいです",
      "logCopied": "ログをコピーしました",
      "couldNotCopyLog": "ログをコピーできませんでした"
    },
  }
};

  function mergeLocaleAliases(loc) {
    if (!loc) return;
    loc.tagline = loc.logo && loc.logo.tagline;
    loc.lang = loc.lang || {};
    loc.lang.label = (loc.language && loc.language.label) || loc.lang.label;
    loc.auth = loc.auth || {};
    loc.auth.unranked = loc.auth.unranked || {};
    loc.auth.unranked.title = loc.menu && loc.menu.unrankedPlay;
    loc.auth.unranked.sub = loc.menu && loc.menu.unrankedSub;
    loc.auth.deckExperiment = loc.auth.deckExperiment || {};
    loc.auth.deckExperiment.title = loc.menu && loc.menu.deckExperiment;
    loc.auth.deckExperiment.sub = loc.menu && loc.menu.deckExperimentSub;
    loc.auth.tutorial = loc.auth.tutorial || {};
    loc.auth.tutorial.title = loc.menu && loc.menu.howToPlay;
    loc.auth.tutorial.sub = loc.menu && loc.menu.howToPlaySub;
    loc.hub = loc.hub || {};
    loc.hub.booster = loc.hub.booster || {};
    loc.hub.booster.title = loc.hub.openBoosters;
    loc.hub.booster.sub = loc.hub.openBoostersSub;
    loc.hub.deck = loc.hub.deck || {};
    loc.hub.deck.title = loc.hub.deckBuilder;
    loc.hub.deck.sub = loc.hub.deckBuilderSub;
    loc.hub.ranked = loc.hub.ranked || {};
    loc.hub.ranked.title = loc.hub.rankedPvp;
    loc.hub.ranked.sub = loc.hub.rankedPvpSub;
    var hubLbTitle = loc.hub.leaderboard;
    var hubLbSub = loc.hub.leaderboardSub;
    loc.hub.leaderboard = { title: hubLbTitle, sub: hubLbSub };
    var hubUnrankedTitle = typeof loc.hub.unranked === 'string' ? loc.hub.unranked : (loc.hub.unranked && loc.hub.unranked.title);
    loc.hub.unranked = {
      title: hubUnrankedTitle || (loc.menu && loc.menu.unrankedPlay),
      sub: loc.hub.unrankedSub || (loc.menu && loc.menu.unrankedSub),
    };
    loc.hub.tutorial = loc.hub.tutorial || {};
    loc.hub.tutorial.title = loc.menu && loc.menu.howToPlay;
    loc.hub.tutorial.sub = loc.menu && loc.menu.howToPlaySub;
    loc.options = loc.options || {};
    loc.options.back = loc.options.backHub;
    loc.options.foil = loc.options.enhancedTextures;
    loc.options.stuck = loc.options.stuck || {};
    loc.options.stuck.title = loc.options.stuckTitle;
    loc.options.stuck.lead = loc.options.stuckLead;
    loc.options.leaveActive = loc.options.leaveActive || 'Leave active match';
    loc.options.reset = loc.options.reset || {};
    loc.options.reset.title = loc.options.resetTitle;
    loc.options.reset.lead = loc.options.resetLead;
    loc.options.reset.btn = loc.options.resetAccount;
    loc.tut = {
      exit: loc.tutorialUi && loc.tutorialUi.exitTitle,
      back: loc.tutorialUi && loc.tutorialUi.back,
      next: loc.tutorialUi && loc.tutorialUi.next,
      finish: loc.tutorialUi && loc.tutorialUi.finish,
    };
    if (loc.phaseBar) loc.phaseMsg = Object.assign({}, loc.phaseBar);
    if (loc.lobby) {
      loc.lobby.experimentPwd = loc.lobby.experimentDeckPassword;
      loc.lobby.experimentPwdPlaceholder = loc.lobby.experimentPasswordPlaceholder;
      loc.lobby.experimentHint = loc.lobby.experimentDeckHint;
      loc.lobby.deckExperiment = loc.lobby.experimentDeckBtn;
    }
    if (loc.deck) {
      loc.deck.loadPwdPlaceholder = loc.deck.deckPasswordPlaceholder;
    }
    if (loc.hub) {
      if (!loc.hub.dailyBoosters) loc.hub.dailyBoosters = loc.hub.daily;
      if (!loc.hub.dailyWelcomeBonus) loc.hub.dailyWelcomeBonus = loc.hub.dailyBonus;
    }
    if (loc.game && loc.game.oppActivatingSkill && !loc.game.opponentSkillWait) {
      loc.game.opponentSkillWait = loc.game.oppActivatingSkill;
    }
  }

  mergeLocaleAliases(STRINGS.en);
  mergeLocaleAliases(STRINGS.ja);
  if (STRINGS.ja && STRINGS.ja.options) {
    STRINGS.ja.options.leaveActive = STRINGS.ja.options.leaveActive || '進行中の対戦を退出';
  }

  function getLocale() {
    try {
      var stored = localStorage.getItem(LLTCG_LOCALE_KEY);
      if (stored && LOCALES.indexOf(stored) !== -1) return stored;
    } catch (e) { /* ignore */ }
    return 'en';
  }

  function setLocale(loc) {
    if (LOCALES.indexOf(loc) === -1) loc = 'en';
    try { localStorage.setItem(LLTCG_LOCALE_KEY, loc); } catch (e) { /* ignore */ }
    try { document.documentElement.lang = loc; } catch (e2) { /* ignore */ }
  }

  function lookupPath(obj, key) {
    if (!obj || !key) return undefined;
    var parts = String(key).split('.');
    var cur = obj;
    for (var i = 0; i < parts.length; i++) {
      if (cur == null || typeof cur !== 'object') return undefined;
      cur = cur[parts[i]];
    }
    return cur;
  }

  function interpolate(str, vars) {
    if (!vars || typeof str !== 'string') return str;
    return str.replace(/\{([^}]+)\}/g, function (_m, name) {
      return vars[name] != null ? String(vars[name]) : _m;
    });
  }

  function t(key, vars) {
    var loc = getLocale();
    var val = lookupPath(STRINGS[loc], key);
    if (val == null && loc === 'ja') val = lookupPath(STRINGS.en, key);
    if (typeof val === 'string') return interpolate(val, vars);
    return key;
  }

  function applyI18n(root) {
    var el = root || document;
    if (!el || !el.querySelectorAll) return;

    el.querySelectorAll('[data-i18n]').forEach(function (node) {
      var key = node.getAttribute('data-i18n');
      if (!key) return;
      var text = t(key);
      if (node.getAttribute('data-i18n-html') === '1') node.innerHTML = text;
      else node.textContent = text;
    });

    el.querySelectorAll('[data-i18n-placeholder]').forEach(function (node) {
      var key = node.getAttribute('data-i18n-placeholder');
      if (key) node.placeholder = t(key);
    });

    el.querySelectorAll('[data-i18n-title]').forEach(function (node) {
      var key = node.getAttribute('data-i18n-title');
      if (key) node.title = t(key);
    });

    el.querySelectorAll('[data-i18n-aria-label]').forEach(function (node) {
      var key = node.getAttribute('data-i18n-aria-label');
      if (key) node.setAttribute('aria-label', t(key));
    });

    el.querySelectorAll('select option[data-i18n]').forEach(function (node) {
      var key = node.getAttribute('data-i18n');
      if (key) node.textContent = t(key);
    });
  }

  function cardLocaleName(card) {
    if (!card) return '';
    var loc = getLocale();
    if (loc === 'ja') return card.name || card.name_en || '';
    return card.name_en || card.name || '';
  }

  function cardLocaleText(card) {
    if (!card) return '';
    var loc = getLocale();
    if (loc === 'ja') return card.text_jp || card.text || '';
    return card.text || card.text_jp || '';
  }

  function cardLocaleType(card) {
    if (!card) return '';
    var loc = getLocale();
    if (loc === 'ja') return card.card_type || card.card_type_en || '';
    return card.card_type_en || card.card_type || '';
  }

  function tutorialDialogue(step) {
    if (!step) return '';
    if (getLocale() === 'ja') {
      if (_tutorialJa && _tutorialJa[step.id]) return _tutorialJa[step.id];
      var translated = t('tutorial.' + step.id);
      if (translated !== 'tutorial.' + step.id) return translated;
    }
    return step.dialogue || '';
  }

  function syncLocaleSelect(sel) {
    if (!sel) return;
    var loc = getLocale();
    if (sel.value !== loc) sel.value = loc;
  }

  function onLocaleSelectChange() {
    var loc = this.value;
    setLocale(loc);
    ['sel-locale-auth', 'sel-locale-hub', 'sel-locale-options'].forEach(function (id) {
      var sel = document.getElementById(id);
      if (sel && sel !== this && sel.value !== loc) sel.value = loc;
    }, this);
    try { document.documentElement.lang = loc; } catch (e) { /* ignore */ }
    applyI18n();
    localeChangeCallbacks.forEach(function (fn) {
      try { fn(loc); } catch (e) { console.error(e); }
    });
  }

  function onLocaleChange(fn) {
    if (typeof fn === 'function') localeChangeCallbacks.push(fn);
  }

  function deckLocaleName(deck, id) {
    if (!deck) return id || '';
    return cardLocaleName(deck) || deck.name || deck.name_en || id || '';
  }

  function loadTutorialJa() {
    if (_tutorialJa) return Promise.resolve(_tutorialJa);
    return fetch('./tutorial_ja.json?v=1', { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('tutorial_ja HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        _tutorialJa = data && typeof data === 'object' ? data : {};
        return _tutorialJa;
      })
      .catch(function () {
        _tutorialJa = {};
        return _tutorialJa;
      });
  }

  function initLocale(onChange) {
    if (typeof onChange === 'function') onLocaleChange(onChange);
    try { document.documentElement.lang = getLocale(); } catch (e) { /* ignore */ }
    ['sel-locale-auth', 'sel-locale-hub', 'sel-locale-options'].forEach(function (id) {
      var sel = document.getElementById(id);
      syncLocaleSelect(sel);
      if (sel && !sel._lltcgLocaleBound) {
        sel._lltcgLocaleBound = true;
        sel.addEventListener('change', onLocaleSelectChange);
      }
    });
    applyI18n();
    void loadTutorialJa();
  }

  function initLocaleUi() {
    initLocale();
  }

  window.LLTCG_I18N = {
    LLTCG_LOCALE_KEY: LLTCG_LOCALE_KEY,
    LOCALES: LOCALES,
    STRINGS: STRINGS,
    getLocale: getLocale,
    setLocale: setLocale,
    t: t,
    applyI18n: applyI18n,
    cardLocaleName: cardLocaleName,
    cardLocaleText: cardLocaleText,
    cardLocaleType: cardLocaleType,
    deckLocaleName: deckLocaleName,
    loadTutorialJa: loadTutorialJa,
    tutorialDialogue: tutorialDialogue,
    initLocale: initLocale,
    initLocaleUi: initLocaleUi,
    onLocaleChange: onLocaleChange
  };
})();
