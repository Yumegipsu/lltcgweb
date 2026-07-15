/* Love Live TCG web — i18n module */
(function () {
  'use strict';

  var LLTCG_LOCALE_KEY = 'lltcg_locale';
  var LOCALES = ['en', 'ja', 'es', 'ko'];
  var localeChangeCallbacks = [];
  var _tutorialJa = null;
  var _tutorialEs = null;
  var _tutorialKo = null;

  var STRINGS = {
  "en": {
    "logo": {
      "tagline": "The Unofficial Web Player"
    },
    "news": {
      "label": "News",
      "title": "News",
      "close": "Close",
      "backToList": "← All news",
      "empty": "No news posts yet.",
      "untitled": "Untitled",
      "cardUnknown": "Card not found: {id}",
      "newBadge": "New!"
    },
    "auth": {
      "checking": "Checking Discord sign-in…",
      "signingIn": "Signing in…",
      "signInDiscord": "Sign in with Discord",
      "guestPrompt": "Sign in with Discord to save your collection and play ranked.",
      "guestPlayHint": "Play unranked without an account, or sign in for ranked.",
      "sessionExpired": "Session expired — sign in again, or play unranked without an account.",
      "loadError": "Could not load account — you can still play unranked.",
      "guestTimeout": "Sign-in check timed out — play unranked, or retry Discord sign-in."
    },
    "menu": {
      "unrankedPlay": "Unranked Play",
      "unrankedSub": "Rooms, friends, or practice vs CPU",
      "deckExperiment": "Deck Experiment",
      "deckExperimentSub": "Build with every card — guests only, unranked",
      "howToPlay": "How to Play",
      "howToPlaySub": "Hands-on beginner lesson with Kanon"
    },
    "hub": {
      "signedIn": "Signed in",
      "signedInAs": "Signed in as",
      "signedInAsHtml": "Signed in as <b>{name}</b>",
      "dailyBoosters": "Daily boosters: {remaining} / {limit} remaining today (JST)",
      "dailyWelcomeBonus": " (welcome bonus!)",
      "daily": "Daily boosters: {remaining} / {limit} remaining today (JST)",
      "dailyBonus": " (welcome bonus!)",
      "rankedPrCount": "{remaining} / {limit}",
      "rankedPrTitle": "Ranked PR rewards remaining today: {remaining} / {limit} (JST)",
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
      "tournamentMode": "Tournament Mode",
      "tournamentModeSub": "Coming Soon",
      "howToPlay": "How to Play",
      "howToPlaySub": "Hands-on beginner lesson with Kanon",
      "backHub": "← Hub",
      "missions": "Missions"
    },
    "missions": {
      "title": "Missions",
      "tabDaily": "Daily",
      "tabMilestone": "Milestones",
      "claim": "Claim",
      "loading": "Loading missions…",
      "empty": "No missions in this tab.",
      "statusActive": "In progress",
      "statusReady": "Ready to claim",
      "statusClaimed": "Claimed",
      "statusLocked": "Locked",
      "completeToast": "Mission complete: {title}",
      "claimedToast": "Claimed {title} (+{reward})",
      "daily": {
        "openAllBoosters": "Open all daily booster packs",
        "rankedMatch": "Play a ranked match",
        "useStamp": "Use a stamp during a match",
        "completeAll": "Complete all daily missions"
      },
      "milestone": {
        "profileBanner": "Update your profile banner",
        "profileFlag": "Set a flag on your profile",
        "profileStamps": "Set favorite stickers",
        "ranked1": "Play 1 ranked match",
        "unranked1": "Play 1 unranked match",
        "ranked5": "Play 5 ranked matches",
        "ranked10": "Play 10 ranked matches",
        "ranked50": "Play 50 ranked matches",
        "ranked100": "Play 100 ranked matches",
        "ranked500": "Play 500 ranked matches",
        "ranked1000": "Play 1,000 ranked matches",
        "winMuse": "Win with a μ's-only main deck",
        "winAqours": "Win with an Aqours-only main deck",
        "winLiella": "Win with a Liella!-only main deck",
        "winHasunosora": "Win with a Hasunosora-only main deck",
        "winNijigasaki": "Win with a Nijigasaki-only main deck",
        "cards400": "Own 400 cards",
        "cards800": "Own 800 cards",
        "cards1200": "Own 1,200 cards",
        "cards1600": "Own 1,600 cards"
      },
      "rewardStarter": "Choose a starter deck",
      "rewardStarterOwned": "Already owned",
      "claimedStarterToast": "Claimed {title} — unlocked {deck}",
      "starterPickTitle": "Choose a starter deck",
      "starterPickConfirm": "Unlock starter",
      "starterPickCancel": "Cancel"
    },
    "language": {
      "label": "Language",
      "en": "English",
      "ja": "日本語",
      "es": "Español",
      "ko": "한국어"
    },
    "lobby": {
      "title": "Unranked Play",
      "yourName": "Your Name",
      "namePlaceholder": "Idol name…",
      "deck": "Deck",
      "createRoom": "Create Room",
      "joinRoom": "Join Room",
      "roomCode": "Room Code",
      "roomCodePlaceholder": "ABCD1234",
      "vsPlayer": "VS Player",
      "vsCpu": "VS CPU",
      "practiceCpu": "Practice vs CPU",
      "cpuDifficulty": "CPU Difficulty",
      "cpuEasy": "Easy — random starter deck",
      "cpuNormal": "Normal — smarter skills & Lives",
      "cpuHard": "Hard — strong deck & skill priority",
      "cpuExpert": "Expert — dry-run search & threat projection",
      "cpuEasyShort": "Easy",
      "cpuNormalShort": "Normal",
      "cpuHardShort": "Hard",
      "cpuExpertShort": "Expert",
      "soloStarting": "Starting vs CPU ({diff})",
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
      "basicDecks": "Basic decks",
      "starters": {
        "nijigasaki": "Nijigasaki Start Deck",
        "muse": "μ's Start Deck",
        "liella": "Liella! Start Deck",
        "hasunosora": "Hasunosora Start Deck",
        "sunshine": "Sunshine!! Start Deck"
      },

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
      "hint": "Auto-build optimizes from your collection · tap to add/remove · hover to preview · point total must be 9 or below",
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
      "experimentHint": "Full card pool · build a legal deck to generate a password · point total must be 9 or below · hold or right-click cards for details",
      "collectionOwned": "Total cards owned · {count}",
      "collectionLoading": "Full card pool · loading…",
      "collectionMatch": "Collection · {match} match",
      "deckStats": "Total {total}/72 · Members {members}/48 · Lives {lives}/12 · Energy {energy}/12 · Points {lovecaPoints}/{lovecaLimit}",
      "lovecaPointLabel": "Points",
      "lovecaPointBadge": "{n}pt",
      "lovecaOverLimit": "Point total would be {total} (max {limit}).",
      "lovecaDeckIllegal": "Point total is {total} — deck must be {limit} or below.",
      "deckIllegalSize": "Deck must be legal: 60 main (48 Members, 12 Lives) and 12 Energy.",
      "lovecaExplain": "Some strong cards cost points. Your main deck point total must stay at 9 or below (each copy counts).",
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
      "openPack": "Open Pack ({n} cards)",
      "openBox": "Open Box ({n} packs)",
      "openPaidBox": "Open 1 box ({n} packs)",
      "selectBoxFirst": "Select a booster set first",
      "needMoreGems": "Need {n} Star Gems to open a box",
      "noPacksOrGems": "No daily packs left and not enough Star Gems.",
      "ratesLead": "{pool} cards in pool · chance to appear in a {n}-card pack",
      "packOpened": "Pack Opened",
      "godPack": "GOD PACK!",
      "openAnother": "Open another pack",
      "openSameAgain": "Open same again",
      "packsLeft": "{n} pack(s) left today (JST)",
      "mainMenu": "Main menu",
      "backHub": "← Hub",
      "noDailyPacks": "No daily packs left",
      "paidLead": "Spend Star Gems to keep opening packs today.",
      "openOnePack": "Open 1 pack",
      "starGemsLabel": "Star Gems:",
      "starGemsUnit": "{n} Star Gems",
      "dailyPack": "Daily pack",
      "packRatesTitle": "Pack rates",
      "packRatesPerPack": "Per pack · tap a card for details",
      "ratesLoading": "Loading rates…",
      "duplicatesConverted": "Duplicates converted",
      "migrationText": "Extra Member/Live copies beyond 4 per card and Energy copies beyond 12 per card were converted into {gems}.",
      "convertedToGems": " · {n} converted to Star Gems",

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
      "prRemaining": "PR rewards today: {remaining} / {limit} remaining (JST)",
      "record": "{wins}W-{losses}L · {winPct}% win",
      "recordFull": "{wins}W-{losses}L · {winPct}% win · {lossPct}% loss",
      "queueStats": "{waiting} waiting · {inGame} in ranked games",
      "searching": "Searching… ({seconds}s)",
      "readySearch": "Ready to search"
    },
    "leaderboard": {
      "title": "Ranked Leaderboard",
      "lead": "Highest ELO from ranked PvP. Set a card banner and flag for your profile row.",
      "empty": "No ranked games yet — play ranked PvP to appear here.",
      "editBanner": "Edit profile",
      "eloSuffix": " ELO",
      "eloLabel": "{elo} ELO",
      "profileBanner": "Profile banner",
      "bannerLead": "Choose a card you own, then drag the strip vertically to pick the art shown on your leaderboard card.",
      "bannerSearchPlaceholder": "Search by card name…",
      "bannerNoMatch": "No cards match your search.",
      "bannerPreview": "Preview",
      "saveBanner": "Save banner",
      "profileFlag": "Profile flag",
      "flagLead": "Choose a flag to show next to your name on the leaderboard.",
      "flagSearchPlaceholder": "Search flags…",
      "equipFlag": "Equip flag",
      "flagNone": "None",
      "flagEquipped": "Currently equipped.",
      "flagReady": "Equip {name}?",
      "flagLoading": "Loading flags…",
      "selectCardFirst": "Select a card first",
      "yourRank": "Your rank: #{rank}",
      "jumpToYou": "Jump to my entry"
    },
    "stamps": {
      "send": "💬 Stamps",
      "pickerTitle": "Send stamp",
      "profilePickTitle": "Favorite stamps",
      "profileSection": "Favorite stamps",
      "editProfile": "Edit favorite stamps",
      "profilePickLead": "Tap stamps to add or remove them (max 20). Used in the ★ Favorites tab during PvP.",
      "profileCount": "{n} / {max} selected",
      "profileHint": "These appear in the ★ Favorites tab when you send stamps in PvP.",
      "profileHintEmpty": "Optional — choose up to 20 stamps for quick access in matches.",
      "profileFull": "You can only save {max} favorite stamps.",
      "tabJa": "日本語",
      "tabEn": "English",
      "tabFavorites": "★ Favorites",
      "audio": "Stamp audio",
      "audioMenu": "Stamp voice clips",
      "voiceVolume": "Stamp voice volume",
      "favoritesEmpty": "No favorites yet — set them in Options, or tap ☆ on a stamp.",
      "empty": "No stamps.",
      "cooldown": "Wait a moment…",
      "done": "Done"
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
      "resignConfirm": "Resign?",
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
      "zoneEmpty": "Empty",
      "energyDeck": "Energy Deck",
      "liveStorage": "Live Storage",
      "successStorage": "Success Live Storage",
      "stageBoard": "Stage Board",
      "activatableSkills": "Activatable skills",
      "activeEffects": "Active effects",
      "hoverHandEmpty": "Hover a card in your hand to preview it here.",
      "hoverPickerEmpty": "Hover a card to preview it here.",
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
      "sidebarInfo": "{turn}<span class=\"turn-sep\">·</span>Phase: {phase}<span class=\"turn-sep\">·</span>Active: {active}<span class=\"turn-sep\">·</span>First: {first}",
      "deckTopLabel": "Deck top",

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
      "livePlayer": "{name}'s Live Phase",
      "livePlayerS": "{name}' Live Phase",
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
      "theirMainS": "{name}' Main Phase",
      "yourLive": "Your Live Phase",
      "theirLive": "{name}'s Live Phase",
      "theirLiveS": "{name}' Live Phase"
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
      "liveStartFlash": "LIVE START",
      "liveJudgeTieCappedBoth": "Tie on Live Score — neither adds a Success Live (both already at 2)",
      "liveJudgeTieYouCappedWin": "Tie — you are capped at 2 Success Lives",
      "liveJudgeTieOppEarns": "Tie — opponent earns a Success Live",
      "liveJudgeTieYouEarns": "Tie — you earn a Success Live",
      "liveJudgeTieOppCappedWin": "Tie — opponent is capped at 2 Success Lives",
      "liveJudgeTieBothSucceed": "Tie on Live Score — both succeed!",
      "liveJudgeYouWin": "You win on Live Score!",
      "liveJudgeOppWin": "Opponent wins on Live Score",
      "liveJudgeNamedWin": "{name} wins on Live Score"
    },
    "mulligan": {
      "title": "Opening Hand 🌸",
      "hint": "Tap cards to mark for replacement. Hold a card to view its details. Tap again to unmark.",
      "tutorialKeepHint": "Happy with this hand? Tap Keep Hand to continue.",
      "tutorialReplaceHint": "Tap the highlighted card to mark it for replacement, then confirm.",
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
      "combinedHearts": "Combined required hearts",
      "livesSelected": "{n} Lives",
      "livesSelectedOne": "1 Live",
      "plusMembers": "+{n} Members",
      "plusMembersOne": "+1 Member",
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
      "activateSub": "Choose whether to activate this effect.",
      "lookAtDeck": "Look at deck",
      "surveilHint": "Spot 1 is the top of your deck. Drag cards between numbered spots and the Waiting Room, tap two cards to swap, or tap a spot / the Waiting Room while a card is selected.",
      "surveilHintReturnAll": "Spot 1 is the top of your deck. Drag cards between numbered spots, or tap two cards to swap. All cards must stay on top of the deck.",
      "wrPickTitle": "Waiting Room",
      "wrPickMsg": "Choose a card from your Waiting Room to add to your hand.",
      "yellPickTitle": "Yell",
      "yellPickMsg": "Choose 1 card revealed by Yell.",
      "successLivePickTitle": "Success Live",
      "successLivePickMsg": "Choose 1 Live card to place in Success Live.",
      "successLiveHandTitle": "Success Live",
      "successLiveHandMsg": "Choose 1 card from your Success Live area to add to your hand.",
      "deckTopTitle": "Deck top",
      "deckTopMsg": "Choose 1 card revealed for Yell to put on top of your deck.",
      "wrEmpty": "Waiting Room is empty",
      "wrNoMatch": "No matching cards in Waiting Room",
      "yellNoCards": "No Yell cards to choose from",
      "noLiveSuccess": "No Live cards to place in Success Live",
      "searchDeckFor": "Search deck for…",
      "deckTopPick": "Deck top",

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
      },
      "wait": {
        "title": "Wait (ウェイト)",
        "body": "A Member put into Wait cannot contribute its Blade that turn — its Blade does not increase cards revealed for Yell during Live Performance. This is not the Waiting Room."
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
      "bladeHearts": "Blade hearts",
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
      "spectatorWinner": "{name} Wins!",
      "spectatorStatsLine": "Turn: {turn} | {p1}: {p1Lives}/3 | {p2}: {p2Lives}/3",
      "findAnother": "Find Another Match",
      "rematchOffer": "Rematch",
      "rematchAccept": "Accept Rematch",
      "rematchWaiting": "Waiting…",
      "rematchWaitingHint": "Waiting for your opponent to accept the rematch.",
      "rematchOppWants": "{name} wants a rematch!",
      "disconnectedYou": "You were disconnected from the match.",
      "disconnectedOpp": "{name} disconnected.",
      "statsLine": "Turn: {turn} | Your successes: {yours}/3 | Opp successes: {opp}/3",
      "debugSaveReplay": "💾 Save replay",
      "saveReplay": "Save Replay",
      "saveReplayToLibrary": "Save Replay to Library",
      "downloadReplayJson": "Download Replay JSON",
      "debugSaveLog": "💾 Save debug log",
      "debugCopyLog": "📋 Copy log",
      "debugSaveBundle": "📦 Export debug bundle",
      "rankedPrNew": "Ranked win reward: {name}",
      "rankedPrDupe": "{name} converted to {gems} Star Gems (over copy limit)",
      "rankedPrDailyCap": "Daily ranked PR rewards used ({limit}/day JST)",
      "rankedPrPopupTitle": "Ranked win reward!"
    },
    "replay": {
      "menuTitle": "Replay Viewer",
      "menuSubAuth": "Load a saved match replay and watch it in realtime",
      "menuSubHub": "Watch saved match replays from your library",
      "title": "Replay Viewer",
      "back": "← Back",
      "lead": "Finished matches autosave to Recent (last 10). Manually saved replays stay forever under Saved — you can also preserve a Recent replay from this screen.",
      "refreshLibrary": "Refresh library",
      "recentSection": "Recent matches",
      "recentHint": "Autosaved from your last 10 games. Oldest is replaced automatically.",
      "savedSection": "Saved forever",
      "savedHint": "Kept when you save from the results screen, or preserve a Recent replay here.",
      "preserve": "Keep forever",
      "preservedToast": "Replay moved to Saved forever",
      "autosavedRecent": "Replay autosaved to Recent",
      "emptyRecent": "No recent autosaves yet. Finish a signed-in match to fill this list.",
      "emptySaved": "No permanent saves yet. Use Save Replay on the results screen, or Keep forever on a Recent replay.",
      "importLead": "Have an exported replay file? Import JSON here as a secondary option.",
      "fileLabel": "Replay file",
      "noFileSelected": "No file selected.",
      "startImported": "Start imported replay",
      "playPause": "Play / Pause",
      "positionAria": "Replay position",
      "handoffNote": "Replay complete — you have control. CPU plays the opponent.",
      "exitReplay": "Exit Replay",
      "phaseBarHint": "Replay {step} / {total} — use the replay bar below to step through recorded actions.",
      "signInLibrary": "Sign in to save and view replays in your library.",
      "emptyLibrary": "No saved replays yet. Finish a match — it will autosave to Recent.",
      "watch": "Watch",
      "downloadJson": "Download JSON",
      "loadingLibrary": "Loading saved replays...",
      "loadLibraryFailed": "Could not load saved replays.",
      "win": "Win",
      "loss": "Loss",
      "replayLabel": "Replay",
      "resultAs": "{result} as {name}",
      "summarySaved": "Saved {date}",
      "summaryRoom": "Room {room}",
      "summaryVs": "vs {name}",
      "summaryTurn": "Turn {turn}",
      "summaryActions": "{count} action",
      "summaryActionsPlural": "{count} actions",
      "metaSaver": "Saver: {name}",
      "metaPerspective": "Perspective: {id}",
      "metaSavedAt": "Saved: {at}",
      "metaSnapshot": "Snapshot: turn {turn}, phase {phase}",
      "metaDuration": "Duration: {duration}",
      "metaActions": "Actions: {count}",
      "unknownDate": "Unknown date",
      "loadedToast": "Replay loaded — {count} action(s)",
      "downloadedJson": "Replay JSON downloaded",
      "downloadFailed": "Could not download replay",
      "payloadMissing": "Replay payload missing",
      "startSavedFailed": "Could not start saved replay",
      "unsupportedSchema": "Unsupported replay schema",
      "invalidFile": "Invalid replay file",
      "chooseFileFirst": "Choose a replay file first.",
      "saveAfterFinish": "Save replay is available after the match finishes.",
      "noCredentials": "No match credentials found for replay export.",
      "savedToLibrary": "Replay saved forever in your library",
      "savedToLibraryId": "Replay saved forever (#{id})",
      "downloadedAsJson": "Replay downloaded as JSON",
      "couldNotSave": "Could not save replay"
    },
    "apiError": {
      "titleClient": "Something went wrong",
      "titleServer": "Server error",
      "hintClient": "Try refreshing the page if the game looks stuck.",
      "hintServer": "Try refreshing the page. If it keeps happening, wait a moment and try again.",
      "connectionFailed": "Could not reach the server. Try refreshing the page."
    },
    "tutorialMeta": {
      "title": "Beginner Tutorial",
      "labelOpponent": "Player2"
    },
    "tutorial": {
      "speaker": "Shibuya Kanon",
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
    },
    "spectate": {
      "listTitle": "Spectate match",
      "listTitleRanked": "Spectate ranked match",
      "listTitleCasual": "Spectate unranked match",
      "lead": "Watch an ongoing match — view only, no interaction.",
      "barReadOnly": "Spectating — read only",
      "barNames": "Spectating {p1} vs {p2}",
      "leave": "Leave",
      "reconnected": "Reconnected to spectating.",
      "watch": "Watch",
      "loading": "Loading…",
      "noMatches": "No matches available to spectate.",
      "matchEnded": "Match ended — returning to lobby.",
      "sessionEnded": "Spectator session ended.",
      "count": "Spectators: {n}",
      "switchPerspective": "Switch perspective"
    },
    "ui": {
      "fullscreen": "Full screen",
      "rankedSearch": "Ranked search",
      "casualSearch": "Casual search",
      "skipToResults": "Skip to results",
      "clickToOpen": "Click to open"
    }

  ,
    "cardType": {
      "member": "Member",
      "live": "Live",
      "energy": "Energy"
    }},
  "ja": {
    "logo": {
      "tagline": "非公式ウェブプレイヤー"
    },
    "news": {
      "label": "お知らせ",
      "title": "お知らせ",
      "close": "閉じる",
      "backToList": "← 一覧へ",
      "empty": "お知らせはまだありません。",
      "untitled": "無題",
      "cardUnknown": "カードが見つかりません：{id}",
      "newBadge": "新着"
    },
    "auth": {
      "checking": "Discordサインインを確認中…",
      "signingIn": "サインイン中…",
      "signInDiscord": "Discordでサインイン",
      "guestPrompt": "Discordでサインインしてコレクションを保存し、ランク戦をプレイしましょう。",
      "guestPlayHint": "アカウントなしで非ランクプレイするか、サインインしてランク戦をプレイできます。",
      "sessionExpired": "セッションの有効期限が切れました——再度サインインするか、アカウントなしで非ランクプレイしてください。",
      "loadError": "アカウントを読み込めませんでした——非ランクプレイは引き続き可能です。",
      "guestTimeout": "サインイン確認がタイムアウトしました——非ランクでプレイするか、Discordサインインを再試行してください。"
    },
    "menu": {
      "unrankedPlay": "カジュアル対戦",
      "unrankedSub": "ルーム作成、フレンド対戦、CPU練習",
      "deckExperiment": "デッキ実験",
      "deckExperimentSub": "全カードで構築——ゲスト限定、非ランク",
      "howToPlay": "遊び方",
      "howToPlaySub": "かのんと一緒に体験する初心者レッスン"
    },
    "hub": {
      "signedIn": "サインイン済み",
      "signedInAs": "サインイン中：",
      "signedInAsHtml": "サインイン中：<b>{name}</b>",
      "dailyBoosters": "デイリーブースター：本日（JST）残り {remaining} / {limit}",
      "dailyWelcomeBonus": "（ウェルカムボーナス！）",
      "daily": "デイリーブースター：本日（JST）残り {remaining} / {limit}",
      "dailyBonus": "（ウェルカムボーナス！）",
      "rankedPrCount": "{remaining} / {limit}",
      "rankedPrTitle": "本日のランクマPR報酬残り：{remaining} / {limit}（JST）",
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
      "tournamentMode": "トーナメントモード",
      "tournamentModeSub": "近日公開",
      "howToPlay": "遊び方",
      "howToPlaySub": "かのんと一緒に体験する初心者レッスン",
      "backHub": "← ハブ",
      "missions": "ミッション"
    },
    "missions": {
      "title": "ミッション",
      "tabDaily": "デイリー",
      "tabMilestone": "マイルストーン",
      "claim": "受け取る",
      "loading": "読み込み中…",
      "empty": "このタブにミッションはありません。",
      "statusActive": "進行中",
      "statusReady": "受け取り可能",
      "statusClaimed": "受取済み",
      "statusLocked": "ロック中",
      "completeToast": "ミッション達成：{title}",
      "claimedToast": "{title} を受け取りました（+{reward}）",
      "daily": {
        "openAllBoosters": "デイリーブースターをすべて開封",
        "rankedMatch": "ランクマッチをプレイ",
        "useStamp": "対戦中にスタンプを使う",
        "completeAll": "デイリーミッションをすべて達成"
      },
      "milestone": {
        "profileBanner": "プロフィールバナーを設定",
        "profileFlag": "プロフィールに国旗を設定",
        "profileStamps": "お気に入りスタンプを設定",
        "ranked1": "ランクマッチを1回プレイ",
        "unranked1": "カジュアルマッチを1回プレイ",
        "ranked5": "ランクマッチを5回プレイ",
        "ranked10": "ランクマッチを10回プレイ",
        "ranked50": "ランクマッチを50回プレイ",
        "ranked100": "ランクマッチを100回プレイ",
        "ranked500": "ランクマッチを500回プレイ",
        "ranked1000": "ランクマッチを1,000回プレイ",
        "winMuse": "μ'sのみのメインデッキで勝利",
        "winAqours": "Aqoursのみのメインデッキで勝利",
        "winLiella": "Liella!のみのメインデッキで勝利",
        "winHasunosora": "蓮ノ空のみのメインデッキで勝利",
        "winNijigasaki": "虹ヶ咲のみのメインデッキで勝利",
        "cards400": "カードを400枚所持する",
        "cards800": "カードを800枚所持する",
        "cards1200": "カードを1,200枚所持する",
        "cards1600": "カードを1,600枚所持する"
      },
      "rewardStarter": "スターターデッキを選ぶ",
      "rewardStarterOwned": "所持済み",
      "claimedStarterToast": "{title} を受け取りました — {deck} を解放",
      "starterPickTitle": "スターターデッキを選ぶ",
      "starterPickConfirm": "スターターを解放",
      "starterPickCancel": "キャンセル"
    },
    "language": {
      "label": "言語",
      "en": "English",
      "ja": "日本語",
      "es": "Español",
      "ko": "한국어"
    },
    "lobby": {
      "title": "カジュアル対戦",
      "yourName": "名前",
      "namePlaceholder": "アイドル名…",
      "deck": "デッキ",
      "createRoom": "ルーム作成",
      "joinRoom": "ルーム参加",
      "roomCode": "ルームコード",
      "roomCodePlaceholder": "ABCD1234",
      "vsPlayer": "対プレイヤー",
      "vsCpu": "対CPU",
      "practiceCpu": "CPU練習",
      "cpuDifficulty": "CPU難易度",
      "cpuEasy": "イージー——ランダムスターターデッキ",
      "cpuNormal": "ノーマル——スキルとライブを賢く使う",
      "cpuHard": "ハード——強力なデッキとスキル優先",
      "cpuExpert": "エキスパート——ドライラン探索と脅威予測",
      "cpuEasyShort": "イージー",
      "cpuNormalShort": "ノーマル",
      "cpuHardShort": "ハード",
      "cpuExpertShort": "エキスパート",
      "soloStarting": "CPU（{diff}）との対戦を開始",
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
      "basicDecks": "基本デッキ",
      "starters": {
        "nijigasaki": "虹ヶ咲スタートデッキ",
        "muse": "μ'sスタートデッキ",
        "liella": "スーパースター!!スタートデッキ",
        "hasunosora": "蓮ノ空女学院スクールアイドルクラブスタートデッキ",
        "sunshine": "サンシャイン!!スタートデッキ"
      },

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
      "hint": "自動構築はコレクションから最適化 · タップで追加/削除 · ホバーでプレビュー · ポイント合計は9以下",
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
      "experimentHint": "全カードプール · 合法デッキを組んでパスワード発行 · ポイント合計は9以下 · 長押しまたは右クリックで詳細",
      "collectionOwned": "所持カード合計 · {count}",
      "collectionLoading": "全カードプール · 読み込み中…",
      "collectionMatch": "コレクション · {match} 件一致",
      "deckStats": "合計 {total}/72 · メンバー {members}/48 · ライブ {lives}/12 · エネルギー {energy}/12 · ポイント {lovecaPoints}/{lovecaLimit}",
      "lovecaPointLabel": "ポイント",
      "lovecaPointBadge": "{n}pt",
      "lovecaOverLimit": "ポイント合計が {total} になります（上限 {limit}）。",
      "lovecaDeckIllegal": "ポイント合計 {total} — デッキは {limit} 以下である必要があります。",
      "deckIllegalSize": "合法デッキ：メイン60（メンバー48・ライブ12）＋エネルギー12。",
      "lovecaExplain": "強力なカードにはポイントが設定されています。メインデッキのポイント合計は9以下（枚数分カウント）にしてください。",
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
      "openPack": "パック開封（{n}枚）",
      "openBox": "ボックス開封（{n}パック）",
      "openPaidBox": "ボックス開封（{n}パック）",
      "selectBoxFirst": "先にブースターを選んでください",
      "needMoreGems": "ボックス開封にはスタージェム {n} が必要です",
      "noPacksOrGems": "本日の無料パックがなく、スタージェムも足りません。",
      "ratesLead": "プール {pool} 枚 · {n}枚パックで出現する確率",
      "packOpened": "パック開封完了",
      "godPack": "GOD PACK!",
      "openAnother": "もう1パック開封",
      "openSameAgain": "同じパックをもう一度",
      "packsLeft": "本日（JST）残り {n} パック",
      "mainMenu": "メインメニュー",
      "backHub": "← ハブ",
      "noDailyPacks": "本日の無料パックはありません",
      "paidLead": "スタージェムを使って本日もパックを開けられます。",
      "openOnePack": "パックを1つ開ける",
      "starGemsLabel": "スタージェム：",
      "starGemsUnit": "スタージェム {n}",
      "dailyPack": "デイリーパック",
      "packRatesTitle": "パック排出率",
      "packRatesPerPack": "1パックあたり · カードをタップで詳細",
      "ratesLoading": "排出率を読み込み中…",
      "duplicatesConverted": "重複を変換しました",
      "migrationText": "カード4枚・エネルギー12枚を超える重複は {gems} に変換されました。",
      "convertedToGems": " · {n} をスタージェムに変換",

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
      "prRemaining": "本日のPR報酬：残り {remaining} / {limit}（JST）",
      "record": "{wins}勝-{losses}敗 · 勝率 {winPct}%",
      "recordFull": "{wins}勝-{losses}敗 · 勝率 {winPct}% · 敗率 {lossPct}%",
      "queueStats": "待機 {waiting} · ランク対戦中 {inGame}",
      "searching": "検索中…（{seconds}秒）",
      "readySearch": "検索可能"
    },
    "leaderboard": {
      "title": "ランクリーダーボード",
      "lead": "ランクPvPの最高ELO。プロフィール行にカードバナーと国旗を設定できます。",
      "empty": "ランク対戦の記録がまだありません——ランクPvPでプレイして掲載しましょう。",
      "editBanner": "プロフィールを編集",
      "eloSuffix": " ELO",
      "eloLabel": "ELO {elo}",
      "profileBanner": "プロフィールバナー",
      "bannerLead": "所持カードを選び、縦にドラッグしてリーダーボードカードに表示するアートを選んでください。",
      "bannerSearchPlaceholder": "カード名で検索…",
      "bannerNoMatch": "検索に一致するカードがありません。",
      "bannerPreview": "プレビュー",
      "saveBanner": "バナーを保存",
      "profileFlag": "プロフィール国旗",
      "flagLead": "リーダーボードの名前の横に表示する国旗を選んでください。",
      "flagSearchPlaceholder": "国旗を検索…",
      "equipFlag": "国旗を装備",
      "flagNone": "なし",
      "flagEquipped": "現在装備中です。",
      "flagReady": "{name} を装備しますか？",
      "flagLoading": "国旗を読み込み中…",
      "selectCardFirst": "先にカードを選択してください",
      "yourRank": "あなたの順位: #{rank}",
      "jumpToYou": "自分の行へ移動"
    },
    "stamps": {
      "send": "💬 スタンプ",
      "pickerTitle": "スタンプを送る",
      "profilePickTitle": "お気に入りスタンプ",
      "profileSection": "お気に入りスタンプ",
      "editProfile": "お気に入りスタンプを編集",
      "profilePickLead": "タップで追加・解除（最大20個）。PvPの★お気に入りタブに表示されます。",
      "profileCount": "{n} / {max} 選択中",
      "profileHint": "PvPでスタンプを送るとき、★お気に入りタブに表示されます。",
      "profileHintEmpty": "任意 — 対戦で使うお気に入りを最大20個まで選べます。",
      "profileFull": "お気に入りスタンプは最大{max}個までです。",
      "tabJa": "日本語",
      "tabEn": "English",
      "tabFavorites": "★ お気に入り",
      "audio": "スタンプ音声",
      "audioMenu": "スタンプのボイス",
      "voiceVolume": "スタンプボイスの音量",
      "favoritesEmpty": "お気に入りがありません — オプションで設定するか、☆をタップしてください。",
      "empty": "スタンプがありません。",
      "cooldown": "少し待ってください…",
      "done": "完了"
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
      "resignConfirm": "降参しますか？",
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
      "zoneEmpty": "空",
      "energyDeck": "エネルギーデッキ",
      "liveStorage": "ライブ置き場",
      "successStorage": "成功ライブ置き場",
      "stageBoard": "ステージボード",
      "activatableSkills": "起動可能スキル",
      "activeEffects": "有効な効果",
      "hoverHandEmpty": "手札のカードにホバーしてプレビュー。",
      "hoverPickerEmpty": "カードにホバーしてプレビュー。",
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
      "sidebarInfo": "{turn}<span class=\"turn-sep\">·</span>フェイズ：{phase}<span class=\"turn-sep\">·</span>アクティブ：{active}<span class=\"turn-sep\">·</span>先攻：{first}",
      "deckTopLabel": "デッキトップ",

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
      "livePlayer": "{name}のライブフェイズ",
      "livePlayerS": "{name}のライブフェイズ",
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
      "theirMainS": "{name}のメインフェイズ",
      "yourLive": "あなたのライブフェイズ",
      "theirLive": "{name}のライブフェイズ",
      "theirLiveS": "{name}のライブフェイズ"
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
      "liveStartFlash": "LIVE START",
      "liveJudgeTieCappedBoth": "ライブスコア同点 — どちらも成功ライブ追加なし（両者とも2枚）",
      "liveJudgeTieYouCappedWin": "同点 — あなたは成功ライブ2枚上限",
      "liveJudgeTieOppEarns": "同点 — 相手が成功ライブを獲得",
      "liveJudgeTieYouEarns": "同点 — あなたが成功ライブを獲得",
      "liveJudgeTieOppCappedWin": "同点 — 相手は成功ライブ2枚上限",
      "liveJudgeTieBothSucceed": "ライブスコア同点 — 両者成功！",
      "liveJudgeYouWin": "ライブスコアであなたの勝ち！",
      "liveJudgeOppWin": "ライブスコアで相手の勝ち",
      "liveJudgeNamedWin": "ライブスコアで{name}の勝ち"
    },
    "mulligan": {
      "title": "初期手札 🌸",
      "hint": "タップで交換マーク。長押しで詳細。もう一度タップで解除。",
      "tutorialKeepHint": "この手札でよければ、「この手札で開始」を押して進んでください。",
      "tutorialReplaceHint": "ハイライトされたカードをタップして交換マークを付け、確定してください。",
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
      "combinedHearts": "合計必要ハート",
      "livesSelected": "ライブ{n}枚",
      "livesSelectedOne": "ライブ1枚",
      "plusMembers": "＋メンバー{n}枚",
      "plusMembersOne": "＋メンバー1枚",
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
      "activateSub": "この効果を発動するか選んでください。",
      "lookAtDeck": "デッキを見る",
      "surveilHint": "番号1がデッキの一番上です。番号付きスポットと控え室の間でカードをドラッグし、2枚タップで入れ替え、またはカード選択中にスポット／控え室をタップしてください。",
      "surveilHintReturnAll": "番号1がデッキの一番上です。番号付きスポット間でドラッグするか、2枚タップで入れ替えてください。すべてのカードをデッキの上に戻してください。",
      "wrPickTitle": "控え室",
      "wrPickMsg": "控え室から手札に加えるカードを1枚選んでください。",
      "yellPickTitle": "エール",
      "yellPickMsg": "エールで公開されたカードを1枚選んでください。",
      "successLivePickTitle": "成功ライブ",
      "successLivePickMsg": "成功ライブに置くライブカードを1枚選んでください。",
      "successLiveHandTitle": "成功ライブ",
      "successLiveHandMsg": "成功ライブ置き場から手札に加えるカードを1枚選んでください。",
      "deckTopTitle": "デッキトップ",
      "deckTopMsg": "エールで公開されたカードを1枚選び、デッキの一番上に置いてください。",
      "wrEmpty": "控え室が空です",
      "wrNoMatch": "控え室に該当カードがありません",
      "yellNoCards": "選べるエールカードがありません",
      "noLiveSuccess": "成功ライブに置けるライブカードがありません",
      "searchDeckFor": "デッキから探す…",
      "deckTopPick": "デッキトップ",

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
      },
      "wait": {
        "title": "ウェイト",
        "body": "ウェイトにしたメンバーは、そのターンのエールで公開する枚数にブレードを加えません。控え室とは別の状態です。"
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
      "bladeHearts": "ブレードハート",
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
      "spectatorWinner": "{name}の勝利！",
      "spectatorStatsLine": "ターン：{turn} | {p1}：{p1Lives}/3 | {p2}：{p2Lives}/3",
      "findAnother": "別のマッチを探す",
      "rematchOffer": "再戦",
      "rematchAccept": "再戦を受ける",
      "rematchWaiting": "待機中…",
      "rematchWaitingHint": "相手が再戦を受け入れるのを待っています。",
      "rematchOppWants": "{name}が再戦を希望しています！",
      "disconnectedYou": "対戦から切断されました。",
      "disconnectedOpp": "{name}が切断しました。",
      "statsLine": "ターン：{turn} | あなたの成功：{yours}/3 | 相手の成功：{opp}/3",
      "debugSaveReplay": "💾 リプレイを保存",
      "saveReplay": "リプレイを保存",
      "saveReplayToLibrary": "リプレイをライブラリに保存",
      "downloadReplayJson": "リプレイJSONをダウンロード",
      "debugSaveLog": "💾 デバッグログを保存",
      "debugCopyLog": "📋 ログをコピー",
      "debugSaveBundle": "📦 デバッグ一式を出力",
      "rankedPrNew": "ランクマ報酬：{name}",
      "rankedPrDupe": "{name}は所持上限のためスタージェム{gems}に変換",
      "rankedPrDailyCap": "本日のランクマPR報酬は上限です（{limit}回/日・JST）",
      "rankedPrPopupTitle": "ランクマ勝利報酬！"
    },
    "replay": {
      "menuTitle": "リプレイビューア",
      "menuSubAuth": "保存した対戦リプレイを読み込み、リアルタイムで再生",
      "menuSubHub": "ライブラリから保存済みリプレイを視聴",
      "title": "リプレイビューア",
      "back": "← 戻る",
      "lead": "終了した対戦はRecent（直近10件）に自動保存されます。結果画面で保存したリプレイはSavedに永久保存。Recentから「永久保存」もできます。",
      "refreshLibrary": "ライブラリを更新",
      "recentSection": "最近の対戦",
      "recentHint": "直近10試合を自動保存。古いものから上書きされます。",
      "savedSection": "永久保存",
      "savedHint": "結果画面の保存、またはRecentからの永久保存で残ります。",
      "preserve": "永久保存",
      "preservedToast": "リプレイを永久保存に移しました",
      "autosavedRecent": "リプレイをRecentに自動保存しました",
      "emptyRecent": "自動保存はまだありません。サインインして対戦を終えるとここに入ります。",
      "emptySaved": "永久保存はまだありません。結果画面で保存するか、Recentから永久保存してください。",
      "importLead": "エクスポートしたリプレイファイルがありますか？ JSONをここから読み込めます（サブオプション）。",
      "fileLabel": "リプレイファイル",
      "noFileSelected": "ファイルが選択されていません。",
      "startImported": "読み込んだリプレイを開始",
      "playPause": "再生 / 一時停止",
      "positionAria": "リプレイ位置",
      "handoffNote": "リプレイ完了 — 操作可能です。COMが相手をプレイします。",
      "exitReplay": "リプレイを終了",
      "phaseBarHint": "リプレイ {step} / {total} — 下のリプレイバーで記録された操作を進めてください。",
      "signInLibrary": "リプレイを保存・閲覧するにはサインインしてください。",
      "emptyLibrary": "保存済みリプレイはまだありません。対戦を終えるとRecentに自動保存されます。",
      "watch": "視聴",
      "downloadJson": "JSONをダウンロード",
      "loadingLibrary": "保存済みリプレイを読み込み中…",
      "loadLibraryFailed": "保存済みリプレイを読み込めませんでした。",
      "win": "勝利",
      "loss": "敗北",
      "replayLabel": "リプレイ",
      "resultAs": "{name}として{result}",
      "summarySaved": "保存 {date}",
      "summaryRoom": "ルーム {room}",
      "summaryVs": "対 {name}",
      "summaryTurn": "ターン {turn}",
      "summaryActions": "{count} アクション",
      "summaryActionsPlural": "{count} アクション",
      "metaSaver": "保存者: {name}",
      "metaPerspective": "視点: {id}",
      "metaSavedAt": "保存日時: {at}",
      "metaSnapshot": "スナップショット: ターン {turn}、フェイズ {phase}",
      "metaDuration": "長さ: {duration}",
      "metaActions": "アクション: {count}",
      "unknownDate": "日付不明",
      "loadedToast": "リプレイを読み込みました — {count} アクション",
      "downloadedJson": "リプレイJSONをダウンロードしました",
      "downloadFailed": "リプレイをダウンロードできませんでした",
      "payloadMissing": "リプレイデータがありません",
      "startSavedFailed": "保存済みリプレイを開始できませんでした",
      "unsupportedSchema": "未対応のリプレイ形式です",
      "invalidFile": "無効なリプレイファイルです",
      "chooseFileFirst": "先にリプレイファイルを選んでください。",
      "saveAfterFinish": "リプレイの保存は対戦終了後に利用できます。",
      "noCredentials": "リプレイ出力用の対戦情報が見つかりません。",
      "savedToLibrary": "リプレイをライブラリに永久保存しました",
      "savedToLibraryId": "リプレイを永久保存しました（#{id}）",
      "downloadedAsJson": "リプレイをJSONでダウンロードしました",
      "couldNotSave": "リプレイを保存できませんでした"
    },
    "apiError": {
      "titleClient": "問題が発生しました",
      "titleServer": "サーバーエラー",
      "hintClient": "ゲームが止まって見える場合は、ページを更新してください。",
      "hintServer": "ページを更新してください。続く場合は少し待ってから再試行してください。",
      "connectionFailed": "サーバーに接続できませんでした。ページを更新してください。"
    },
    "tutorialMeta": {
      "title": "初心者チュートリアル",
      "labelOpponent": "Player2"
    },
    "tutorial": {
      "speaker": "渋谷かのん",
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
    "spectate": {
      "listTitle": "観戦",
      "listTitleRanked": "ランクマッチを観戦",
      "listTitleCasual": "カジュアルマッチを観戦",
      "lead": "進行中の対戦を見る — 閲覧のみ、操作不可。",
      "barReadOnly": "観戦中 — 閲覧のみ",
      "barNames": "観戦中 {p1} vs {p2}",
      "leave": "退出",
      "reconnected": "観戦に再接続しました。",
      "watch": "観戦",
      "loading": "読み込み中…",
      "noMatches": "観戦できる対戦がありません。",
      "matchEnded": "対戦が終了しました — ロビーに戻ります。",
      "sessionEnded": "観戦セッションが終了しました。",
      "count": "観戦者: {n}",
      "switchPerspective": "視点を切り替え"
    },
    "ui": {
      "fullscreen": "全画面",
      "rankedSearch": "ランク検索",
      "casualSearch": "カジュアル検索",
      "skipToResults": "結果へスキップ",
      "clickToOpen": "クリックして開く"
    }

  ,
    "cardType": {
      "member": "メンバー",
      "live": "ライブ",
      "energy": "エネルギー"
    }},
  "es": {
    "logo": {
      "tagline": "El reproductor web no oficial"
    },
    "news": {
      "label": "Noticias",
      "title": "Noticias",
      "close": "Cerrar",
      "backToList": "← Todas las noticias",
      "empty": "Aún no hay noticias.",
      "untitled": "Sin título",
      "cardUnknown": "Carta no encontrada: {id}",
      "newBadge": "¡Nuevo!"
    },
    "auth": {
      "checking": "Verificando inicio de sesión con Discord…",
      "signingIn": "Iniciando sesión…",
      "signInDiscord": "Iniciar sesión con Discord",
      "guestPrompt": "Inicia sesión con Discord para guardar tu colección y jugar clasificatoria.",
      "guestPlayHint": "Juega no clasificada sin cuenta, o inicia sesión para clasificatoria.",
      "sessionExpired": "La sesión expiró: inicia sesión otra vez, o juega no clasificada sin cuenta.",
      "loadError": "No se pudo cargar la cuenta: aún puedes jugar no clasificada.",
      "guestTimeout": "La verificación de inicio de sesión tardó demasiado: juega no clasificada, o vuelve a intentar iniciar sesión con Discord."
    },
    "menu": {
      "unrankedPlay": "Juego no clasificado",
      "unrankedSub": "Salas, amistades o práctica contra CPU",
      "deckExperiment": "Experimento de mazo",
      "deckExperimentSub": "Construye con todas las cartas: solo invitados, no clasificada",
      "howToPlay": "Cómo jugar",
      "howToPlaySub": "Lección práctica para principiantes con Kanon"
    },
    "hub": {
      "signedIn": "Sesión iniciada",
      "signedInAs": "Sesión iniciada como",
      "signedInAsHtml": "Sesión iniciada como <b>{name}</b>",
      "dailyBoosters": "Sobres diarios: quedan {remaining} / {limit} hoy (JST)",
      "dailyWelcomeBonus": " (¡bono de bienvenida!)",
      "daily": "Sobres diarios: quedan {remaining} / {limit} hoy (JST)",
      "dailyBonus": " (¡bono de bienvenida!)",
      "rankLine": "ELO {elo} · {wins}V-{losses}D · {winPct}% victorias",
      "options": "Opciones",
      "signOut": "Cerrar sesión",
      "openBoosters": "Abrir sobres",
      "openBoostersSub": "Abre sobres de cartas",
      "deckBuilder": "Constructor de mazos",
      "deckBuilderSub": "Edita preajustes y mazo clasificatorio",
      "rankedPvp": "PvP clasificatorio",
      "rankedPvpSub": "Sube tu ELO en partidas emparejadas",
      "leaderboard": "Clasificación",
      "leaderboardSub": "Ver las clasificaciones en línea",
      "unranked": "Juego no clasificado",
      "unrankedSub": "Salas, amistades o práctica contra CPU",
      "tournamentMode": "Modo torneo",
      "tournamentModeSub": "Próximamente",
      "howToPlay": "Cómo jugar",
      "howToPlaySub": "Lección práctica para principiantes con Kanon",
      "backHub": "← Hub",
      "missions": "Misiones",
      "rankedPrCount": "{remaining} / {limit}",
      "rankedPrTitle": "Recompensas PR clasificatorias restantes hoy: {remaining} / {limit} (JST)"
    },
    "language": {
      "label": "Idioma",
      "en": "English",
      "ja": "日本語",
      "es": "Español"
    },
    "lobby": {
      "title": "Juego no clasificado",
      "yourName": "Tu nombre",
      "namePlaceholder": "Nombre de idol…",
      "deck": "Mazo",
      "createRoom": "Crear sala",
      "joinRoom": "Unirse a sala",
      "roomCode": "Código de sala",
      "vsPlayer": "VS jugador",
      "vsCpu": "VS CPU",
      "practiceCpu": "Práctica contra CPU",
      "cpuDifficulty": "Dificultad de CPU",
      "cpuEasy": "Fácil: mazo inicial aleatorio",
      "cpuNormal": "Normal: habilidades y Lives más inteligentes",
      "cpuHard": "Difícil: mazo fuerte y prioridad de habilidades",
      "cpuExpert": "Experto: búsqueda dry-run y proyección de amenaza",
      "cpuEasyShort": "Fácil",
      "cpuNormalShort": "Normal",
      "cpuHardShort": "Difícil",
      "cpuExpertShort": "Experto",
      "soloStarting": "Empezando vs CPU ({diff})",
      "findRandomMatch": "Buscar partida aleatoria",
      "spectate": "Espectar partida",
      "cancelSearch": "Cancelar búsqueda",
      "phaseTimer": "Temporizador de fase (principal y LIVE)",
      "phaseTimerSec": "Segundos por fase (10–120)",
      "backHub": "← Hub",
      "orJoinFriend": "o únete a una amistad",
      "orMatchRandomly": "o empareja al azar",
      "casualHint": "PvP casual: sin ELO ni registro clasificatorio",
      "experimentDeckPassword": "Contraseña de mazo experimental",
      "experimentPasswordPlaceholder": "Código de 8 letras",
      "experimentDeckBtn": "Experimento de mazo",
      "experimentDeckHint": "Construye en Experimento de mazo, genera una contraseña y luego introdúcela aquí, o elige un mazo guardado abajo.",
      "secondsLabel": "{n}s",
      "casualQueueStats": "{waiting} esperando · {inGame} en partidas casuales",
      "casualSearching": "Buscando oponente… ({seconds}s)",
      "roomCodePlaceholder": "ABCD1234"
    },
    "deck": {
      "title": "Constructor de mazos",
      "experimentTitle": "Experimento de mazo",
      "deckName": "Nombre del mazo",
      "presetSlot": "Espacio de preajuste (máx. 10)",
      "search": "Buscar cartas",
      "searchPlaceholder": "Nombre, ID o texto de reglas…",
      "collection": "Colección",
      "currentDeck": "Mazo actual",
      "savePreset": "Guardar preajuste",
      "equipRanked": "Equipar para clasificatoria",
      "autoBuild": "Construir automáticamente",
      "clear": "Limpiar",
      "hint": "La construcción automática optimiza desde tu colección · toca para agregar/quitar · pasa el cursor para previsualizar · el total de puntos debe ser 9 o menos",
      "hoverEmpty": "Pasa el cursor sobre una carta del mazo para previsualizarla aquí.",
      "backHub": "← Hub",
      "backMenu": "← Menú",
      "deckPassword": "Contraseña del mazo",
      "deckPasswordPlaceholder": "Introduce contraseña para cargar",
      "load": "Cargar",
      "savedPassword": "Contraseña guardada:",
      "copy": "Copiar",
      "cardPool": "Conjunto de cartas",
      "resetStarter": "Restablecer inicial",
      "useStarter": "Usar inicial",
      "randomDeck": "Mazo aleatorio",
      "updateSavedDeck": "Actualizar mazo guardado",
      "generatePassword": "Generar contraseña",
      "experimentHint": "Conjunto completo de cartas · construye un mazo legal para generar una contraseña · el total de puntos debe ser 9 o menos · mantén presionado o haz clic derecho en las cartas para ver detalles",
      "collectionOwned": "Cartas totales obtenidas · {count}",
      "collectionLoading": "Conjunto completo de cartas · cargando…",
      "collectionMatch": "Colección · {match} coincidencia",
      "deckStats": "Total {total}/72 · Miembros {members}/48 · Lives {lives}/12 · Energía {energy}/12 · Puntos {lovecaPoints}/{lovecaLimit}",
      "lovecaPointLabel": "Puntos",
      "lovecaPointBadge": "{n}pt",
      "lovecaOverLimit": "El total de puntos sería {total} (máx. {limit}).",
      "lovecaDeckIllegal": "El total de puntos es {total}; el mazo debe ser {limit} o menos.",
      "deckIllegalSize": "El mazo debe ser legal: 60 principal (48 Miembros, 12 Lives) y 12 Energía.",
      "lovecaExplain": "Algunas cartas fuertes cuestan puntos. El total de puntos de tu mazo principal debe mantenerse en 9 o menos (cada copia cuenta).",
      "deckEmpty": "Toca cartas de tu colección para construir un mazo.",
      "deckEmptyExperiment": "Toca cartas del conjunto para construir un mazo legal de 60+12.",
      "experimentStarterTitle": "Elegir mazo inicial",
      "experimentStarterLead": "Carga una lista inicial oficial como base; edítala libremente desde el conjunto completo de cartas.",
      "accountStarterHint": "Inicial de tu cuenta: {starter} · el preajuste n.º 1 empieza desde esta lista.",
      "noStarterOnAccount": "No hay mazo inicial en esta cuenta.",
      "loadedStarterIntoPreset": "Se cargó {name} en el preajuste n.º {slot}.",
      "loadedStarterFallbackName": "mazo inicial",
      "chooseStarterFirst": "Elige primero un mazo inicial.",
      "building": "Construyendo…",
      "autoBuiltSuccess": "Se construyó automáticamente un mazo legal desde tu colección.",
      "starterDecksNotLoaded": "Los mazos iniciales aún no se han cargado.",
      "equippedRanked": "Equipado para clasificatoria.",
      "filters": {
        "title": "Filtros",
        "showAdvanced": "Mostrar filtros avanzados",
        "hideAdvanced": "Ocultar filtros avanzados",
        "all": "Todo",
        "allTypes": "Todos los tipos",
        "allFields": "Todos los campos",
        "allProducts": "Todos los productos",
        "allSets": "Todos los sets",
        "any": "Cualquiera",
        "min": "Mín.",
        "max": "Máx.",
        "notIncluded": "No incluido",
        "heartAll": "Todo",
        "drawIcon": "Ícono de robar",
        "scoreIcon": "Ícono de puntuación",
        "label": {
          "type": "Tipo",
          "group": "Grupo",
          "rarity": "Rareza",
          "keywordIn": "Palabra clave en",
          "product": "Producto",
          "productSet": "Set de producto",
          "subunit": "Subunidad",
          "parallel": "Parallel",
          "printedHearts": "Corazones impresos",
          "requiredHearts": "Corazones requeridos",
          "bladeHearts": "Corazones de Blade",
          "blade": "Blade",
          "cost": "Costo",
          "score": "Puntuación"
        },
        "type": {
          "member": "Miembro",
          "live": "Live",
          "energy": "Energía"
        },
        "searchMode": {
          "all": "Todos los campos",
          "name": "Nombre",
          "text": "Texto",
          "id": "ID de carta"
        },
        "searchPlaceholder": {
          "all": "Nombre, ID o texto de reglas…",
          "name": "Nombre de carta…",
          "text": "Texto de reglas…",
          "id": "ID de carta, ej. PL!N-sd1-021-SD"
        },
        "productKind": {
          "bp": "Booster Pack",
          "pb": "Premium Booster",
          "pb_duo": "Premium Booster (DUO)",
          "sd": "Mazo inicial",
          "collection": "Colección",
          "pr": "PR"
        },
        "parallel": {
          "normal": "Solo normal",
          "parallel": "Solo Parallel"
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
          "aria": "Orden y clasificación de colección",
          "sortBy": "Ordenar por",
          "order": "Orden",
          "asc": "Ascendente",
          "desc": "Descendente",
          "id": "ID de carta",
          "rarity": "Rareza",
          "name": "Nombre (idol)",
          "type": "Tipo de carta",
          "group": "Grupo / escuela",
          "recent": "Obtenidas recientemente"
        }
      },
      "basicDecks": "Mazos básicos",
      "starters": {
        "nijigasaki": "Mazo inicial Nijigasaki",
        "muse": "Mazo inicial μ's",
        "liella": "Mazo inicial Liella!",
        "hasunosora": "Mazo inicial Hasunosora",
        "sunshine": "Mazo inicial Sunshine!!"
      }
    },
    "booster": {
      "title": "Abrir sobres",
      "openPack": "Abrir sobre ({n} cartas)",
      "openPaidBox": "Abrir 1 caja ({n} sobres)",
      "ratesLead": "{pool} cartas en el conjunto · probabilidad de aparecer en un sobre de {n} cartas",
      "packOpened": "Sobre abierto",
      "godPack": "¡GOD PACK!",
      "openAnother": "Abrir otro sobre",
      "openSameAgain": "Abrir el mismo otra vez",
      "packsLeft": "Quedan {n} sobre(s) hoy (JST)",
      "mainMenu": "Menú principal",
      "backHub": "← Hub",
      "noDailyPacks": "No quedan sobres diarios",
      "paidLead": "Gasta Star Gems para seguir abriendo sobres hoy.",
      "openOnePack": "Abrir 1 sobre",
      "starGemsLabel": "Star Gems:",
      "starGemsUnit": "{n} Star Gems",
      "dailyPack": "Sobre diario",
      "packRatesTitle": "Probabilidades del sobre",
      "packRatesPerPack": "Por sobre · toca una carta para ver detalles",
      "ratesLoading": "Cargando probabilidades…",
      "duplicatesConverted": "Duplicados convertidos",
      "migrationText": "Las copias extra de Miembro/Live más allá de 4 por carta y las copias de Energía más allá de 12 por carta se convirtieron en {gems}.",
      "convertedToGems": " · {n} convertidas a Star Gems",
      "needMoreGems": "Necesitas {n} Star Gems para abrir una caja",
      "noPacksOrGems": "No quedan sobres diarios ni Star Gems suficientes.",
      "openBox": "Abrir caja ({n} sobres)",
      "selectBoxFirst": "Elige un booster primero"
    },
    "ranked": {
      "title": "PvP clasificatorio",
      "findMatch": "Buscar partida",
      "cancelSearch": "Cancelar búsqueda",
      "spectate": "Espectar partida",
      "timerNote": "Las fases principal y LIVE usan un temporizador de 120 s.",
      "deckLabel": "Mazo clasificatorio",
      "matchSound": "Reproducir sonido cuando se encuentre partida",
      "leaderboard": "Clasificación",
      "leaderboardTitle": "Clasificación de clasificatoria",
      "backHub": "← Hub",
      "infoLine": "ELO {elo} · {record}",
      "record": "{wins}V-{losses}D · {winPct}% victorias",
      "recordFull": "{wins}V-{losses}D · {winPct}% victorias · {lossPct}% derrotas",
      "queueStats": "{waiting} esperando · {inGame} en partidas clasificatorias",
      "searching": "Buscando… ({seconds}s)",
      "readySearch": "Listo para buscar",
      "prRemaining": "Recompensas PR hoy: quedan {remaining} / {limit} (JST)"
    },
    "leaderboard": {
      "title": "Clasificación de clasificatoria",
      "lead": "El ELO más alto de PvP clasificatorio. Configura un banner y una bandera para tu fila de perfil.",
      "empty": "Aún no hay partidas clasificatorias: juega PvP clasificatorio para aparecer aquí.",
      "editBanner": "Editar perfil",
      "eloSuffix": " ELO",
      "eloLabel": "{elo} ELO",
      "profileBanner": "Banner de perfil",
      "bannerLead": "Elige una carta que tengas y luego arrastra la franja verticalmente para escoger el arte mostrado en tu carta de clasificación.",
      "bannerSearchPlaceholder": "Buscar por nombre de carta…",
      "bannerNoMatch": "Ninguna carta coincide con tu búsqueda.",
      "bannerPreview": "Vista previa",
      "saveBanner": "Guardar banner",
      "profileFlag": "Bandera de perfil",
      "flagLead": "Elige una bandera para mostrar junto a tu nombre en la clasificación.",
      "flagSearchPlaceholder": "Buscar banderas…",
      "equipFlag": "Equipar bandera",
      "flagNone": "Ninguna",
      "flagEquipped": "Equipada actualmente.",
      "flagReady": "¿Equipar {name}?",
      "flagLoading": "Cargando banderas…",
      "selectCardFirst": "Selecciona primero una carta",
      "jumpToYou": "Ir a mi fila",
      "yourRank": "Tu puesto: #{rank}"
    },
    "options": {
      "title": "Opciones",
      "enhancedTextures": "Texturas mejoradas en cartas de alta rareza",
      "soundEffects": "Efectos de sonido",
      "sfxVolume": "Volumen de SFX",
      "stuckTitle": "¿Atascado en una partida?",
      "stuckLead": "Si clasificatoria te reconecta a una partida rota o terminada, abandona aquí el registro de partida activa. Esto cuenta como rendición si la partida aún está en curso.",
      "resetTitle": "Restablecer cuenta",
      "resetLead": "Elimina todas las cartas de colección, preajustes de mazo, estadísticas clasificatorias y progreso de sobres. Elegirás un nuevo mazo inicial y empezarás de nuevo. Esto no se puede deshacer.",
      "resetAccount": "Restablecer cuenta",
      "backHub": "← Hub"
    },
    "starter": {
      "title": "Elige tu mazo inicial",
      "lead": "Elige un mazo inicial oficial como base de tu colección. Esta elección es permanente.",
      "confirm": "Confirmar inicial"
    },
    "waiting": {
      "roomCreated": "¡Sala creada!",
      "shareCode": "Comparte este código con tu oponente:",
      "tapCopy": "Toca para copiar 📋",
      "clickCopy": "Haz clic para copiar",
      "waitingOpponent": "Esperando a que se una el oponente…",
      "cancel": "Cancelar",
      "phaseTimerInfo": "Temporizador de fase: {sec}s por turno principal y LIVE"
    },
    "game": {
      "you": "Tú",
      "opponent": "Oponente",
      "opp": "Opo.",
      "gameLog": "Registro de partida",
      "resign": "🏳 Rendirse",
      "resignConfirm": "¿Rendirse?",
      "enableRadio": "📻 Activar radio",
      "endMainPhase": "Terminar Fase principal",
      "endLivePhase": "Terminar Fase Live",
      "setLiveCards": "Colocar cartas Live",
      "waitingOpponent": "Esperando al oponente",
      "resolveSkillFirst": "Resuelve primero la habilidad",
      "waitingSkill": "Esperando habilidad",
      "yourHand": "Tu mano",
      "mainDeck": "Mazo principal",
      "waitingRoom": "Sala de espera",
      "oppWaitingRoom": "Sala de espera del oponente",
      "deckHidden": "El mazo del oponente está oculto.",
      "energyDeck": "Mazo de Energía",
      "liveStorage": "Almacenamiento de Live",
      "successStorage": "Pila de éxito",
      "stageBoard": "Tablero de Escenario",
      "activatableSkills": "Habilidades activables",
      "activeEffects": "Efectos activos",
      "hoverHandEmpty": "Pasa el cursor sobre una carta en tu mano para previsualizarla aquí.",
      "starting": "Iniciando…",
      "hand": "Mano",
      "wr": "SE",
      "spectating": "Espectando: {p1} vs {p2} (solo lectura)",
      "oppActivatingSkill": "El oponente está activando una habilidad…",
      "activeEnergy": "activa",
      "pickSlot": "Elige un espacio",
      "batonPassHint": "Relevo: paga {cost} Energía activa",
      "overplayHint": "Sobreponer: paga {cost} Energía activa",
      "slotLeft": "Lado izquierdo",
      "slotCenter": "Centro",
      "slotRight": "Lado derecho",
      "baton": "Relevo",
      "batonToggleOn": "Toca para modo sobreponer",
      "batonToggleOff": "Toca para relevo",
      "opponentSkillWait": "{name} está activando una habilidad…",
      "perfYou": "Tú",
      "perfOpp": "Oponente",
      "sidebarInfo": "{turn}<span class=\"turn-sep\">·</span>Fase: {phase}<span class=\"turn-sep\">·</span>Activo: {active}<span class=\"turn-sep\">·</span>Primero: {first}",
      "deckTopLabel": "Parte superior del mazo",
      "hoverPickerEmpty": "Pasa el cursor sobre una carta para previsualizarla aquí.",
      "zoneEmpty": "Vacío"
    },
    "slot": {
      "left": "Izquierda",
      "center": "Centro",
      "right": "Derecha"
    },
    "phase": {
      "waiting": "Esperando",
      "setup": "Preparación (muligan)",
      "main": "Fase principal",
      "main_first": "Fase principal",
      "main_second": "Fase principal",
      "live": "Fase Live",
      "live_set": "Fase Live",
      "live_set_first": "Fase Live",
      "live_set_second": "Fase Live",
      "live_start_effects": "Inicio de Live",
      "live_success_effects": "Éxito de Live",
      "performance": "Fase de presentación",
      "live_performance_first": "Fase de presentación",
      "live_performance_second": "Fase de presentación",
      "coinFlip": "Lanzamiento de moneda",
      "preparation": "Preparación",
      "active": "Fase activa",
      "active_first": "Fase activa",
      "active_second": "Fase activa",
      "live_judge": "Verificación de victoria/derrota de Live"
    },
    "phaseId": {
      "waiting": "Esperando",
      "coin_flip": "Lanzamiento de moneda",
      "setup": "Preparación (muligan)",
      "active_first": "Fase activa",
      "active_second": "Fase activa",
      "main_first": "Fase principal",
      "main_second": "Fase principal",
      "live_set": "Fase Live",
      "live_set_first": "Fase Live",
      "live_set_second": "Fase Live",
      "live_start_effects": "Inicio de Live",
      "live_success_effects": "Éxito de Live",
      "live_performance_first": "Fase de presentación",
      "live_performance_second": "Fase de presentación",
      "live_judge": "Verificación de victoria/derrota de Live"
    },
    "phaseBar": {
      "spectating": "Espectando: {p1} vs {p2} (solo lectura)",
      "setupWaitMulligan": "Esperando a que el oponente termine el muligan…",
      "setupMulligan": "Preparación: revisa tu mano inicial, marca cartas para muligan y luego confirma.",
      "coinFlip": "Lanzamiento de moneda: el ganador elige quién va primero…",
      "mainYour": "Tu Fase principal: juega Miembros ({energy} disponible). Termina la Fase principal cuando estés listo.",
      "mainOpp": "Turno de {name}: Fase principal…",
      "mainOppS": "Turno de {name}: Fase principal…",
      "liveRaised": "Fase Live: {count} carta levantada · toca la mano para ajustar · confirma con el botón debajo del registro",
      "liveRaisedPlural": "Fase Live: {count} cartas levantadas · toca la mano para ajustar · confirma con el botón debajo del registro",
      "liveStored": "Fase Live: {stored} en almacenamiento · coloca hasta {slots} más (Live o Miembro), o termina la Fase Live debajo del registro",
      "livePlace": "Fase Live: coloca 0–{slots} cartas (Live o Miembro), luego termina la Fase Live · botón debajo del registro",
      "liveBothLocked": "Ambos jugadores bloquearon su selección: iniciando Presentación…",
      "liveYouLocked": "Bloqueaste tu selección: esperando a que el oponente termine la selección LIVE…",
      "liveStartEffects": "Resuelve los avisos de Inicio de Live: los efectos opcionales aparecerán como superposiciones.",
      "liveSuccessEffects": "Resuelve los avisos de Éxito de Live: los efectos opcionales aparecerán como superposiciones.",
      "performance": "Fase de presentación: Yell · corazones · verificación de éxito de Live",
      "liveJudge": "Fase de verificación de victoria/derrota de Live…"
    },
    "phaseBanner": {
      "coinFlipTitle": "Lanzamiento de moneda",
      "coinFlipSub": "El ganador elige quién va primero",
      "setupTitle": "Preparación",
      "setupSub": "Muligan opcional (un cambio)",
      "activeTitle": "Fase activa",
      "activeSub": "Refresca Energía y Miembros",
      "mainYour": "Tu Fase principal",
      "mainOpp": "Fase principal de {name}",
      "mainOppS": "Fase principal de {name}",
      "liveTitle": "Fase Live",
      "livePlayer": "Fase Live de {name}",
      "livePlayerS": "Fase Live de {name}",
      "liveSub": "Coloca 0–3 cartas (Live o Miembro), luego termina la Fase Live",
      "liveStartTitle": "Inicio de Live",
      "liveStartSub": "Efectos opcionales antes de la Presentación",
      "liveSuccessTitle": "Éxito de Live",
      "liveSuccessSub": "Efectos opcionales después de los corazones",
      "performanceTitle": "Fase de presentación",
      "performanceSub": "Revelar · Yell · Corazones",
      "liveJudgeTitle": "Verificación de victoria/derrota de Live",
      "liveJudgeSub": "Comparando puntuaciones de Live…",
      "yourMain": "Tu Fase principal",
      "theirMain": "Fase principal de {name}",
      "theirMainS": "Fase principal de {name}",
      "theirLive": "Fase Live de {name}",
      "theirLiveS": "Fase Live de {name}",
      "yourLive": "Tu Fase Live"
    },
    "splash": {
      "turn": "Turno {turn}",
      "turnBegin": "Comienza el turno {turn}",
      "noLives": "No se jugaron Lives este turno",
      "gameStart": "Inicio de partida",
      "deckRefresh": "Refresco de mazo",
      "deckRefreshOpp": "{name}: refresco de mazo",
      "deckRefreshSub": "{n} carta(s) barajadas desde la Sala de espera",
      "youAttemptLive": "¡Intentas un Live!",
      "theyAttemptLive": "{name} intenta un Live",
      "attemptSub": "Robando Yell · verificando corazones",
      "youWait": "Tú esperas",
      "theyWait": "{name} espera",
      "youWaitSub": "Las cartas Live permanecen en almacenamiento",
      "theyWaitSub": "Las cartas Live permanecen en su almacenamiento",
      "perfRoundFailed": "{ok} pasaron corazones · ronda fallida (todos los Lives deben tener éxito)",
      "perfCleared": "{ok} carta(s) Live superaron la ronda",
      "perfMixed": "{ok} tuvieron éxito · {fail} fallaron corazones → Sala de espera",
      "yourLivePerformance": "Tu Presentación Live",
      "theirLive": "Live de {name}",
      "perfSubYell": "Yell {blades} · {sub}",
      "successLiveYou": "¡Live exitoso!",
      "successLiveThey": "{name}: ¡Live exitoso!",
      "successLiveSubYou": "Una carta Live se une a tus éxitos",
      "successLiveSubThey": "Una carta Live se une a sus éxitos",
      "bothWait": "Ambos jugadores esperan",
      "bothWaitSub": "Las cartas Live permanecen en almacenamiento",
      "liveStartFlash": "INICIO DE LIVE",
      "liveJudgeTieCappedBoth": "Empate en puntuación Live — ninguno añade un Live exitoso (ambos ya tienen 2)",
      "liveJudgeTieYouCappedWin": "Empate — tienes el tope de 2 Lives exitosos",
      "liveJudgeTieOppEarns": "Empate — el oponente obtiene un Live exitoso",
      "liveJudgeTieYouEarns": "Empate — obtienes un Live exitoso",
      "liveJudgeTieOppCappedWin": "Empate — el oponente tiene el tope de 2 Lives exitosos",
      "liveJudgeTieBothSucceed": "Empate en puntuación Live — ¡ambos tienen éxito!",
      "liveJudgeYouWin": "¡Ganas por puntuación Live!",
      "liveJudgeOppWin": "El oponente gana por puntuación Live",
      "liveJudgeNamedWin": "{name} gana por puntuación Live"
    },
    "mulligan": {
      "title": "Mano inicial 🌸",
      "hint": "Toca cartas para marcarlas para reemplazo. Mantén presionada una carta para ver sus detalles. Toca otra vez para desmarcar.",
      "keepHand": "Conservar mano",
      "replaceCard": "Reemplazar {n} carta",
      "replaceCards": "Reemplazar {n} cartas",
      "tutorialKeepHint": "¿Te gusta esta mano? Toca Conservar mano para continuar.",
      "tutorialReplaceHint": "Toca la carta resaltada para marcarla y luego confirma el reemplazo."
    },
    "coin": {
      "title": "Primer jugador",
      "flipping": "Lanzando moneda…",
      "goFirst": "Iré primero",
      "escortFirst": "Escort va primero",
      "opponentFirst": "El oponente va primero",
      "waitingOppFlip": "Esperando a que el oponente termine de ver el lanzamiento…",
      "waitingOpp": "Esperando al oponente…",
      "wonFlip": "¡{name} ganó el lanzamiento de moneda!",
      "wonFlipShort": "{name} ganó el lanzamiento de moneda",
      "winnerChoosing": "Eligiendo quién va primero…",
      "chooseFirst": "Elige quién va primero",
      "youWon": "¡Ganaste el lanzamiento de moneda!",
      "oppGoesFirst": "{name} va primero"
    },
    "live": {
      "overlayTitle": "Fase Live: colocar cartas",
      "overlayHint": "Fase Live: coloca 0–3 cartas (Live o Miembro) en el almacenamiento de Live; las tuyas quedan boca arriba, las del oponente quedan ocultas hasta Presentación. Roba 1 por cada carta colocada y luego termina la Fase Live. Presentación revela el almacenamiento del oponente de una vez.",
      "placeInStorage": "Colocar en almacenamiento",
      "selected": "Seleccionada",
      "inStorage": "En almacenamiento",
      "liveScore": "Puntuación de Live",
      "combinedHearts": "Corazones requeridos totales",
      "livesSelected": "{n} Lives",
      "livesSelectedOne": "1 Live",
      "plusMembers": "+{n} Miembros",
      "plusMembersOne": "+1 Miembro",
      "liveJudge": "Juez de Live",
      "liveWinLoss": "Verificación de victoria/derrota de Live",
      "yourScore": "Tu puntuación",
      "oppScore": "Puntuación del opo."
    },
    "prompt": {
      "confirm": "Confirmar",
      "cancel": "Cancelar",
      "respond": "Responder",
      "chooseCards": "Elegir cartas",
      "chooseFromHand": "Elegir de la mano",
      "chooseHeart": "Elegir un corazón",
      "discardFromHand": "Descartar de la mano",
      "discardOne": "Elige una carta para enviar a la Sala de espera.",
      "discardMany": "Elige {count} cartas para enviar a la Sala de espera.",
      "selectThenConfirm": "Selecciona cartas y luego toca Confirmar.",
      "tapCardConfirm": "Toca una carta para confirmar.",
      "yes": "Sí",
      "noSkip": "No: saltar",
      "skip": "Saltar",
      "tapOption": "Toca una opción abajo.",
      "useLiveStart": "¿Usar este efecto de Inicio de Live?",
      "useEffect": "¿Usar este efecto?",
      "answer": "Respuesta",
      "typeAnswer": "Escribe tu respuesta…",
      "typeAnswerHint": "Escribe tu respuesta; la ortografía y la redacción pueden variar.",
      "confirmArrangement": "Confirmar arreglo",
      "selectedCount": "Seleccionadas: {n}/{max}",
      "activateSub": "Elige si activar este efecto.",
      "lookAtDeck": "Mirar el mazo",
      "surveilHint": "La posición 1 es la parte superior de tu mazo. Arrastra cartas entre las posiciones numeradas y la Sala de espera, toca dos cartas para intercambiarlas, o toca una posición / la Sala de espera mientras una carta está seleccionada.",
      "surveilHintReturnAll": "La posición 1 es la parte superior de tu mazo. Arrastra cartas entre las posiciones numeradas, o toca dos cartas para intercambiarlas. Todas las cartas deben permanecer encima del mazo.",
      "wrPickTitle": "Sala de espera",
      "wrPickMsg": "Elige una carta de tu Sala de espera para añadirla a tu mano.",
      "yellPickTitle": "Yell",
      "yellPickMsg": "Elige 1 carta revelada por Yell.",
      "successLivePickTitle": "Live exitoso",
      "successLivePickMsg": "Elige 1 carta Live para colocar en Live exitoso.",
      "successLiveHandTitle": "Live exitoso",
      "successLiveHandMsg": "Elige 1 carta de tu área de Live exitoso para añadirla a tu mano.",
      "deckTopTitle": "Parte superior del mazo",
      "deckTopMsg": "Elige 1 carta revelada para Yell y colócala en la parte superior de tu mazo.",
      "wrEmpty": "La Sala de espera está vacía",
      "wrNoMatch": "No hay cartas coincidentes en la Sala de espera",
      "yellNoCards": "No hay cartas Yell para elegir",
      "noLiveSuccess": "No hay cartas Live para colocar en Live exitoso",
      "searchDeckFor": "Buscar en el mazo…",
      "deckTopPick": "Parte superior del mazo"
    },
    "skill": {
      "alreadyUsed": "Ya usado este turno",
      "needEnergy": "Necesitas {n} Energía activa",
      "tutorialDemo": "Demo del tutorial: usa Siguiente para continuar"
    },
    "skillKw": {
      "onEnter": {
        "title": "Al entrar",
        "body": "Se activa una vez cuando este Miembro se juega desde tu mano a tu Escenario."
      },
      "onLeave": {
        "title": "Al salir",
        "body": "Se activa cuando este Miembro deja tu Escenario (enviado a la Sala de espera, relevo, etc.)."
      },
      "liveStart": {
        "title": "Inicio de Live",
        "body": "Se resuelve durante el paso de Inicio de Live después de que se intenta un Live. Muchos efectos son opcionales: busca \"puedes\"."
      },
      "liveSuccess": {
        "title": "Éxito de Live",
        "body": "Se resuelve cuando tu Presentación Live tiene éxito: se cumplieron los corazones requeridos para las cartas Live intentadas."
      },
      "activated": {
        "title": "Activada",
        "body": "Eliges usarla durante tu Fase principal mientras el Miembro está activo en el Escenario. Paga primero cualquier costo indicado."
      },
      "always": {
        "title": "Siempre",
        "body": "Efecto pasivo que permanece mientras este Miembro está en juego y se cumplen sus condiciones. No hay nada que activar."
      },
      "oncePerTurn": {
        "title": "Una vez por turno",
        "body": "Solo puedes usar este efecto una vez en cada turno."
      },
      "automatic": {
        "title": "Automático",
        "body": "Se dispara por sí mismo cuando ocurre la condición indicada; no requiere activación."
      },
      "center": {
        "title": "Centro",
        "body": "Solo aplica si este Miembro está en el espacio central del Escenario cuando el efecto se resuelve."
      },
      "yell": {
        "title": "Yell (エール)",
        "body": "Durante la Presentación Live, roba cartas de tu mazo igual a tu Blade total (de los Miembros activos en el Escenario). Esas cartas se revelan: los corazones que muestren cuentan para cumplir los corazones requeridos de tus cartas Live. Las cartas Yell se envían después a la Sala de espera."
      },
      "wait": {
        "body": "Un Miembro puesto en Wait no aporta su Blade ese turno: su Blade no aumenta las cartas reveladas para Yell en la Presentación Live. No es la Sala de espera.",
        "title": "Wait (ウェイト)"
      }
    },
    "heart": {
      "pickColor": "Elige un color de corazón para este efecto.",
      "yellow": "Amarillo",
      "pink": "Rosa",
      "purple": "Morado",
      "red": "Rojo",
      "green": "Verde",
      "blue": "Azul"
    },
    "card": {
      "cost": "Costo",
      "blade": "Blade",
      "score": "Puntuación",
      "requiredHearts": "Corazones requeridos",
      "hearts": "Corazones",
      "yellIcons": "Íconos de Yell",
      "playToSlot": "Jugar en espacio:",
      "needEnergy": "Necesitas",
      "haveEnergy": "tienes",
      "bladeHearts": "Corazones de Blade"
    },
    "pack": {
      "opened": "Sobre abierto",
      "boxOpened": "Caja abierta"
    },
    "log": {
      "gameStartedCoinFlip": "¡La partida comenzó! Lanzamiento de moneda: el ganador elige quién va primero.",
      "preparationDrawEnergy": "Preparación: cada jugador robó 6 cartas y colocó 3 Energía en almacenamiento.",
      "preparationMulligan": "Preparación: muligan: puedes reemplazar cualquier cantidad de cartas de tu mano inicial una vez.",
      "livePhaseIntro": "Fase Live: coloca 0–3 cartas (Live o Miembro) boca abajo en el almacenamiento de Live (roba 1 por cada carta colocada), luego termina la Fase Live.",
      "bothRevealLive": "Ambos jugadores revelan el almacenamiento de Live simultáneamente.",
      "noLivesThisTurn": "No se jugaron Lives este turno.",
      "remainingLiveToWr": "El almacenamiento de Live restante se envió a la Sala de espera.",
      "neitherWrFromHand": "Ningún jugador tenía cartas en mano para poner en la Sala de espera.",
      "neitherCouldDraw": "Ningún jugador pudo robar (mazo vacío).",
      "neitherLiveWinner": "Ningún jugador tiene éxito: no hay ganador de Live este turno.",
      "coinFlipAuto": "Lanzamiento de moneda: continuó automáticamente (el jugador no respondió a tiempo).",
      "cpuDeck": "Mazo de CPU: {label}",
      "dividerLive": "=== Fase Live ===",
      "dividerPerformance": "=== Fase de presentación ===",
      "dividerLiveShow": "=== Show Live ===",
      "dividerLiveJudge": "=== Fase de verificación de victoria/derrota de Live ===",
      "dividerTurnBegin": "=== Comienza el turno {turn} ===",
      "dividerTurn": "--- Turno {turn} ---",
      "hasNoValidLive": " no tiene cartas Live válidas.",
      "disconnectedWin": "{loser} se desconectó. ¡{winner} gana!",
      "chooseSuccessLive": " — elige una carta Live para Live exitoso.",
      "scoreTiedBlocked": " — puntuación empatada; Live exitoso bloqueado; cartas Live enviadas a la Sala de espera.",
      "scoreTiedCap": " — puntuación empatada, pero ya tiene 2 Lives exitosos; cartas Live enviadas a la Sala de espera."
    },
    "win": {
      "youWin": "¡Ganaste!",
      "youLose": "¡Perdiste!",
      "playAgain": "Jugar otra vez",
      "returnMenu": "Volver al menú",
      "viewLeaderboard": "Ver clasificación",
      "resigned": "Te rendiste",
      "conceded": "Concediste la partida.",
      "oppResigned": "{name} se rindió.",
      "threeLives": "¡{name} logró 3 Lives exitosos!",
      "findAnother": "Buscar otra partida",
      "disconnectedYou": "Te desconectaste de la partida.",
      "disconnectedOpp": "{name} se desconectó.",
      "statsLine": "Turno: {turn} | Tus éxitos: {yours}/3 | Éxitos del opo.: {opp}/3",
      "debugSaveReplay": "💾 Guardar repetición",
      "saveReplay": "Guardar repetición",
      "saveReplayToLibrary": "Guardar repetición en la biblioteca",
      "downloadReplayJson": "Descargar JSON de repetición",
      "debugSaveLog": "💾 Guardar registro de depuración",
      "debugCopyLog": "📋 Copiar registro",
      "debugSaveBundle": "📦 Exportar paquete de depuración",
      "rankedPrDailyCap": "Recompensas PR clasificatorias del día usadas ({limit}/día JST)",
      "rankedPrDupe": "{name} convertida a {gems} Star Gems (límite de copias)",
      "rankedPrNew": "Recompensa por victoria clasificatoria: {name}",
      "rankedPrPopupTitle": "¡Recompensa por victoria clasificatoria!",
      "rematchAccept": "Aceptar revancha",
      "rematchOffer": "Revancha",
      "rematchOppWants": "¡{name} quiere una revancha!",
      "rematchWaiting": "Esperando…",
      "rematchWaitingHint": "Esperando a que tu oponente acepte la revancha.",
      "spectatorStatsLine": "Turno: {turn} | {p1}: {p1Lives}/3 | {p2}: {p2Lives}/3",
      "spectatorWinner": "¡{name} gana!"
    },
    "replay": {
      "menuTitle": "Visor de repeticiones",
      "menuSubAuth": "Carga una repetición guardada y mírala en tiempo real",
      "menuSubHub": "Mira repeticiones guardadas desde tu biblioteca",
      "title": "Visor de repeticiones",
      "back": "← Atrás",
      "lead": "Mira repeticiones guardadas desde la biblioteca de tu cuenta. La reproducción sigue el ritmo de acciones guardado, y la barra de búsqueda puede saltar a cualquier punto de la repetición.",
      "refreshLibrary": "Actualizar biblioteca",
      "importLead": "¿Tienes un archivo de repetición exportado? Importa JSON aquí como opción secundaria.",
      "fileLabel": "Archivo de repetición",
      "noFileSelected": "No se seleccionó ningún archivo.",
      "startImported": "Iniciar repetición importada",
      "playPause": "Reproducir / pausar",
      "positionAria": "Posición de la repetición",
      "handoffNote": "Repetición completa: tienes el control. La CPU juega como oponente.",
      "exitReplay": "Salir de repetición",
      "phaseBarHint": "Repetición {step} / {total}: usa la barra de repetición abajo para avanzar por las acciones grabadas.",
      "signInLibrary": "Inicia sesión para guardar y ver repeticiones en tu biblioteca.",
      "emptyLibrary": "Aún no hay repeticiones guardadas. Termina una partida y elige Guardar repetición.",
      "watch": "Ver",
      "downloadJson": "Descargar JSON",
      "loadingLibrary": "Cargando repeticiones guardadas...",
      "loadLibraryFailed": "No se pudieron cargar las repeticiones guardadas.",
      "win": "Victoria",
      "loss": "Derrota",
      "replayLabel": "Repetición",
      "resultAs": "{result} como {name}",
      "summarySaved": "Guardada {date}",
      "summaryRoom": "Sala {room}",
      "summaryVs": "vs {name}",
      "summaryTurn": "Turno {turn}",
      "summaryActions": "{count} acción",
      "summaryActionsPlural": "{count} acciones",
      "metaSaver": "Guardó: {name}",
      "metaPerspective": "Perspectiva: {id}",
      "metaSavedAt": "Guardada: {at}",
      "metaSnapshot": "Instantánea: turno {turn}, fase {phase}",
      "metaDuration": "Duración: {duration}",
      "metaActions": "Acciones: {count}",
      "unknownDate": "Fecha desconocida",
      "loadedToast": "Repetición cargada: {count} acción(es)",
      "downloadedJson": "JSON de repetición descargado",
      "downloadFailed": "No se pudo descargar la repetición",
      "payloadMissing": "Falta el contenido de la repetición",
      "startSavedFailed": "No se pudo iniciar la repetición guardada",
      "unsupportedSchema": "Esquema de repetición no compatible",
      "invalidFile": "Archivo de repetición inválido",
      "chooseFileFirst": "Elige primero un archivo de repetición.",
      "saveAfterFinish": "Guardar repetición está disponible después de que termine la partida.",
      "noCredentials": "No se encontraron credenciales de partida para exportar la repetición.",
      "savedToLibrary": "Repetición guardada en tu biblioteca",
      "savedToLibraryId": "Repetición guardada en tu biblioteca (#{id})",
      "downloadedAsJson": "Repetición descargada como JSON",
      "couldNotSave": "No se pudo guardar la repetición",
      "autosavedRecent": "Repetición autoguardada en Recientes",
      "emptyRecent": "Aún no hay autoguardados. Termina una partida con sesión iniciada para llenar esta lista.",
      "emptySaved": "Aún no hay guardados permanentes. Usa Guardar repetición en resultados, o Guardar para siempre en Recientes.",
      "preserve": "Guardar para siempre",
      "preservedToast": "Repetición movida a Guardadas para siempre",
      "recentHint": "Autoguardadas de tus últimas 10 partidas. La más antigua se reemplaza sola.",
      "recentSection": "Partidas recientes",
      "savedHint": "Se mantienen al guardar en la pantalla de resultados, o al preservar una de Recientes.",
      "savedSection": "Guardadas para siempre"
    },
    "apiError": {
      "titleClient": "Algo salió mal",
      "titleServer": "Error del servidor",
      "hintClient": "Intenta actualizar la página si la partida parece atascada.",
      "hintServer": "Intenta actualizar la página. Si sigue ocurriendo, espera un momento e inténtalo de nuevo.",
      "connectionFailed": "No se pudo conectar con el servidor. Intenta actualizar la página."
    },
    "tutorialMeta": {
      "title": "Tutorial para principiantes",
      "labelOpponent": "Jugador2"
    },
    "tutorial": {
      "exitTitle": "Salir al título",
      "back": "← Atrás",
      "next": "Siguiente →",
      "finish": "Finalizar",
      "intro_welcome": "¡Hola! Soy **Shibuya Kanon**. ¡Bienvenido al tutorial de **Love Live! Official Card Game**!",
      "intro_what": "¡Este es un juego de cartas para **dos jugadores** sobre **idols escolares**! Reclutarás **Miembros** en tu Escenario, administrarás **Energía** y presentarás **Lives** para superar a tu oponente.",
      "intro_goal": "**Condición de victoria:** Presenta con éxito **3 Lives** antes que tu oponente. Cuando tu **Live** tiene éxito, ese Live se mueve al **almacenamiento de cartas Live exitosas**: ¡el primero en llegar a tres gana la partida!",
      "intro_decks": "Este juego usa tres tipos de cartas. Cartas de **Miembro**, cartas **Live** y cartas de **Energía**. Cada jugador tiene un **Mazo principal** de **60** cartas (**48** cartas de Miembro y **12** cartas Live) y un **Mazo de Energía** de **12** cartas de Energía.",
      "intro_card_member": "Las **cartas de Miembro** son las idols que se presentarán en el Escenario. Paga **Energía** igual a su costo para jugarlas desde tu mano. Cada Miembro tiene cierta cantidad de **Corazones** de color (verticales) que se usan al presentar Lives. También hay **Blades** (los íconos redondos de penlight) y **Corazones de Blade** (los corazones de lado), pero por ahora nos enfocaremos en el corazón vertical. Shiki aquí tiene **1 corazón morado**.",
      "intro_card_live": "Las **cartas Live** son las canciones que presentas. Puedes jugar hasta 3 a la vez. Los Lives se superan usando las cartas de **Miembro** que colocaste en tu Escenario; hablaremos más de esto después.",
      "intro_card_energy": "Las **cartas de Energía** de tu **Mazo de Energía** se colocan aquí. Empiezas con **3 Energía** y ganas **+1** cada turno (hasta que las **12** de tu energía estén en juego). La Energía se gasta para colocar **cartas de Miembro** en tu **Escenario**.",
      "intro_demo": "Te guiaré por una demo: **Liella!** vs **μ's** sobre el tapete. Tú estás abajo; tu oponente está arriba.",
      "intro_deck_piles": "La pila superior es tu **Mazo principal**, de donde robas cartas. Debajo está el **Mazo de Energía**.",
      "intro_stage": "El **Escenario** (izquierda / centro / derecha) es donde se sientan los Miembros. Sus colores de **Corazón** y valores de **Blade** alimentan los Lives durante la Presentación.",
      "intro_live": "El **Almacenamiento de Live** guarda hasta 3 cartas boca abajo durante la Fase Live. Podrás ver tus propias cartas en esta versión web, pero las cartas de tu oponente estarán ocultas.",
      "intro_success": "¡Completar un **Live** mueve esa carta Live a la **Pila de éxito**! ¡Lleva la cuenta de qué tan cerca estás de ganar aquí!",
      "intro_wr": "La **Sala de espera** es la pila de descarte.",
      "intro_hands": "Normalmente la mano de tu oponente estará oculta, pero es visible en este tutorial. Tu mano consiste en cartas de **Miembro** y **Live** de tu mazo.",
      "setup_coin": "Antes de empezar, un **lanzamiento de moneda** elige un ganador: esa persona **elige** quién va primero. ¡Pon atención a esto al inicio de cada partida!",
      "setup_coin_p1": "...¡**Liella** va primero!",
      "setup_coin_p2": "¡Ahora podemos ver nuestra **mano inicial**!",
      "setup_mulligan": "Empiezas con **6** cartas. Si no estás conforme con las cartas que robaste, esta pantalla te da la oportunidad de cambiar tantas cartas como quieras y robar reemplazos (a esto lo llamamos **muligan**).",
      "setup_mull_p1": "Flujo de juego: **Fase principal** -> **Fase Live** -> **Fase de presentación** -> repetir.",
      "setup_mull_p2": "¡**Fase principal!**. Robaste una carta nueva de tu mazo.",
      "t1_structure": "En cada **Fase principal**, actúa primero el primer jugador y luego el segundo; ahí es donde juegas Miembros... y usas habilidades. Presionarás **Terminar Fase principal** aquí cuando termines de realizar acciones.",
      "t1_energy_refresh": "Al inicio de un nuevo turno, ganarás **+1 Energía**. Seguirás ganando **1** Energía con cada nuevo turno hasta que las **12** cartas de Energía estén en juego.",
      "t1_main_p1": "**Fase principal** de Liella: ¡juguemos primero una carta de Miembro!",
      "t1_play_shiki_plain": "Gastamos **2 Energía** para jugar esta carta y enviarla a un espacio libre de nuestro Escenario. (La Energía gastada se gira de lado)",
      "t1_no_skill": "Ahora tenemos un solo Miembro en el centro de nuestro Escenario. Si no tienes la Energía requerida para colocar más cartas, puedes terminar tu Fase principal.",
      "t1_end_main_p1": "Liella termina su Fase principal: ¡ahora es el turno del oponente de colocar sus cartas!",
      "t1_main_p2": "μ's juega **Rin Hoshizora** en su Escenario, con **1 corazón rosa**.",
      "t1_hearts": "¡Puedes ver aquí arriba la cantidad total de **Corazones** y **Blades** de las cartas activas en tu **Escenario** y el de tu oponente!",
      "t1_end_main_p2": "Después de que ambos jugadores completan su Fase principal, ¡es hora de la **Fase Live**!",
      "t1_live_intro": "Coloca 0–3 cartas (Live o Miembro) en el **almacenamiento de cartas Live**. Roba 1 carta nueva de tu mazo por cada carta que colocaste. Las cartas de Miembro colocadas en el almacenamiento de Live se descartarán en la siguiente fase; ¡puedes reemplazar cartas no deseadas de esta forma!",
      "t1_live_p1": "Cuando colocas una carta **Live** en la Fase Live, deberá intentarse más tarde en el mismo turno, ¡así que elige con cuidado! Liella coloca **WE WILL!!**: necesita 1 corazón **rojo**, 1 corazón **morado** y 1 corazón adicional de **cualquier color** (indicado por un corazón gris) para superarse con éxito.",
      "t1_live_p1_lock": "Liella termina su **Fase Live**, bloqueando su selección. A diferencia de la Fase principal, tu Fase Live y la de tu oponente ocurren al mismo tiempo. Si la Fase Live de tu oponente aún no termina, esperarás a que acabe antes de avanzar.",
      "t1_live_p2": "μ's coloca un **Live** boca abajo en almacenamiento: verás cuál es en la **Fase de presentación**.",
      "t1_live_p2_lock": "μ's bloquea su selección.",
      "t1_perf_intro": "Ambos jugadores terminan la Fase Live — ¡comienza la **Presentación**! Si alguno colocó Lives, verás la pantalla **Inicio de Live**. ¡Aquí se decide si los Lives tienen éxito o no!",
      "t1_hearts_check": "Aquí puedes comprobar si las cartas del Escenario cumplen los Corazones requeridos de este Live. **Shiki Wakana** solo aporta **1 Corazón morado**, así que aún faltan **1 Corazón rojo** y **1 Corazón de cualquier color**.",
      "t1_hearts_grey": "Los Corazones **grises / cualquier color** cuentan como **cualquier color** — con rojo y morado cubiertos, los Corazones restantes pueden llenar el espacio \"cualquier\" de **WE WILL!!**.",
      "t1_yell": "Aunque un Live parezca perdido, aún no termina… aquí entra **Yell**. Usa el valor **Blade** de las cartas — Liella! hace \"Yell\" y roba cartas extra del mazo según el total de **Blade** en el Escenario. **Shiki Wakana** tiene **Blade 2**, ¡así que roba 2 cartas del mazo!",
      "t1_yell_hearts": "¡Las cartas de Natsumi sumaron 2! Las cartas de Yell añaden Corazones de lado (**Blade Corazones**) al total. Dos **Corazones rojos** cubren **rojo 1** y **cualquier 1**. **¡El Live tiene éxito!**",
      "t1_success": "Piensa en **Yell** como el grito de apoyo del público — es clave para cumplir Corazones difíciles. Ten en cuenta los valores **Blade** del Escenario — ¡el Live **WE WILL!!** **tuvo éxito**!",
      "t1_yell_opp": "Siguiente **Yell** de μ's — **Blade 1** en el Escenario voltea **1** carta. **Nico** no tiene Blade Corazones — **Kitto Seishun ga Kikoeru** aún no puede tener éxito.",
      "t1_fail": "μ's no pudo cumplir el costo de Corazones de **Kitto Seishun ga Kikoeru** — ese **Live** va a la **Sala de espera**.",
      "t1_judge": "¡Liella! gana por puntuación y obtiene un **Live exitoso**!",
      "t1_end": "Turno 1 listo: jugaste un Miembro, colocaste un Live y aprendiste **emparejamiento de Corazones** durante la Presentación.",
      "t2_start": "**Turno 2**: robas una carta a tu mano y ganas +1 Energía.",
      "t2_skill_intro": "Las cartas que hemos jugado hasta ahora solo dan **corazones** y **blades**, pero algunas cartas también tienen **habilidades** que afectan la partida de varias formas. Mira esta carta: incluye texto de habilidad.",
      "t2_skill_preview": "Esta es una habilidad **[Al entrar]**, lo que significa que cuando esta carta entra al Escenario desde tu mano, ocurre algo.",
      "t2_play_shiki_skill": "Liella juega a Shiki en el espacio derecho. Mira: el juego preguntará si quieres usar su efecto **Al entrar**.",
      "t2_on_enter_offer": "Si una habilidad dice \"puedes\", significa que activarla es opcional y puedes elegir saltar el efecto. Liella acepta **activar** la habilidad. Después de pagar **1 Energía**, Liella puede elegir una nueva carta de la parte superior de su mazo para añadirla a su mano.",
      "t2_on_enter_confirm": "Ahora eligen qué carta conservar. Liella elegirá **1 carta** para conservar y enviará las demás a la **Sala de espera**.",
      "t2_on_enter_result": "La habilidad de Shiki se resuelve: una carta se une a la mano de Liella, dos van a la **Sala de espera**. Así funciona una habilidad **[Al entrar]**.",
      "t2_end_p1": "Liella termina principal.",
      "t2_main_p2": "μ's juega un Miembro asequible para añadir Corazones.",
      "t2_end_p2": "μ's termina principal.",
      "t2_live_skill_intro": "¡Las cartas Live también pueden tener habilidades! Algunas tienen **[Inicio de Live]**: eso se activa cuando empieza la Presentación de ese Live.",
      "t2_live_p1": "Liella coloca una carta Live.",
      "t2_live_p1_lock": "Bloqueado.",
      "t2_live_p2": "μ's coloca **START:DASH!!** boca abajo.",
      "t2_live_p2_lock": "μ's bloquea su selección.",
      "t2_perf_intro": "Ambos confirmaron — ¡otra **Presentación**! Los Lives se revelan de nuevo.",
      "t2_live_start_offer": "**[Inicio de Live]** — **START:DASH!!** permite a μ's **mirar las 3 cartas superiores** de su mazo y reordenarlas antes de continuar la Presentación. Este aviso demuestra el efecto **[Inicio de Live]**.",
      "t2_yell_mine": "Primero tu **Yell** — **Blade 3** en el Escenario voltea **3** cartas. Aparecieron **Ren**, **Keke** y **Mei**, pero ninguna tiene **Blade Corazones** — **Watashi no Symphony ~Shibuya Kanon Ver.~** necesita **rojo 4**, **morado 4** y **cualquier 3**, así que ese **Live** **falla**.",
      "t2_yell_opp": "Siguiente **Yell** de μ's — la primera carta es **Korekara no Someday** con **Blade Corazones** de TODOS los colores (cuentan como cualquier color y cubren lo que falta de **START:DASH!!** — aquí **amarillo**). La segunda, **Rin**, no tiene Blade Corazones.",
      "t2_outcomes": "Ambos Lives se resolvieron — los exitosos quedan en la pila de éxito y los fallidos van a la **Sala de espera**.",
      "t2_judge": "Solo **μ's** logró **START:DASH!!** con éxito — tu Live falló, así que **Watashi no Symphony ~Shibuya Kanon Ver.~** va a la **Sala de espera**. ¡μ's obtiene un **Live exitoso**!",
      "t3_start": "**Turno 3**: μ's va primero este turno porque superó el único Live exitoso en la última Presentación.",
      "t3_main_p2": "μ's juega un Miembro asequible para añadir Corazones.",
      "t3_p2_end": "μ's termina principal.",
      "t3_turn": "**Turno 3**: tu Fase principal. Robaste una carta y ganaste **+1 Energía** (**6** en almacenamiento).",
      "t3_baton_intro": "Ahora explicaré otra mecánica llamada **relevo**. Al jugar una carta sobre otra carta que ya está en tu Escenario, puedes **intercambiar** la carta vieja por la nueva. Al hacer relevo, se considera que pagaste el costo del Miembro reemplazado al moverlo a la **Sala de espera**; solo pagarás la **diferencia** en Energía.",
      "t3_baton_example": "**Mei Yoneme** cuesta **7**: normalmente serían **7 Energía** desde la mano, pero hacer relevo sobre **Shiki** (costo **4**) en **Derecha** cuesta solo **3** (7−4). **Shiki** permanece en **Centro** (**2 Blade**), **Mei** en **Derecha** añade **1 rojo** y **2 morado**; colocarás **Mirai wa Kaze no You ni** desde la **mano** en la **Fase Live**, y al Escenario le falta un Corazón para superarlo hasta el **Yell**.",
      "t3_baton_play": "¡Liella hace **relevo** con Mei en **Derecha**!",
      "skill_glossary_intro": "Ya viste en vivo varios momentos de habilidades. Estas son palabras clave comunes que verás en las cartas:",
      "skill_on_enter": "**[Al entrar]**: se dispara una vez cuando el Miembro se juega desde la mano a tu Escenario (como Shiki recién). Muchas dicen *puedes*: son opcionales.",
      "skill_live_start": "**[Inicio de Live]**: se dispara cuando comienza una Presentación Live con esa carta (como START:DASH). También suele ser opcional.",
      "skill_activated": "**[Activada]**: durante tu Fase principal, usa los botones bajo **Habilidades activables**. Algunos Miembros como **Kinako** pueden dejar el Escenario para añadir un **Live** de tu **Sala de espera** a tu mano.",
      "skill_wr_note": "Algunas habilidades **[Activada]** solo funcionan mientras el Miembro está **en la Sala de espera**; la lista muestra **SE ·** antes de su nombre. Las habilidades de Escenario muestran el espacio en su lugar.",
      "skill_always": "**[Siempre]** / **[Automático]**: permanece activa mientras se cumplen las condiciones; no hay botón que presionar. **Automático** se dispara por sí mismo cuando ocurre algo.",
      "skill_once": "**[Una vez por turno]**: aunque pudieras pagar el costo otra vez, solo tienes un uso en cada turno.",
      "skill_center": "**[Centro]**: solo funciona si ese Miembro está en el espacio **central** del Escenario.",
      "skill_on_leave": "**[Al salir]**: se dispara cuando el Miembro deja el Escenario (relevo, efectos de retiro, etc.).",
      "t3_stage_hearts": "Con **Izquierda** libre, coloca **Mirai wa Kaze no You ni** desde la **mano** en la **Fase Live**. **Shiki** en **Centro** y **Mei** en **Derecha** aportan algunos Corazones; **Mirai wa Kaze no You ni** todavía necesita más Corazones, que con suerte serán proporcionados por **Yell**. La habilidad de **Mirai wa Kaze no You ni** permite que los corazones de **Yell** cuenten como **cualquier** color, así que mejora nuestras posibilidades.",
      "t3_end_p1": "Liella termina principal.",
      "t3_live1": "Liella coloca **Mirai wa Kaze no You ni** desde la **mano**.",
      "t3_live1_lock": "Liella bloquea su selección.",
      "t3_live2": "μ's coloca **START:DASH!!** boca abajo.",
      "t3_live2_lock": "μ's bloquea su selección: ¡Presentación final!",
      "t3_perf_intro": "Ambos confirmaron — ¡la **Presentación** final!",
      "t3_yell_mine": "Primero el **Yell** de μ's — voltea cartas del mazo por **Blade Corazones**. **START:DASH!!** ya coincide con el Escenario (**rosa**, **amarillo** y **morado** de **Rin Hoshizora**, **Honoka** y **Umi**).",
      "t3_yell_opp": "Siguiente tu **Yell** — **Shiki Wakana** en **Centro** (**Blade 2**) y **Mei Yoneme** en **Derecha** (**Blade 1**) voltean **3** cartas. **Mirai wa Kaze no You ni** trata esos Corazones de Yell como **cualquier** color — ¡el **Live** **tiene éxito** junto con el Escenario!",
      "t3_outcomes": "¡Ambos Lives **tuvieron éxito**! En este caso un desempate decide al ganador — ¡hora del **Juez de Live**!",
      "t3_judge": "**Juez de Live** — ¡μ's gana con puntuación potenciada! Otro **Live exitoso** más cerca de la victoria en la partida.",
      "outro": "Bucle principal: **Principal → Colocar Live → Presentación → Juez**. Las habilidades añaden sabor encima. ¡Prueba **Práctica contra CPU** después!",
      "outro_link": "Reglas completas: llofficial-cardgame.com/rule/ — ¡buena suerte!",
      "speaker": "Shibuya Kanon"
    },
    "mobile": {
      "rotateTitle": "Este juego se juega en horizontal",
      "rotateSub": "Gira tu dispositivo para continuar."
    },
    "common": {
      "loading": "Cargando…",
      "back": "← Atrás",
      "hubBack": "← Hub",
      "confirm": "Confirmar",
      "cancel": "Cancelar",
      "copy": "Copiar",
      "load": "Cargar",
      "preview": "Vista previa",
      "menu": "Menú principal",
      "seconds": "{n}s",
      "ok": "OK"
    },
    "toast": {
      "reconnected": "Reconectado a tu partida",
      "leftActiveMatch": "Saliste de la partida activa",
      "noActiveMatch": "No se encontró ninguna partida activa",
      "noCardId": "No hay ID de carta para copiar",
      "cardIdCopied": "ID de carta copiado",
      "couldNotCopyCardId": "No se pudo copiar el ID de carta",
      "signInDeckBuilder": "Inicia sesión para usar el Constructor de mazos.",
      "signOutDeckExperiment": "Cierra sesión para usar Experimento de mazo.",
      "rankedMatchFound": "¡Partida clasificatoria encontrada!",
      "casualMatchFound": "¡Partida casual encontrada!",
      "passwordCopied": "Contraseña copiada",
      "copyFailed": "Error al copiar",
      "copied": "¡Copiado! 📋",
      "liveOnly": "Solo las cartas Live o Miembro pueden ir al almacenamiento de Live",
      "onlyLiveOrMember": "Solo las cartas Live o Miembro pueden ir al almacenamiento de Live",
      "maxLiveCards": "Máx. {slots} carta para almacenamiento de Live",
      "maxLiveCardsPlural": "Máx. {slots} cartas para almacenamiento de Live",
      "maxLiveStorage": "Máx. {slots} carta para almacenamiento de Live",
      "maxLiveStoragePlural": "Máx. {slots} cartas para almacenamiento de Live",
      "liveStorageFull": "El almacenamiento de Live está lleno",
      "logCopied": "Registro copiado",
      "couldNotCopyLog": "No se pudo copiar el registro",
      "resolveSkillFirst": "Resuelve la habilidad pendiente antes de continuar."
    },
    "tutorialUi": {
      "exitTitle": "Salir al título",
      "back": "← Atrás",
      "next": "Siguiente →",
      "finish": "Finalizar"
    },
    "spectate": {
      "listTitle": "Espectar partida",
      "listTitleRanked": "Espectar partida clasificatoria",
      "listTitleCasual": "Espectar partida no clasificada",
      "lead": "Mira una partida en curso: solo lectura, sin interacción.",
      "barReadOnly": "Espectando — solo lectura",
      "barNames": "Espectando {p1} vs {p2}",
      "leave": "Salir",
      "reconnected": "Reconectado a la transmisión.",
      "watch": "Ver",
      "loading": "Cargando…",
      "noMatches": "No hay partidas disponibles para espectar.",
      "count": "Espectadores: {n}",
      "matchEnded": "La partida terminó — volviendo al lobby.",
      "sessionEnded": "La sesión de espectador terminó.",
      "switchPerspective": "Cambiar perspectiva"
    },
    "missions": {
      "claim": "Reclamar",
      "claimedStarterToast": "Reclamaste {title} — desbloqueaste {deck}",
      "claimedToast": "Reclamaste {title} (+{reward})",
      "completeToast": "Misión completada: {title}",
      "daily": {
        "completeAll": "Completa todas las misiones diarias",
        "openAllBoosters": "Abre todos los sobres diarios",
        "rankedMatch": "Juega una partida clasificatoria",
        "useStamp": "Usa un sello en una partida"
      },
      "empty": "No hay misiones en esta pestaña.",
      "loading": "Cargando misiones…",
      "milestone": {
        "cards1200": "Posee 1.200 cartas",
        "cards1600": "Posee 1.600 cartas",
        "cards400": "Posee 400 cartas",
        "cards800": "Posee 800 cartas",
        "profileBanner": "Actualiza tu banner de perfil",
        "profileFlag": "Pon una bandera en tu perfil",
        "profileStamps": "Configura sellos favoritos",
        "ranked1": "Juega 1 partida clasificatoria",
        "ranked10": "Juega 10 partidas clasificatorias",
        "ranked100": "Juega 100 partidas clasificatorias",
        "ranked1000": "Juega 1.000 partidas clasificatorias",
        "ranked5": "Juega 5 partidas clasificatorias",
        "ranked50": "Juega 50 partidas clasificatorias",
        "ranked500": "Juega 500 partidas clasificatorias",
        "unranked1": "Juega 1 partida no clasificatoria",
        "winAqours": "Gana con un mazo principal solo Aqours",
        "winHasunosora": "Gana con un mazo principal solo Hasunosora",
        "winLiella": "Gana con un mazo principal solo Liella!",
        "winMuse": "Gana con un mazo principal solo μ's",
        "winNijigasaki": "Gana con un mazo principal solo Nijigasaki"
      },
      "rewardStarter": "Elige un mazo inicial",
      "rewardStarterOwned": "Ya lo tienes",
      "starterPickCancel": "Cancelar",
      "starterPickConfirm": "Desbloquear mazo",
      "starterPickTitle": "Elige un mazo inicial",
      "statusActive": "En progreso",
      "statusClaimed": "Reclamada",
      "statusLocked": "Bloqueada",
      "statusReady": "Lista para reclamar",
      "tabDaily": "Diarias",
      "tabMilestone": "Hitos",
      "title": "Misiones"
    },
    "stamps": {
      "audio": "Audio de sellos",
      "audioMenu": "Voces de sellos",
      "voiceVolume": "Volumen de voces de sellos",
      "cooldown": "Espera un momento…",
      "done": "Listo",
      "editProfile": "Editar sellos favoritos",
      "empty": "No hay sellos.",
      "favoritesEmpty": "Sin favoritos — configúralos en Opciones o toca ☆ en un sello.",
      "pickerTitle": "Enviar sello",
      "profileCount": "{n} / {max} seleccionados",
      "profileFull": "Solo puedes guardar {max} sellos favoritos.",
      "profileHint": "Aparecen en la pestaña ★ Favoritos al enviar sellos en PvP.",
      "profileHintEmpty": "Opcional — elige hasta 20 sellos para acceso rápido en partidas.",
      "profilePickLead": "Toca sellos para añadirlos o quitarlos (máx. 20). Se usan en la pestaña ★ Favoritos en PvP.",
      "profilePickTitle": "Sellos favoritos",
      "profileSection": "Sellos favoritos",
      "send": "💬 Sellos",
      "tabEn": "English",
      "tabFavorites": "★ Favoritos",
      "tabJa": "日本語"
    },
    "ui": {
      "fullscreen": "Pantalla completa",
      "rankedSearch": "Búsqueda clasificatoria",
      "casualSearch": "Búsqueda casual",
      "skipToResults": "Saltar a resultados",
      "clickToOpen": "Clic para abrir"
    },
    "cardType": {
      "member": "Miembro",
      "live": "Live",
      "energy": "Energía"
    }
  },
  "ko": {
    "logo": {
      "tagline": "비공식 웹 플레이어"
    },
    "news": {
      "label": "뉴스",
      "title": "뉴스",
      "close": "닫기",
      "backToList": "← 전체 뉴스",
      "empty": "아직 등록된 뉴스가 없습니다.",
      "untitled": "제목 없음",
      "cardUnknown": "카드를 찾을 수 없습니다: {id}",
      "newBadge": "신규!"
    },
    "auth": {
      "checking": "Discord 로그인 확인 중…",
      "signingIn": "로그인 중…",
      "signInDiscord": "Discord로 로그인",
      "guestPrompt": "컬렉션을 저장하고 랭크전을 플레이하려면 Discord로 로그인하세요.",
      "guestPlayHint": "계정 없이 일반전을 즐기거나, 랭크전을 위해 로그인하세요.",
      "sessionExpired": "세션이 만료되었습니다 — 다시 로그인하거나 계정 없이 일반전을 플레이하세요.",
      "loadError": "계정을 불러올 수 없습니다 — 일반전은 계속 플레이할 수 있습니다.",
      "guestTimeout": "로그인 확인 시간이 초과되었습니다 — 일반전을 플레이하거나 Discord 로그인을 다시 시도하세요."
    },
    "menu": {
      "unrankedPlay": "일반전",
      "unrankedSub": "방 만들기, 친구와 대전, CPU 연습",
      "deckExperiment": "덱 실험",
      "deckExperimentSub": "모든 카드로 덱 구성 — 게스트 전용, 일반전",
      "howToPlay": "플레이 방법",
      "howToPlaySub": "카논과 함께하는 초보자 실습 강의"
    },
    "hub": {
      "signedIn": "로그인됨",
      "signedInAs": "로그인 계정",
      "signedInAsHtml": "<b>{name}</b>님으로 로그인됨",
      "dailyBoosters": "일일 부스터: 오늘 {remaining} / {limit} 남음 (JST)",
      "dailyWelcomeBonus": " (웰컴 보너스!)",
      "daily": "일일 부스터: 오늘 {remaining} / {limit} 남음 (JST)",
      "dailyBonus": " (웰컴 보너스!)",
      "rankLine": "ELO {elo} · {wins}승-{losses}패 · 승률 {winPct}%",
      "options": "옵션",
      "signOut": "로그아웃",
      "openBoosters": "부스터 개봉",
      "openBoostersSub": "카드 부스터 팩 개봉하기",
      "deckBuilder": "덱 빌더",
      "deckBuilderSub": "프리셋 편집 및 랭크전 덱 설정",
      "rankedPvp": "랭크 PvP",
      "rankedPvpSub": "매칭 대전으로 ELO 올리기",
      "leaderboard": "리더보드",
      "leaderboardSub": "온라인 순위 보기",
      "unranked": "일반전",
      "unrankedSub": "방 만들기, 친구와 대전, CPU 연습",
      "tournamentMode": "토너먼트 모드",
      "tournamentModeSub": "곧 공개",
      "howToPlay": "플레이 방법",
      "howToPlaySub": "카논과 함께하는 초보자 실습 강의",
      "backHub": "← 허브",
      "missions": "미션",
      "rankedPrCount": "{remaining} / {limit}",
      "rankedPrTitle": "오늘 남은 랭크 PR 보상: {remaining} / {limit} (JST)"
    },
    "language": {
      "label": "언어",
      "en": "English",
      "ja": "日本語",
      "es": "Español",
      "ko": "한국어"
    },
    "lobby": {
      "title": "일반전",
      "yourName": "내 이름",
      "namePlaceholder": "아이돌 이름…",
      "deck": "덱",
      "createRoom": "방 만들기",
      "joinRoom": "방 참가",
      "roomCode": "방 코드",
      "vsPlayer": "플레이어 대전",
      "vsCpu": "CPU 대전",
      "practiceCpu": "CPU 연습",
      "cpuDifficulty": "CPU 난이도",
      "cpuEasy": "쉬움 — 무작위 스타터 덱",
      "cpuNormal": "보통 — 더 똑똑한 스킬과 Live",
      "cpuHard": "어려움 — 강력한 덱과 스킬 우선순위",
      "cpuExpert": "전문가 — 시뮬레이션 탐색과 위협 예측",
      "cpuEasyShort": "쉬움",
      "cpuNormalShort": "보통",
      "cpuHardShort": "어려움",
      "cpuExpertShort": "전문가",
      "soloStarting": "CPU ({diff}) 대전 시작",
      "findRandomMatch": "무작위 대전 찾기",
      "spectate": "경기 관전",
      "cancelSearch": "검색 취소",
      "phaseTimer": "페이즈 타이머 (메인 & Live)",
      "phaseTimerSec": "페이즈당 초 (10–120)",
      "backHub": "← 허브",
      "orJoinFriend": "또는 친구와 참가",
      "orMatchRandomly": "또는 무작위 매칭",
      "casualHint": "캐주얼 PvP — ELO나 랭크 기록 없음",
      "experimentDeckPassword": "실험 덱 비밀번호",
      "experimentPasswordPlaceholder": "8자리 코드",
      "experimentDeckBtn": "덱 실험",
      "experimentDeckHint": "덱 실험에서 덱을 만들고 비밀번호를 생성한 뒤 여기에 입력하세요 — 또는 아래에서 저장된 덱을 선택하세요.",
      "secondsLabel": "{n}초",
      "casualQueueStats": "대기 {waiting}명 · 캐주얼 진행 중 {inGame}명",
      "casualSearching": "상대 찾는 중… ({seconds}초)",
      "roomCodePlaceholder": "ABCD1234"
    },
    "deck": {
      "title": "덱 빌더",
      "experimentTitle": "덱 실험",
      "deckName": "덱 이름",
      "presetSlot": "프리셋 슬롯 (최대 10개)",
      "search": "카드 검색",
      "searchPlaceholder": "이름, ID 또는 룰 텍스트…",
      "collection": "컬렉션",
      "currentDeck": "현재 덱",
      "savePreset": "프리셋 저장",
      "equipRanked": "랭크전 장착",
      "autoBuild": "자동 구성",
      "clear": "초기화",
      "hint": "자동 구성은 내 컬렉션에서 최적화합니다 · 탭하여 추가/제거 · 마우스 오버로 미리보기 · 포인트 합계는 9 이하여야 함",
      "hoverEmpty": "덱 카드를 마우스 오버하면 여기에서 미리 볼 수 있습니다.",
      "backHub": "← 허브",
      "backMenu": "← 메뉴",
      "deckPassword": "덱 비밀번호",
      "deckPasswordPlaceholder": "불러올 비밀번호 입력",
      "load": "불러오기",
      "savedPassword": "저장된 비밀번호:",
      "copy": "복사",
      "cardPool": "카드 풀",
      "resetStarter": "스타터 초기화",
      "useStarter": "스타터 사용",
      "randomDeck": "무작위 덱",
      "updateSavedDeck": "저장된 덱 업데이트",
      "generatePassword": "비밀번호 생성",
      "experimentHint": "전체 카드 풀 · 정식 덱을 구성하면 비밀번호가 생성됩니다 · 포인트 합계는 9 이하여야 함 · 카드를 길게 누르거나 우클릭하면 상세 정보 확인",
      "collectionOwned": "보유 카드 총합 · {count}",
      "collectionLoading": "전체 카드 풀 · 불러오는 중…",
      "collectionMatch": "컬렉션 · {match}개 일치",
      "deckStats": "총합 {total}/72 · 멤버 {members}/48 · 라이브 {lives}/12 · 에너지 {energy}/12 · 포인트 {lovecaPoints}/{lovecaLimit}",
      "lovecaPointLabel": "포인트",
      "lovecaPointBadge": "{n}pt",
      "lovecaOverLimit": "포인트 합계가 {total}이 됩니다 (최대 {limit}).",
      "lovecaDeckIllegal": "포인트 합계가 {total}입니다 — 덱은 {limit} 이하여야 합니다.",
      "deckIllegalSize": "덱은 정식 규격이어야 합니다: 메인 60장(멤버 48장, 라이브 12장), 에너지 12장.",
      "lovecaExplain": "일부 강력한 카드는 포인트를 소모합니다. 메인 덱의 포인트 합계는 9 이하로 유지해야 합니다 (매 장마다 계산됩니다).",
      "deckEmpty": "컬렉션의 카드를 탭하여 덱을 구성하세요.",
      "deckEmptyExperiment": "카드 풀에서 카드를 탭하여 60+12 정식 덱을 구성하세요.",
      "experimentStarterTitle": "스타터 덱 선택",
      "experimentStarterLead": "공식 스타터 리스트를 기본으로 불러온 뒤, 전체 카드 풀에서 자유롭게 편집하세요.",
      "accountStarterHint": "내 계정 스타터: {starter} · 프리셋 #1은 이 리스트에서 시작합니다.",
      "noStarterOnAccount": "이 계정에는 스타터 덱이 없습니다.",
      "loadedStarterIntoPreset": "{name}을 프리셋 #{slot}에 불러왔습니다.",
      "loadedStarterFallbackName": "스타터 덱",
      "chooseStarterFirst": "먼저 스타터 덱을 선택하세요.",
      "building": "구성 중…",
      "autoBuiltSuccess": "컬렉션에서 정식 덱을 자동으로 구성했습니다.",
      "starterDecksNotLoaded": "스타터 덱을 아직 불러오지 못했습니다.",
      "equippedRanked": "랭크전 장착 완료.",
      "filters": {
        "title": "필터",
        "showAdvanced": "고급 필터 표시",
        "hideAdvanced": "고급 필터 숨기기",
        "all": "전체",
        "allTypes": "모든 타입",
        "allFields": "모든 항목",
        "allProducts": "모든 제품",
        "allSets": "모든 세트",
        "any": "무관",
        "min": "최소",
        "max": "최대",
        "notIncluded": "미포함",
        "heartAll": "전체",
        "drawIcon": "드로우 아이콘",
        "scoreIcon": "스코어 아이콘",
        "label": {
          "type": "타입",
          "group": "그룹",
          "rarity": "레어도",
          "keywordIn": "키워드 위치",
          "product": "제품",
          "productSet": "제품 세트",
          "subunit": "서브 유닛",
          "parallel": "Parallel",
          "printedHearts": "표기 하트",
          "requiredHearts": "필요 하트",
          "bladeHearts": "Blade 하트",
          "blade": "Blade",
          "cost": "코스트",
          "score": "스코어"
        },
        "type": {
          "member": "멤버",
          "live": "Live",
          "energy": "에너지"
        },
        "searchMode": {
          "all": "모든 항목",
          "name": "이름",
          "text": "텍스트",
          "id": "카드 ID"
        },
        "searchPlaceholder": {
          "all": "이름, ID 또는 룰 텍스트…",
          "name": "카드 이름…",
          "text": "룰 텍스트…",
          "id": "카드 ID 예: PL!N-sd1-021-SD"
        },
        "productKind": {
          "bp": "Booster Pack",
          "pb": "Premium Booster",
          "pb_duo": "Premium Booster (DUO)",
          "sd": "스타터 덱",
          "collection": "컬렉션",
          "pr": "PR"
        },
        "parallel": {
          "normal": "노멀만",
          "parallel": "Parallel만"
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
          "aria": "컬렉션 정렬 및 순서",
          "sortBy": "정렬 기준",
          "order": "순서",
          "asc": "오름차순",
          "desc": "내림차순",
          "id": "카드 ID",
          "rarity": "레어도",
          "name": "이름 (아이돌)",
          "type": "카드 타입",
          "group": "그룹 / 학교",
          "recent": "최근 획득"
        }
      },
      "basicDecks": "기본 덱",
      "starters": {
        "nijigasaki": "니지가사키 스타터 덱",
        "muse": "μ's 스타터 덱",
        "liella": "Liella! 스타터 덱",
        "hasunosora": "하스노소라 스타터 덱",
        "sunshine": "Sunshine!! 스타터 덱"
      },
      "groups": {
        "nijigasaki": "니지가사키",
        "hasunosora": "하스노소라",
        "sunshine": "Sunshine",
        "superstar": "Superstar",
        "mus": "μ's",
        "saintsnow": "Saint Snow",
        "arise": "A-RISE",
        "sunnypassion": "Sunny Passion"
      }
    },
    "booster": {
      "title": "부스터 개봉",
      "openPack": "팩 개봉 ({n}장)",
      "openPaidBox": "박스 1개 개봉 ({n}팩)",
      "ratesLead": "카드 풀 {pool}장 · {n}장 팩에 등장할 확률",
      "packOpened": "팩 개봉 완료",
      "godPack": "GOD PACK!",
      "openAnother": "다른 팩 열기",
      "openSameAgain": "같은 팩 다시 열기",
      "packsLeft": "오늘 남은 팩 {n}개 (JST)",
      "mainMenu": "메인 메뉴",
      "backHub": "← 허브",
      "noDailyPacks": "오늘의 팩이 모두 소진되었습니다",
      "paidLead": "Star Gems를 사용해 오늘 팩을 계속 열 수 있습니다.",
      "openOnePack": "팩 1개 열기",
      "starGemsLabel": "Star Gems:",
      "starGemsUnit": "Star Gems {n}개",
      "dailyPack": "일일 팩",
      "packRatesTitle": "팩 확률",
      "packRatesPerPack": "팩당 확률 · 카드를 탭하면 상세 정보",
      "ratesLoading": "확률 불러오는 중…",
      "duplicatesConverted": "중복 카드 전환",
      "migrationText": "카드당 4장을 초과한 멤버/라이브 카드와 12장을 초과한 에너지 카드는 {gems}로 전환되었습니다.",
      "convertedToGems": " · {n}개가 Star Gems로 전환됨",
      "needMoreGems": "박스를 열려면 Star Gem {n}개가 필요합니다",
      "noPacksOrGems": "일일 팩이 없고 Star Gem도 부족합니다.",
      "openBox": "박스 열기 (팩 {n}개)",
      "selectBoxFirst": "먼저 부스터 세트를 선택하세요"
    },
    "ranked": {
      "title": "랭크 PvP",
      "findMatch": "대전 찾기",
      "cancelSearch": "검색 취소",
      "spectate": "경기 관전",
      "timerNote": "메인 및 Live 페이즈는 120초 타이머를 사용합니다.",
      "deckLabel": "랭크전 덱",
      "matchSound": "매칭 완료 시 알림음 재생",
      "leaderboard": "리더보드",
      "leaderboardTitle": "랭크 리더보드",
      "backHub": "← 허브",
      "infoLine": "ELO {elo} · {record}",
      "record": "{wins}승-{losses}패 · 승률 {winPct}%",
      "recordFull": "{wins}승-{losses}패 · 승률 {winPct}% · 패률 {lossPct}%",
      "queueStats": "대기 {waiting}명 · 랭크전 진행 중 {inGame}명",
      "searching": "검색 중… ({seconds}초)",
      "readySearch": "검색 준비 완료",
      "prRemaining": "오늘 PR 보상: {remaining} / {limit} 남음 (JST)"
    },
    "leaderboard": {
      "title": "랭크 리더보드",
      "lead": "랭크 PvP에서 달성한 최고 ELO입니다. 프로필 행에 표시할 카드 배너와 국기를 설정하세요.",
      "empty": "아직 랭크전 기록이 없습니다 — 랭크 PvP를 플레이하면 여기에 표시됩니다.",
      "editBanner": "프로필 편집",
      "eloSuffix": " ELO",
      "eloLabel": "{elo} ELO",
      "profileBanner": "프로필 배너",
      "bannerLead": "보유한 카드를 선택한 뒤, 띠를 위아래로 드래그하여 리더보드 카드에 표시할 그림을 고르세요.",
      "bannerSearchPlaceholder": "카드 이름으로 검색…",
      "bannerNoMatch": "검색 결과와 일치하는 카드가 없습니다.",
      "bannerPreview": "미리보기",
      "saveBanner": "배너 저장",
      "profileFlag": "프로필 국기",
      "flagLead": "리더보드에서 이름 옆에 표시할 국기를 고르세요.",
      "flagSearchPlaceholder": "국기 검색…",
      "equipFlag": "국기 장착",
      "flagNone": "없음",
      "flagEquipped": "현재 장착 중.",
      "flagReady": "{name}을(를) 장착할까요?",
      "flagLoading": "국기 불러오는 중…",
      "selectCardFirst": "먼저 카드를 선택하세요",
      "jumpToYou": "내 순위로 이동",
      "yourRank": "내 순위: #{rank}"
    },
    "options": {
      "title": "옵션",
      "enhancedTextures": "고레어도 카드에 고해상도 텍스처 적용",
      "soundEffects": "음향 효과",
      "sfxVolume": "SFX 음량",
      "stuckTitle": "경기에 갇혀 있나요?",
      "stuckLead": "랭크전이 깨지거나 종료된 경기로 재접속시킨다면, 여기서 진행 중인 경기 기록을 나가세요. 경기가 아직 진행 중이라면 기권으로 처리됩니다.",
      "resetTitle": "계정 초기화",
      "resetLead": "모든 컬렉션 카드, 덱 프리셋, 랭크 전적, 부스터 진행도가 삭제됩니다. 새 스타터 덱을 선택하고 처음부터 다시 시작합니다. 이 작업은 되돌릴 수 없습니다.",
      "resetAccount": "계정 초기화",
      "backHub": "← 허브"
    },
    "starter": {
      "title": "스타터 덱을 선택하세요",
      "lead": "컬렉션의 기반이 될 공식 스타터 덱을 하나 선택하세요. 이 선택은 영구적입니다.",
      "confirm": "스타터 확정"
    },
    "waiting": {
      "roomCreated": "방 생성 완료!",
      "shareCode": "이 코드를 상대에게 공유하세요:",
      "tapCopy": "탭하여 복사 📋",
      "clickCopy": "클릭하여 복사",
      "waitingOpponent": "상대가 참가하기를 기다리는 중…",
      "cancel": "취소",
      "phaseTimerInfo": "페이즈 타이머: 메인 & Live 턴당 {sec}초"
    },
    "game": {
      "you": "나",
      "opponent": "상대",
      "opp": "상대",
      "gameLog": "게임 로그",
      "resign": "🏳 기권",
      "resignConfirm": "기권하시겠습니까?",
      "enableRadio": "📻 라디오 켜기",
      "endMainPhase": "메인 페이즈 종료",
      "endLivePhase": "Live 페이즈 종료",
      "setLiveCards": "Live 카드 세팅",
      "waitingOpponent": "상대 대기 중",
      "resolveSkillFirst": "먼저 스킬을 해결하세요",
      "waitingSkill": "스킬 대기 중",
      "yourHand": "내 손패",
      "mainDeck": "메인 덱",
      "waitingRoom": "대기실",
      "oppWaitingRoom": "상대 대기실",
      "deckHidden": "상대의 덱은 비공개입니다.",
      "energyDeck": "에너지 덱",
      "liveStorage": "Live 스토리지",
      "successStorage": "성공 Live 더미",
      "stageBoard": "스테이지 보드",
      "activatableSkills": "발동 가능한 스킬",
      "activeEffects": "활성 효과",
      "hoverHandEmpty": "손패의 카드를 마우스 오버하면 여기서 미리 볼 수 있습니다.",
      "starting": "시작 중…",
      "hand": "손패",
      "wr": "대기",
      "spectating": "관전 중 — {p1} vs {p2} (읽기 전용)",
      "oppActivatingSkill": "상대가 스킬을 발동하는 중…",
      "activeEnergy": "활성",
      "pickSlot": "슬롯을 선택하세요",
      "batonPassHint": "바톤 터치 — 활성 에너지 {cost} 지불",
      "overplayHint": "오버플레이 — 활성 에너지 {cost} 지불",
      "slotLeft": "왼쪽",
      "slotCenter": "센터",
      "slotRight": "오른쪽",
      "baton": "바톤",
      "batonToggleOn": "탭하면 오버플레이 모드",
      "batonToggleOff": "탭하면 바톤 터치",
      "opponentSkillWait": "{name}이(가) 스킬을 발동하는 중…",
      "perfYou": "나",
      "perfOpp": "상대",
      "sidebarInfo": "{turn}<span class=\"turn-sep\">·</span>페이즈: {phase}<span class=\"turn-sep\">·</span>활성: {active}<span class=\"turn-sep\">·</span>선공: {first}",
      "deckTopLabel": "덱 상단",
      "hoverPickerEmpty": "카드를 가리키면 여기에 미리보기가 표시됩니다.",
      "zoneEmpty": "비어 있음"
    },
    "slot": {
      "left": "왼쪽",
      "center": "센터",
      "right": "오른쪽"
    },
    "phase": {
      "waiting": "대기 중",
      "setup": "준비 (멀리건)",
      "main": "메인 페이즈",
      "main_first": "메인 페이즈",
      "main_second": "메인 페이즈",
      "live": "Live 페이즈",
      "live_set": "Live 페이즈",
      "live_set_first": "Live 페이즈",
      "live_set_second": "Live 페이즈",
      "live_start_effects": "라이브 개시",
      "live_success_effects": "라이브 성공",
      "performance": "퍼포먼스 페이즈",
      "live_performance_first": "퍼포먼스 페이즈",
      "live_performance_second": "퍼포먼스 페이즈",
      "coinFlip": "코인 플립",
      "preparation": "준비",
      "active": "액티브 페이즈",
      "active_first": "액티브 페이즈",
      "active_second": "액티브 페이즈",
      "live_judge": "Live 승패 확인"
    },
    "phaseId": {
      "waiting": "대기 중",
      "coin_flip": "코인 플립",
      "setup": "준비 (멀리건)",
      "active_first": "액티브 페이즈",
      "active_second": "액티브 페이즈",
      "main_first": "메인 페이즈",
      "main_second": "메인 페이즈",
      "live_set": "Live 페이즈",
      "live_set_first": "Live 페이즈",
      "live_set_second": "Live 페이즈",
      "live_start_effects": "라이브 개시",
      "live_success_effects": "라이브 성공",
      "live_performance_first": "퍼포먼스 페이즈",
      "live_performance_second": "퍼포먼스 페이즈",
      "live_judge": "Live 승패 확인"
    },
    "phaseBar": {
      "spectating": "관전 중 — {p1} vs {p2} (읽기 전용)",
      "setupWaitMulligan": "상대가 멀리건을 마치기를 기다리는 중…",
      "setupMulligan": "준비 — 시작 패를 확인하고 원하는 카드를 멀리건한 뒤 확정하세요.",
      "coinFlip": "코인 플립 — 승자가 선공을 선택합니다…",
      "mainYour": "내 메인 페이즈 — 멤버를 플레이하세요 ({energy} 사용 가능). 준비되면 메인 페이즈를 종료하세요.",
      "mainOpp": "{name}의 턴 — 메인 페이즈…",
      "mainOppS": "{name}의 턴 — 메인 페이즈…",
      "liveRaised": "Live 페이즈 — {count}장 올림 · 손패를 탭해 조정 · 로그 아래 버튼으로 확정",
      "liveRaisedPlural": "Live 페이즈 — {count}장 올림 · 손패를 탭해 조정 · 로그 아래 버튼으로 확정",
      "liveStored": "Live 페이즈 — 스토리지에 {stored}장 · Live 또는 멤버를 최대 {slots}장 더 놓거나, 로그 아래에서 Live 페이즈를 종료하세요",
      "livePlace": "Live 페이즈 — Live 또는 멤버 카드를 0~{slots}장 놓은 뒤 Live 페이즈를 종료하세요 · 버튼은 로그 아래",
      "liveBothLocked": "두 플레이어 모두 선택 확정 — 퍼포먼스 시작 중…",
      "liveYouLocked": "선택을 확정했습니다 — 상대의 Live 선택 완료를 기다리는 중…",
      "liveStartEffects": "라이브 개시 프롬프트를 해결하세요 — 선택 효과는 오버레이로 표시됩니다.",
      "liveSuccessEffects": "라이브 성공 프롬프트를 해결하세요 — 선택 효과는 오버레이로 표시됩니다.",
      "performance": "퍼포먼스 페이즈 — Yell · 하트 · Live 성공 확인",
      "liveJudge": "Live 승패 확인 페이즈…"
    },
    "phaseBanner": {
      "coinFlipTitle": "코인 플립",
      "coinFlipSub": "승자가 선공을 선택합니다",
      "setupTitle": "준비",
      "setupSub": "선택적 멀리건 (1회 교체)",
      "activeTitle": "액티브 페이즈",
      "activeSub": "에너지 및 멤버 리프레시",
      "mainYour": "내 메인 페이즈",
      "mainOpp": "{name}의 메인 페이즈",
      "mainOppS": "{name}의 메인 페이즈",
      "liveTitle": "Live 페이즈",
      "livePlayer": "{name}의 Live 페이즈",
      "livePlayerS": "{name}의 Live 페이즈",
      "liveSub": "Live 또는 멤버 카드를 0~3장 놓은 뒤 Live 페이즈를 종료하세요",
      "liveStartTitle": "라이브 개시",
      "liveStartSub": "퍼포먼스 전 선택 효과",
      "liveSuccessTitle": "라이브 성공",
      "liveSuccessSub": "하트 확인 후 선택 효과",
      "performanceTitle": "퍼포먼스 페이즈",
      "performanceSub": "공개 · Yell · 하트",
      "liveJudgeTitle": "Live 승패 확인",
      "liveJudgeSub": "Live 스코어 비교 중…",
      "yourMain": "내 메인 페이즈",
      "theirMain": "{name}의 메인 페이즈",
      "theirMainS": "{name}의 메인 페이즈",
      "theirLive": "{name}의 Live 페이즈",
      "theirLiveS": "{name}의 Live 페이즈",
      "yourLive": "당신의 Live 페이즈"
    },
    "splash": {
      "turn": "턴 {turn}",
      "turnBegin": "턴 {turn} 시작",
      "noLives": "이번 턴에 플레이된 Live 없음",
      "gameStart": "게임 시작",
      "deckRefresh": "덱 리프레시",
      "deckRefreshOpp": "{name} — 덱 리프레시",
      "deckRefreshSub": "대기실에서 {n}장을 섞어 덱에 합침",
      "youAttemptLive": "Live 시도!",
      "theyAttemptLive": "{name} Live 시도",
      "attemptSub": "Yell 드로우 · 하트 확인",
      "youWait": "Wait",
      "theyWait": "{name} Wait",
      "youWaitSub": "Live 카드가 스토리지에 남습니다",
      "theyWaitSub": "Live 카드가 상대 스토리지에 남습니다",
      "perfRoundFailed": "{ok}개 하트 충족 · 라운드 실패 (모든 Live가 성공해야 함)",
      "perfCleared": "Live 카드 {ok}장이 라운드를 클리어함",
      "perfMixed": "{ok}개 성공 · {fail}개 하트 실패 → 대기실",
      "yourLivePerformance": "내 Live 퍼포먼스",
      "theirLive": "{name} Live",
      "perfSubYell": "Yell {blades} · {sub}",
      "successLiveYou": "성공 Live!",
      "successLiveThey": "{name} — 성공 Live!",
      "successLiveSubYou": "Live 카드가 내 성공 더미에 추가됩니다",
      "successLiveSubThey": "Live 카드가 상대 성공 더미에 추가됩니다",
      "bothWait": "두 플레이어 모두 Wait",
      "bothWaitSub": "Live 카드가 스토리지에 남습니다",
      "liveStartFlash": "라이브 개시",
      "liveJudgeTieCappedBoth": "Live 스코어 동점 — 양쪽 모두 이미 성공 Live 2개라 추가되지 않음",
      "liveJudgeTieYouCappedWin": "동점 — 이미 성공 Live 2개로 한도에 도달함",
      "liveJudgeTieOppEarns": "동점 — 상대가 성공 Live를 획득함",
      "liveJudgeTieYouEarns": "동점 — 성공 Live를 획득함",
      "liveJudgeTieOppCappedWin": "동점 — 상대는 이미 성공 Live 2개로 한도에 도달함",
      "liveJudgeTieBothSucceed": "Live 스코어 동점 — 양쪽 모두 성공!",
      "liveJudgeYouWin": "Live 스코어로 승리!",
      "liveJudgeOppWin": "상대가 Live 스코어로 승리",
      "liveJudgeNamedWin": "{name}이(가) Live 스코어로 승리"
    },
    "mulligan": {
      "title": "시작 패 🌸",
      "hint": "교체할 카드를 탭하여 표시하세요. 카드를 길게 누르면 상세 정보를 볼 수 있습니다. 다시 탭하면 표시가 해제됩니다.",
      "keepHand": "패 유지",
      "replaceCard": "{n}장 교체",
      "replaceCards": "{n}장 교체",
      "tutorialKeepHint": "이 핸드로 괜찮다면 「핸드 유지」를 눌러 계속하세요.",
      "tutorialReplaceHint": "강조된 카드를 눌러 교체 대상으로 표시한 뒤 확인하세요."
    },
    "coin": {
      "title": "선공 결정",
      "flipping": "코인 던지는 중…",
      "goFirst": "내가 선공",
      "escortFirst": "Escort가 선공",
      "opponentFirst": "상대가 선공",
      "waitingOppFlip": "상대가 코인 플립을 확인하는 것을 기다리는 중…",
      "waitingOpp": "상대를 기다리는 중…",
      "wonFlip": "{name}이(가) 코인 플립에서 승리했습니다!",
      "wonFlipShort": "{name}이(가) 코인 플립에서 승리",
      "winnerChoosing": "선공을 선택하는 중…",
      "chooseFirst": "선공을 선택하세요",
      "youWon": "코인 플립에서 승리했습니다!",
      "oppGoesFirst": "{name}이(가) 선공"
    },
    "live": {
      "overlayTitle": "Live 페이즈 — 카드 세팅",
      "overlayHint": "Live 페이즈: Live 또는 멤버 카드를 0~3장 Live 스토리지에 놓습니다 — 내 카드는 앞면으로 표시되지만 상대 카드는 퍼포먼스까지 숨겨집니다. 놓은 카드마다 1장씩 드로우한 뒤 Live 페이즈를 종료하세요. 퍼포먼스에서 상대의 스토리지가 한 번에 공개됩니다.",
      "placeInStorage": "스토리지에 놓기",
      "selected": "선택됨",
      "inStorage": "스토리지에 있음",
      "liveScore": "Live 스코어",
      "combinedHearts": "합산 필요 하트",
      "livesSelected": "Live {n}장",
      "livesSelectedOne": "Live 1장",
      "plusMembers": "+멤버 {n}장",
      "plusMembersOne": "+멤버 1장",
      "liveJudge": "Live 심판",
      "liveWinLoss": "Live 승패 확인",
      "yourScore": "내 스코어",
      "oppScore": "상대 스코어"
    },
    "prompt": {
      "confirm": "확인",
      "cancel": "취소",
      "respond": "응답",
      "chooseCards": "카드 선택",
      "chooseFromHand": "손패에서 선택",
      "chooseHeart": "하트 선택",
      "discardFromHand": "손패에서 버리기",
      "discardOne": "대기실로 보낼 카드를 선택하세요.",
      "discardMany": "대기실로 보낼 카드 {count}장을 선택하세요.",
      "selectThenConfirm": "카드를 선택한 뒤 확인을 탭하세요.",
      "tapCardConfirm": "카드를 탭하여 확정하세요.",
      "yes": "예",
      "noSkip": "아니요 — 건너뛰기",
      "skip": "건너뛰기",
      "tapOption": "아래 옵션을 탭하세요.",
      "useLiveStart": "이 라이브 개시 효과를 사용하시겠습니까?",
      "useEffect": "이 효과를 사용하시겠습니까?",
      "answer": "답변",
      "typeAnswer": "답변을 입력하세요…",
      "typeAnswerHint": "답변을 입력하세요 — 철자나 표현은 조금 달라도 괜찮습니다.",
      "confirmArrangement": "배치 확정",
      "selectedCount": "선택됨: {n}/{max}",
      "activateSub": "이 효과를 발동할지 선택하세요.",
      "lookAtDeck": "덱 확인",
      "surveilHint": "1번 자리는 덱의 맨 위입니다. 번호가 매겨진 자리와 대기실 사이로 카드를 드래그하거나, 두 카드를 탭해 맞바꾸거나, 카드가 선택된 상태에서 자리나 대기실을 탭하세요.",
      "surveilHintReturnAll": "1번 자리는 덱의 맨 위입니다. 번호가 매겨진 자리 사이로 드래그하거나 두 카드를 탭해 맞바꾸세요. 모든 카드는 덱 위에 남겨야 합니다.",
      "wrPickTitle": "대기실",
      "wrPickMsg": "대기실에서 손패에 추가할 카드를 선택하세요.",
      "yellPickTitle": "Yell",
      "yellPickMsg": "Yell로 공개된 카드 중 1장을 선택하세요.",
      "successLivePickTitle": "성공 Live",
      "successLivePickMsg": "성공 Live에 놓을 Live 카드 1장을 선택하세요.",
      "successLiveHandTitle": "성공 Live",
      "successLiveHandMsg": "성공 Live 영역에서 손패에 추가할 카드 1장을 선택하세요.",
      "deckTopTitle": "덱 상단",
      "deckTopMsg": "Yell로 공개된 카드 중 덱 맨 위에 놓을 카드 1장을 선택하세요.",
      "wrEmpty": "대기실이 비어 있습니다",
      "wrNoMatch": "대기실에 일치하는 카드가 없습니다",
      "yellNoCards": "선택할 Yell 카드가 없습니다",
      "noLiveSuccess": "성공 Live에 놓을 Live 카드가 없습니다",
      "searchDeckFor": "덱에서 검색…",
      "deckTopPick": "덱 상단"
    },
    "skill": {
      "alreadyUsed": "이번 턴에 이미 사용함",
      "needEnergy": "활성 에너지 {n} 필요",
      "tutorialDemo": "튜토리얼 데모 — 계속하려면 다음을 사용하세요"
    },
    "skillKw": {
      "onEnter": {
        "title": "등장 시",
        "body": "이 멤버가 손패에서 스테이지로 플레이될 때 한 번 발동합니다."
      },
      "onLeave": {
        "title": "퇴장 시",
        "body": "이 멤버가 스테이지를 떠날 때 발동합니다 (대기실로 이동, 바톤 터치 등)."
      },
      "liveStart": {
        "title": "라이브 개시",
        "body": "Live 시도 후 라이브 개시 단계에서 해결됩니다. 많은 효과가 선택적이므로 \"해도 된다\"라는 표현을 확인하세요."
      },
      "liveSuccess": {
        "title": "라이브 성공",
        "body": "Live 퍼포먼스가 성공했을 때 해결됩니다 — 시도한 Live 카드의 필요 하트가 충족된 경우입니다."
      },
      "activated": {
        "title": "기동",
        "body": "멤버가 스테이지에서 활성 상태일 때 메인 페이즈 중 직접 사용을 선택합니다. 먼저 표시된 코스트를 지불하세요."
      },
      "always": {
        "title": "상시",
        "body": "이 멤버가 필드에 있고 조건이 충족되는 동안 계속 적용되는 패시브 효과입니다. 별도로 발동할 필요는 없습니다."
      },
      "oncePerTurn": {
        "title": "턴에 1회",
        "body": "이 효과는 각 턴에 한 번만 사용할 수 있습니다."
      },
      "automatic": {
        "title": "자동",
        "body": "표시된 조건이 발생하면 자동으로 발동됩니다 — 별도의 발동 절차가 필요하지 않습니다."
      },
      "center": {
        "title": "센터",
        "body": "효과가 해결될 때 이 멤버가 스테이지의 센터 슬롯에 있을 때만 적용됩니다."
      },
      "yell": {
        "title": "Yell (エール)",
        "body": "Live 퍼포먼스 중, 활성 스테이지 멤버의 총 Blade 수만큼 덱에서 카드를 드로우합니다. 이 카드들은 공개되며, 표시된 하트는 Live 카드의 필요 하트를 충족하는 데 사용됩니다. Yell 카드는 이후 대기실로 보내집니다."
      },
      "wait": {
        "body": "Wait 상태가 된 멤버는 그 턴 Blade를 기여하지 않습니다 — Live 퍼포먼스에서 Yell로 공개되는 카드의 Blade를 올리지 않습니다. Waiting Room과는 다릅니다.",
        "title": "Wait (ウェイト)"
      }
    },
    "heart": {
      "pickColor": "이 효과에 사용할 하트 색을 선택하세요.",
      "yellow": "노란색",
      "pink": "핑크색",
      "purple": "보라색",
      "red": "빨간색",
      "green": "초록색",
      "blue": "파란색"
    },
    "card": {
      "cost": "코스트",
      "blade": "Blade",
      "score": "스코어",
      "requiredHearts": "필요 하트",
      "hearts": "하트",
      "yellIcons": "Yell 아이콘",
      "playToSlot": "슬롯에 플레이:",
      "needEnergy": "필요",
      "haveEnergy": "보유",
      "bladeHearts": "Blade 하트"
    },
    "pack": {
      "opened": "팩 개봉 완료",
      "boxOpened": "박스 개봉 완료"
    },
    "log": {
      "gameStartedCoinFlip": "게임이 시작되었습니다! 코인 플립 — 승자가 선공을 선택합니다.",
      "preparationDrawEnergy": "준비: 각 플레이어가 6장을 드로우하고 에너지 3장을 스토리지에 놓았습니다.",
      "preparationMulligan": "준비 — 멀리건: 시작 패의 카드를 원하는 만큼 한 번 교체할 수 있습니다.",
      "livePhaseIntro": "Live 페이즈: Live 또는 멤버 카드를 0~3장 뒷면으로 Live 스토리지에 놓습니다 (놓은 카드마다 1장 드로우), 이후 Live 페이즈를 종료합니다.",
      "bothRevealLive": "두 플레이어가 동시에 Live 스토리지를 공개합니다.",
      "noLivesThisTurn": "이번 턴에 플레이된 Live가 없습니다.",
      "remainingLiveToWr": "남은 Live 스토리지가 대기실로 보내졌습니다.",
      "neitherWrFromHand": "두 플레이어 모두 대기실로 보낼 손패 카드가 없었습니다.",
      "neitherCouldDraw": "두 플레이어 모두 드로우할 수 없었습니다 (덱 소진).",
      "neitherLiveWinner": "두 플레이어 모두 성공하지 못했습니다 — 이번 턴에는 Live 승자가 없습니다.",
      "coinFlipAuto": "코인 플립 — 자동으로 진행되었습니다 (플레이어가 시간 내에 응답하지 않음).",
      "cpuDeck": "CPU 덱: {label}",
      "dividerLive": "=== Live 페이즈 ===",
      "dividerPerformance": "=== 퍼포먼스 페이즈 ===",
      "dividerLiveShow": "=== Live 쇼 ===",
      "dividerLiveJudge": "=== Live 승패 확인 페이즈 ===",
      "dividerTurnBegin": "=== 턴 {turn} 시작 ===",
      "dividerTurn": "--- 턴 {turn} ---",
      "hasNoValidLive": " 유효한 Live 카드가 없습니다!",
      "disconnectedWin": "{loser}의 연결이 끊겼습니다. {winner} 승리!",
      "chooseSuccessLive": " — 성공 Live에 놓을 Live 카드를 선택하세요.",
      "scoreTiedBlocked": " — 스코어 동점, 성공 Live 불가, Live 카드가 대기실로 보내짐.",
      "scoreTiedCap": " — 스코어 동점이지만 이미 성공 Live 2개를 보유해 Live 카드가 대기실로 보내짐."
    },
    "win": {
      "youWin": "승리!",
      "youLose": "패배!",
      "playAgain": "다시 플레이",
      "returnMenu": "메뉴로 돌아가기",
      "viewLeaderboard": "리더보드 보기",
      "resigned": "기권함",
      "conceded": "경기를 포기했습니다.",
      "oppResigned": "{name}이(가) 기권했습니다.",
      "threeLives": "{name}이(가) 성공 Live 3개를 달성했습니다!",
      "findAnother": "다른 경기 찾기",
      "disconnectedYou": "경기에서 연결이 끊겼습니다.",
      "disconnectedOpp": "{name}의 연결이 끊겼습니다.",
      "statsLine": "턴: {turn} | 내 성공: {yours}/3 | 상대 성공: {opp}/3",
      "debugSaveReplay": "💾 리플레이 저장",
      "saveReplay": "리플레이 저장",
      "saveReplayToLibrary": "라이브러리에 리플레이 저장",
      "downloadReplayJson": "리플레이 JSON 다운로드",
      "debugSaveLog": "💾 디버그 로그 저장",
      "debugCopyLog": "📋 로그 복사",
      "debugSaveBundle": "📦 디버그 묶음 내보내기",
      "rankedPrDailyCap": "오늘의 랭크 PR 보상 한도 소진 ({limit}/일 JST)",
      "rankedPrDupe": "{name} → Star Gem {gems}개로 변환 (보유 한도 초과)",
      "rankedPrNew": "랭크 승리 보상: {name}",
      "rankedPrPopupTitle": "랭크 승리 보상!",
      "rematchAccept": "재대결 수락",
      "rematchOffer": "재대결",
      "rematchOppWants": "{name} 님이 재대결을 원합니다!",
      "rematchWaiting": "대기 중…",
      "rematchWaitingHint": "상대가 재대결을 수락하기를 기다리는 중입니다.",
      "spectatorStatsLine": "턴: {turn} | {p1}: {p1Lives}/3 | {p2}: {p2Lives}/3",
      "spectatorWinner": "{name} 승리!"
    },
    "replay": {
      "menuTitle": "리플레이 뷰어",
      "menuSubAuth": "저장된 경기 리플레이를 불러와 실시간으로 시청하세요",
      "menuSubHub": "라이브러리에 저장된 경기 리플레이를 시청하세요",
      "title": "리플레이 뷰어",
      "back": "← 뒤로",
      "lead": "계정 라이브러리에 저장된 경기 리플레이를 시청하세요. 재생은 저장된 액션 타이밍을 따르며, 탐색 바로 리플레이의 어느 지점으로든 이동할 수 있습니다.",
      "refreshLibrary": "라이브러리 새로고침",
      "importLead": "내보낸 리플레이 파일이 있나요? 보조 방법으로 여기서 JSON을 가져올 수 있습니다.",
      "fileLabel": "리플레이 파일",
      "noFileSelected": "선택된 파일이 없습니다.",
      "startImported": "가져온 리플레이 시작",
      "playPause": "재생 / 일시정지",
      "positionAria": "리플레이 위치",
      "handoffNote": "리플레이 종료 — 이제부터 직접 조작합니다. CPU가 상대를 플레이합니다.",
      "exitReplay": "리플레이 종료",
      "phaseBarHint": "리플레이 {step} / {total} — 아래 리플레이 바로 기록된 액션을 넘겨보세요.",
      "signInLibrary": "리플레이를 저장하고 라이브러리에서 보려면 로그인하세요.",
      "emptyLibrary": "아직 저장된 리플레이가 없습니다. 경기를 마친 뒤 리플레이 저장을 선택하세요.",
      "watch": "시청",
      "downloadJson": "JSON 다운로드",
      "loadingLibrary": "저장된 리플레이 불러오는 중...",
      "loadLibraryFailed": "저장된 리플레이를 불러올 수 없습니다.",
      "win": "승리",
      "loss": "패배",
      "replayLabel": "리플레이",
      "resultAs": "{name}(으)로 {result}",
      "summarySaved": "저장: {date}",
      "summaryRoom": "방 {room}",
      "summaryVs": "vs {name}",
      "summaryTurn": "턴 {turn}",
      "summaryActions": "액션 {count}개",
      "summaryActionsPlural": "액션 {count}개",
      "metaSaver": "저장자: {name}",
      "metaPerspective": "관점: {id}",
      "metaSavedAt": "저장 시각: {at}",
      "metaSnapshot": "스냅샷: 턴 {turn}, 페이즈 {phase}",
      "metaDuration": "재생 시간: {duration}",
      "metaActions": "액션: {count}",
      "unknownDate": "알 수 없는 날짜",
      "loadedToast": "리플레이 로드됨 — 액션 {count}개",
      "downloadedJson": "리플레이 JSON 다운로드됨",
      "downloadFailed": "리플레이를 다운로드할 수 없습니다",
      "payloadMissing": "리플레이 데이터가 없습니다",
      "startSavedFailed": "저장된 리플레이를 시작할 수 없습니다",
      "unsupportedSchema": "지원되지 않는 리플레이 형식입니다",
      "invalidFile": "유효하지 않은 리플레이 파일입니다",
      "chooseFileFirst": "먼저 리플레이 파일을 선택하세요.",
      "saveAfterFinish": "리플레이 저장은 경기가 끝난 후에 가능합니다.",
      "noCredentials": "리플레이를 내보낼 경기 인증 정보를 찾을 수 없습니다.",
      "savedToLibrary": "리플레이가 라이브러리에 저장되었습니다",
      "savedToLibraryId": "리플레이가 라이브러리에 저장되었습니다 (#{id})",
      "downloadedAsJson": "리플레이가 JSON으로 다운로드되었습니다",
      "couldNotSave": "리플레이를 저장할 수 없습니다",
      "autosavedRecent": "리플레이가 「최근」에 자동 저장됨",
      "emptyRecent": "최근 자동 저장이 없습니다. 로그인 상태로 매치를 끝내면 여기에 쌓입니다.",
      "emptySaved": "영구 저장이 없습니다. 결과 화면에서 「리플레이 저장」하거나 「최근」에서 「영구 보관」하세요.",
      "preserve": "영구 보관",
      "preservedToast": "리플레이를 「영구 보관」으로 이동했습니다",
      "recentHint": "최근 10경기에서 자동 저장됩니다. 가장 오래된 항목부터 교체됩니다.",
      "recentSection": "최근 매치",
      "savedHint": "결과 화면에서 저장하거나, 「최근」 리플레이를 여기서 영구 보관합니다.",
      "savedSection": "영구 보관"
    },
    "apiError": {
      "titleClient": "문제가 발생했습니다",
      "titleServer": "서버 오류",
      "hintClient": "게임이 멈춘 것처럼 보이면 페이지를 새로고침해 보세요.",
      "hintServer": "페이지를 새로고침해 보세요. 계속 발생하면 잠시 기다린 뒤 다시 시도하세요.",
      "connectionFailed": "서버에 연결할 수 없습니다. 페이지를 새로고침해 보세요."
    },
    "tutorialMeta": {
      "title": "초보자 튜토리얼",
      "labelOpponent": "플레이어2"
    },
    "tutorial": {
      "exitTitle": "타이틀로 나가기",
      "back": "← 뒤로",
      "next": "다음 →",
      "finish": "완료",
      "intro_welcome": "안녕하세요! 저는 **시부야 카논**이에요. **Love Live! Official Card Game** 튜토리얼에 오신 걸 환영해요!",
      "intro_what": "이 게임은 **스쿨 아이돌**을 주제로 한 **2인용** 카드 게임이에요! **멤버**를 스테이지에 영입하고 **에너지**를 관리하며 **Live**를 성공시켜 상대를 앞서 나가요.",
      "intro_goal": "**승리 조건:** 상대보다 먼저 **Live**를 **3번 성공**시키는 거예요. **Live**가 성공하면 그 카드는 **성공 Live 더미**로 이동해요 — 먼저 3개를 채우는 쪽이 승리해요!",
      "intro_decks": "이 게임에는 세 종류의 카드가 있어요. **멤버** 카드, **Live** 카드, **에너지** 카드예요. 각 플레이어는 **60**장의 **메인 덱**(**멤버** 카드 **48**장, **Live** 카드 **12**장)과 **12**장의 **에너지** 카드로 이루어진 **에너지 덱**을 가져요.",
      "intro_card_member": "**멤버 카드**는 스테이지에서 활약할 아이돌이에요. 손패에서 플레이할 때 코스트만큼 **에너지**를 지불해요. 각 멤버는 라이브를 진행할 때 사용하는 색깔별 **하트**(세워진 모양)를 가지고 있어요. **Blade**(둥근 펜라이트 아이콘)와 **Blade 하트**(옆으로 누운 하트)도 있지만, 지금은 세워진 하트에 집중할게요. 여기 시키는 **보라색 하트 1개**를 가지고 있어요.",
      "intro_card_live": "**Live 카드**는 여러분이 공연할 곡이에요. 한 번에 최대 3장까지 낼 수 있어요. Live는 스테이지에 배치한 **멤버** 카드로 클리어해요 — 자세한 내용은 나중에 다룰게요.",
      "intro_card_energy": "**에너지 덱**의 **에너지 카드**는 여기에 놓여요. 처음에는 **에너지 3장**으로 시작해서 매 턴 **+1**씩 얻어요(**에너지 12장**이 모두 필드에 나올 때까지). 에너지는 **멤버 카드**를 **스테이지**에 놓을 때 소비돼요.",
      "intro_demo": "이제 데모로 안내할게요 — 플레이매트 위의 **Liella!** vs **μ's**예요. 여러분은 아래쪽, 상대는 위쪽에 있어요.",
      "intro_deck_piles": "위쪽 더미가 카드를 드로우하는 **메인 덱**이에요. 그 아래는 **에너지 덱**이에요.",
      "intro_stage": "**스테이지**(왼쪽 / 센터 / 오른쪽)는 멤버가 자리하는 곳이에요. 이들의 **하트** 색과 **Blade** 값이 퍼포먼스 중 라이브를 뒷받침해요.",
      "intro_live": "**라이브 스토리지**는 라이브 페이즈 동안 최대 3장의 카드를 뒷면으로 보관해요. 이 웹 버전에서는 자신의 카드는 보이지만, 상대 카드는 숨겨져요.",
      "intro_success": "**Live**를 완료하면 그 Live 카드가 **성공 더미**로 이동해요! 승리까지 얼마나 남았는지 여기서 확인하세요!",
      "intro_wr": "**대기실**은 카드를 버리는 곳이에요.",
      "intro_hands": "평소에는 상대의 손패가 보이지 않지만, 이 튜토리얼에서는 볼 수 있어요. 손패는 덱에서 뽑은 **멤버**와 **Live** 카드로 이루어져요.",
      "setup_coin": "게임을 시작하기 전, **코인 플립**으로 승자를 정해요 — 승자가 선공을 **선택**해요. 모든 경기 시작 시 이 과정을 확인하세요!",
      "setup_coin_p1": "...**Liella**가 선공이에요!",
      "setup_coin_p2": "이제 우리의 **시작 패**를 볼 수 있어요!",
      "setup_mulligan": "**6**장으로 시작해요. 뽑은 카드가 마음에 들지 않으면 이 화면에서 원하는 만큼 카드를 교체하고 새로 뽑을 수 있어요(이걸 **멀리건**이라고 불러요).",
      "setup_mull_p1": "게임 진행: **메인 페이즈** -> **Live 페이즈** -> **퍼포먼스 페이즈** -> 반복.",
      "setup_mull_p2": "**메인 페이즈!** 덱에서 새 카드를 한 장 뽑았어요.",
      "t1_structure": "각 **메인 페이즈**에서는 선공 플레이어가 먼저 행동하고, 그다음 후공이 행동해요 — 여기서 멤버를 내고... 스킬도 사용해요. 행동이 끝나면 여기서 **메인 페이즈 종료**를 누르세요.",
      "t1_energy_refresh": "새로운 턴이 시작되면 **에너지 +1**을 얻어요. **에너지 12장**이 모두 필드에 나올 때까지 매 턴 **1**씩 계속 얻어요.",
      "t1_main_p1": "Liella의 **메인 페이즈** — 먼저 멤버 카드를 내 볼까요!",
      "t1_play_shiki_plain": "**에너지 2**를 써서 이 카드를 내고 스테이지의 빈 자리에 놓아요! (사용한 에너지는 옆으로 눕혀요)",
      "t1_no_skill": "이제 스테이지 센터에 멤버가 한 명 있어요. 카드를 더 낼 에너지가 없다면 메인 페이즈를 종료할 수 있어요.",
      "t1_end_main_p1": "Liella가 메인 페이즈를 종료해요 — 이제 상대가 카드를 낼 차례예요!",
      "t1_main_p2": "μ's가 **호시조라 린**을 스테이지에 내요 — **핑크색 하트 1개**를 가지고 있어요.",
      "t1_hearts": "여기서 나와 상대의 **스테이지**에 있는 활성 카드의 **하트**와 **Blade** 총합을 확인할 수 있어요!",
      "t1_end_main_p2": "두 플레이어가 모두 메인 페이즈를 마치면 **Live 페이즈**가 시작돼요!",
      "t1_live_intro": "**Live 카드 스토리지**에 Live 또는 멤버 카드를 0~3장 놓아요. 놓은 카드마다 덱에서 새 카드를 1장 뽑아요. Live 스토리지에 놓인 멤버 카드는 다음 페이즈에서 버려져요 — 이 방법으로 원하지 않는 카드를 교체할 수 있어요!",
      "t1_live_p1": "Live 페이즈에서 **Live** 카드를 세팅하면 같은 턴 안에 반드시 시도해야 하니 신중하게 골라야 해요! Liella가 **WE WILL!!**을 세팅해요 — 성공하려면 **빨간색** 하트 1개, **보라색** 하트 1개, 그리고 **아무 색**이나 되는 하트 1개(회색 하트로 표시)가 필요해요.",
      "t1_live_p1_lock": "Liella가 **Live 페이즈**를 종료해 선택을 확정해요. 메인 페이즈와 달리, 나와 상대의 Live 페이즈는 동시에 진행돼요. 상대의 Live 페이즈가 아직 끝나지 않았다면 상대가 마칠 때까지 기다려야 해요.",
      "t1_live_p2": "μ's가 **Live**를 뒷면으로 스토리지에 세팅해요 — 무엇인지는 **퍼포먼스 페이즈**에서 확인할 수 있어요.",
      "t1_live_p2_lock": "μ's가 선택을 확정해요.",
      "t1_perf_intro": "두 플레이어 모두 Live 페이즈를 마치면 — **퍼포먼스**가 시작돼요! Live를 세팅했다면 **LIVE START** 화면이 나타나요! 여기서 Live의 성공 여부가 결정돼요.",
      "t1_hearts_check": "여기서 **스테이지**의 카드가 이 Live의 하트 조건을 충족하는지 확인할 수 있어요. **와카나 시키**는 **보라색 하트 1개**만 낼 수 있어서 아직 **빨간색 하트 1개**와 **아무 색 하트 1개**가 부족해요.",
      "t1_hearts_grey": "**회색 / 아무 색** 하트는 **아무 색**으로 취급돼요 — 빨간색과 보라색이 채워지면 남은 하트로 **WE WILL!!**의 '아무 색' 칸을 채울 수 있어요.",
      "t1_yell": "Live가 실패할 것처럼 보여도 아직 끝난 게 아니에요… 여기서 **Yell**이 등장해요. 카드의 **Blade** 값을 사용하는데, Liella!가 'Yell'을 진행해 스테이지의 총 **Blade** 값만큼 덱에서 추가로 카드를 드로우해요. **와카나 시키**는 **Blade 2**를 가지고 있어서 덱에서 2장을 뽑아요!",
      "t1_yell_hearts": "나츠미의 카드로 2가 추가됐어요! Yell로 뽑은 카드는 옆으로 누운 하트(**Blade 하트**)를 총합에 더해요. **빨간색 하트** 2개면 빨간색 1개와 아무 색 1개를 모두 채울 수 있어요. **Live가 성공해요!**",
      "t1_success": "**Yell**은 관객의 응원이라고 생각하면 돼요 — 까다로운 하트 조건을 채우는 데 핵심이에요. 스테이지의 **Blade** 값을 잘 살펴보세요 — **WE WILL!!** Live가 **성공**했어요!",
      "t1_yell_opp": "이어서 μ's의 **Yell** — 스테이지의 **Blade 1**로 **1**장을 뒤집어요. **니코**에게는 Blade 하트가 없어서 **키토 세이슌 가 키코에루**는 아직 성공할 수 없어요.",
      "t1_fail": "μ's는 **키토 세이슌 가 키코에루**의 하트 조건을 채우지 못했어요 — 그 **Live**는 **대기실**로 가요.",
      "t1_judge": "Liella!가 스코어로 승리하며 **성공 Live**를 획득해요!",
      "t1_end": "턴 1 완료 — 멤버를 플레이하고 Live를 세팅했으며, 퍼포먼스 중 **하트 맞추기**를 배웠어요.",
      "t2_start": "**턴 2** — 손패에 카드를 한 장 뽑고 에너지 +1을 얻어요.",
      "t2_skill_intro": "지금까지 낸 카드들은 **하트**와 **Blade**만 제공했지만, 어떤 카드는 게임에 다양한 영향을 주는 **스킬**도 가지고 있어요. 이 카드를 보세요, 스킬 텍스트가 있어요.",
      "t2_skill_preview": "이건 **[등장 시]** 스킬이에요. 이 카드가 손패에서 스테이지로 들어올 때 어떤 일이 일어난다는 뜻이에요.",
      "t2_play_shiki_skill": "Liella가 시키를 오른쪽 슬롯에 내요. 잘 보세요 — 게임이 시키의 **등장 시** 효과를 사용할지 물어볼 거예요.",
      "t2_on_enter_offer": "스킬에 \"해도 된다\"라고 쓰여 있으면 발동이 선택 사항이라는 뜻이라, 효과를 건너뛸 수도 있어요. Liella는 스킬을 **발동**하기로 해요. **에너지 1**을 지불한 뒤, Liella는 덱 맨 위에서 새 카드를 골라 손패에 추가할 수 있어요!",
      "t2_on_enter_confirm": "이제 어떤 카드를 남길지 골라요. Liella는 **1장**을 남기고 나머지는 **대기실**로 보내요.",
      "t2_on_enter_result": "시키의 스킬이 해결돼요 — 한 장은 Liella의 손패에 들어가고, 두 장은 **대기실**로 가요. 이게 **[등장 시]** 스킬이 작동하는 모습이에요.",
      "t2_end_p1": "Liella가 메인을 종료해요.",
      "t2_main_p2": "μ's가 감당할 수 있는 멤버를 내서 하트를 추가해요.",
      "t2_end_p2": "μ's가 메인을 종료해요.",
      "t2_live_skill_intro": "Live 카드도 스킬을 가질 수 있어요! 일부는 **[라이브 개시]**를 가지고 있는데, 그 Live의 퍼포먼스가 시작될 때 발동해요.",
      "t2_live_p1": "Liella가 Live 카드를 세팅해요.",
      "t2_live_p1_lock": "확정했어요.",
      "t2_live_p2": "μ's가 **START:DASH!!**를 뒷면으로 세팅해요.",
      "t2_live_p2_lock": "μ's가 선택을 확정해요.",
      "t2_perf_intro": "둘 다 확정했어요 — 다시 **퍼포먼스**예요! Live가 다시 공개돼요.",
      "t2_live_start_offer": "**[라이브 개시]** — **START:DASH!!** 덕분에 μ's는 덱 맨 위 **3장을 확인**하고 퍼포먼스를 이어가기 전에 순서를 바꿀 수 있어요. 이 프롬프트가 **[라이브 개시]** 효과를 보여주는 예시예요.",
      "t2_yell_mine": "먼저 여러분의 **Yell** — 스테이지의 **Blade 3**으로 **3**장을 뒤집어요. **렌**, **케케**, **메이**가 나왔지만 아무도 **Blade 하트**가 없어요 — **Watashi no Symphony ~Shibuya Kanon Ver.~**는 **빨간색 4**, **보라색 4**, **아무 색 3**이 필요해서 그 **Live**는 **실패**해요.",
      "t2_yell_opp": "이어서 μ's의 **Yell** — 첫 번째 카드는 **Korekara no Someday**로 **모든 색**의 **Blade 하트**를 가지고 있어요(아무 색으로 취급되어 **START:DASH!!**의 부족한 부분 — 여기서는 **노란색** — 을 채워요). 두 번째인 **린**은 Blade 하트가 없어요.",
      "t2_outcomes": "두 Live 모두 해결됐어요 — 성공한 쪽은 성공 더미에 남고, 실패한 쪽은 **대기실**로 가요.",
      "t2_judge": "**μ's**만 **START:DASH!!**을 성공시켰어요 — 여러분의 Live는 실패해서 **Watashi no Symphony ~Shibuya Kanon Ver.~**는 **대기실**로 가요. μ's가 **성공 Live**를 획득해요!",
      "t3_start": "**턴 3** — 지난 퍼포먼스에서 유일한 성공 Live를 차지했기 때문에 이번 턴은 μ's가 선공이에요.",
      "t3_main_p2": "μ's가 감당할 수 있는 멤버를 내서 하트를 추가해요.",
      "t3_p2_end": "μ's가 메인을 종료해요.",
      "t3_turn": "**턴 3** — 여러분의 메인 페이즈예요. 카드를 한 장 뽑고 **에너지 +1**을 얻었어요 (스토리지에 **6**).",
      "t3_baton_intro": "이제 **바톤 터치**라는 또 다른 메커니즘을 설명할게요. 스테이지에 이미 있는 카드 위에 다른 카드를 내면 기존 카드를 새 카드로 **교체**할 수 있어요. 바톤 터치를 할 때는 교체된 멤버의 코스트를 대기실로 보내며 이미 지불한 것으로 취급되어, **차액**만큼의 에너지만 지불해요.",
      "t3_baton_example": "**요네메 메이**의 코스트는 **7**이에요 — 보통은 손패에서 **에너지 7**이 들지만, **오른쪽**의 **시키**(코스트 **4**) 위로 바톤 터치하면 **3**만 들어요(7−4). **시키**는 **센터**에 남고(**Blade 2**), **오른쪽**의 **메이**가 **빨간색 1**과 **보라색 2**를 추가해요 — **Live 페이즈**에서 손패의 **Mirai wa Kaze no You ni**를 세팅할 예정인데, **Yell**이 오기 전까지 스테이지 하트가 1개 부족해요.",
      "t3_baton_play": "Liella가 메이로 **오른쪽**에 **바톤 터치**를 해요!",
      "skill_glossary_intro": "지금까지 몇 가지 스킬 타이밍을 직접 봤어요. 카드에서 자주 보게 될 공통 **키워드**를 정리했어요:",
      "skill_on_enter": "**[등장 시]** — 멤버가 손패에서 스테이지로 플레이될 때 한 번 발동해요(방금 시키처럼요). 대부분 *해도 된다*라고 쓰여 있어서 선택 사항이에요.",
      "skill_live_start": "**[라이브 개시]** — 그 카드로 Live 퍼포먼스가 시작될 때 발동해요(START:DASH처럼요). 이 역시 대부분 선택 사항이에요.",
      "skill_activated": "**[기동]** — 메인 페이즈 중 **발동 가능한 스킬** 아래의 버튼을 사용해요. **키나코**처럼 스테이지를 떠나 **대기실**의 **Live**를 손패에 추가할 수 있는 멤버도 있어요.",
      "skill_wr_note": "일부 **[기동]** 스킬은 멤버가 **대기실**에 있을 때만 작동해요 — 목록에서 이름 앞에 **대기 ·**가 표시돼요. 스테이지 스킬은 대신 슬롯이 표시돼요.",
      "skill_always": "**[상시]** / **[자동]** — 조건이 충족되는 동안 계속 적용되며, 누를 버튼이 없어요. **자동**은 어떤 일이 일어나면 스스로 발동해요.",
      "skill_once": "**[턴에 1회]** — 코스트를 다시 지불할 수 있어도 각 턴에 한 번만 사용할 수 있어요.",
      "skill_center": "**[센터]** — 그 멤버가 스테이지의 센터 슬롯에 있을 때만 작동해요.",
      "skill_on_leave": "**[퇴장 시]** — 멤버가 스테이지를 떠날 때 발동해요(바톤 터치, 제거 효과 등).",
      "t3_stage_hearts": "**왼쪽**이 비어 있으니 **Live 페이즈**에서 손패의 **Mirai wa Kaze no You ni**를 세팅해요. **센터**의 **시키**와 **오른쪽**의 **메이**가 하트를 일부 제공하지만, **Mirai wa Kaze no You ni**는 아직 하트가 더 필요해서 **Yell**로 채워지길 바라야 해요. **Mirai wa Kaze no You ni**의 스킬로 **Yell** 하트가 **아무** 색으로 취급되니 성공 확률이 올라가요.",
      "t3_end_p1": "Liella가 메인을 종료해요.",
      "t3_live1": "Liella가 **손패**에서 **Mirai wa Kaze no You ni**를 세팅해요.",
      "t3_live1_lock": "Liella가 선택을 확정해요.",
      "t3_live2": "μ's가 **START:DASH!!**를 뒷면으로 세팅해요.",
      "t3_live2_lock": "μ's가 선택을 확정해요 — 마지막 **퍼포먼스**예요!",
      "t3_perf_intro": "둘 다 확정했어요 — 마지막 **퍼포먼스**예요!",
      "t3_yell_mine": "먼저 μ's의 **Yell** — **Blade 하트**를 위해 덱을 뒤집어요. **START:DASH!!**는 이미 스테이지와 맞아요(**린 호시조라**, **호노카**, **우미**의 **핑크색**, **노란색**, **보라색**).",
      "t3_yell_opp": "이어서 여러분의 **Yell** — **센터**의 **시키 와카나**(**Blade 2**)와 **오른쪽**의 **메이 요네메**(**Blade 1**)가 **3**장을 뒤집어요. **Mirai wa Kaze no You ni**는 그 Yell 하트를 **아무** 색으로 취급해요 — 스테이지와 함께 **Live**가 **성공**해요!",
      "t3_outcomes": "두 Live 모두 **성공**했어요! 이 경우에는 동점자 결정으로 승자를 가려요 — **Live 심판**의 시간이에요!",
      "t3_judge": "**Live 심판** — 강화된 스코어로 μ's가 승리해요! 경기 승리에 한 걸음 더 가까워진 또 하나의 **성공 Live**예요.",
      "outro": "매 턴은 **메인 → 라이브 → 퍼포먼스** 순이에요. 준비가 되면 **CPU 연습**을 해 보세요 — 스킬과 바톤 터치는 플레이하면서 자연스럽게 배워요.",
      "outro_link": "전체 규칙: llofficial-cardgame.com/rule/ — 행운을 빌어요!",
      "speaker": "시부야 카논",
      "welcome": "안녕하세요! 저는 **시부야 카논**이에요. **Love Live! Official Card Game**에 오신 걸 환영해요! 플레이하면서 기본을 알려드릴게요.",
      "goal": "**승리 조건:** 상대보다 먼저 **라이브**를 **3번 성공**시키는 거예요. 라이브가 성공하면 그 카드는 **성공** 더미로 이동해요 — 먼저 3개를 채우는 쪽이 승리해요!",
      "card_types": "이 게임에는 세 종류의 카드가 있어요. **멤버** 카드, **라이브** 카드, **에너지** 카드예요. 멤버의 코스트만큼 **에너지**를 지불해 손패에서 내요.",
      "coin": "게임이 시작되기 전, **코인 플립**으로 승자를 정해요 — 승자가 선공을 **선택**해요. 플립을 지켜보세요!",
      "choose_first": "코인 플립에서 이겼어요! **선공**을 고르세요 — **선공하기**를 눌러 먼저 플레이해요.",
      "mulligan": "**6**장으로 시작해요. 마음에 들지 않는 카드가 있으면 탭해서 교체 표시를 하고 새로 뽑을 수 있어요 — 이게 **멀리건**이에요. **시부야 카논**은 코스트 **9**라 초반에는 부담스러워요. 그녀를 탭한 뒤 **1장 교체**를 누르세요.",
      "main_intro": "**메인 페이즈** — 손패의 **멤버**를 **에너지**를 써서 내요(에너지 카드는 옆으로 눕혀요). 원하는 순서대로, 원하는 만큼 플레이할 수 있어요!",
      "play_member": "**와카나 시키**를 **센터**에 내 볼까요 — 코스트는 **에너지 2**예요. 손패에서 탭/클릭한 뒤 **센터** 슬롯을 탭하거나, 마우스·터치로 센터에 드래그하세요.",
      "energy_tip": "사용한 에너지는 **옆으로** 눕혀져요. 스테이지의 멤버는 라이브를 할 때 필요한 **하트**를 보여 줘요!",
      "end_main": "메인 페이즈에서 할 일을 마치면 **메인 페이즈 종료**를 누르세요.",
      "live_intro": "**라이브 페이즈** — 라이브 스토리지에 **0~3**장을 뒷면으로 놓아요. 놓은 장수만큼 덱에서 **1**장씩 뽑아요.",
      "set_live": "손패에서 **WE WILL!!**을 고른 뒤 **라이브 선택 확정**을 눌러 뒷면으로 놓아요. 라이브를 1장 세팅할 때마다 **1**장을 뽑아요!",
      "perf_watch": "**퍼포먼스!** 라이브가 앞면으로 공개돼요. 각 라이브의 **필요 하트**를 스테이지 하트로 맞추세요. **Yell**로 덱에서 카드를 뒤집으면 — 그 카드의 **Blade** 아이콘으로 하트를 채울 수 있어요!",
      "success": "성공한 라이브는 **성공** 더미로 가요! **라이브 3회** 성공하면 승리예요. 양쪽 모두 성공하면 **스코어**가 높은 쪽이 그 라운드를 가져가요."
    },
    "mobile": {
      "rotateTitle": "이 게임은 가로 화면으로 플레이합니다",
      "rotateSub": "계속하려면 기기를 회전하세요."
    },
    "common": {
      "loading": "불러오는 중…",
      "back": "← 뒤로",
      "hubBack": "← 허브",
      "confirm": "확인",
      "cancel": "취소",
      "copy": "복사",
      "load": "불러오기",
      "preview": "미리보기",
      "menu": "메인 메뉴",
      "seconds": "{n}초",
      "ok": "확인"
    },
    "toast": {
      "reconnected": "게임에 다시 연결됨",
      "leftActiveMatch": "진행 중인 경기에서 나감",
      "noActiveMatch": "진행 중인 경기를 찾을 수 없음",
      "noCardId": "복사할 카드 ID가 없음",
      "cardIdCopied": "카드 ID 복사됨",
      "couldNotCopyCardId": "카드 ID를 복사할 수 없음",
      "signInDeckBuilder": "덱 빌더를 사용하려면 로그인하세요.",
      "signOutDeckExperiment": "덱 실험을 사용하려면 로그아웃하세요.",
      "rankedMatchFound": "랭크 매칭 성사!",
      "casualMatchFound": "캐주얼 매칭 성사!",
      "passwordCopied": "비밀번호 복사됨",
      "copyFailed": "복사 실패",
      "copied": "복사됨! 📋",
      "liveOnly": "Live 스토리지에는 Live 또는 멤버 카드만 놓을 수 있습니다",
      "onlyLiveOrMember": "Live 스토리지에는 Live 또는 멤버 카드만 놓을 수 있습니다",
      "maxLiveCards": "Live 스토리지 최대 {slots}장",
      "maxLiveCardsPlural": "Live 스토리지 최대 {slots}장",
      "maxLiveStorage": "Live 스토리지 최대 {slots}장",
      "maxLiveStoragePlural": "Live 스토리지 최대 {slots}장",
      "liveStorageFull": "Live 스토리지가 가득 찼습니다",
      "logCopied": "로그 복사됨",
      "couldNotCopyLog": "로그를 복사할 수 없음",
      "resolveSkillFirst": "계속하려면 먼저 대기 중인 스킬을 해결하세요."
    },
    "tutorialUi": {
      "exitTitle": "타이틀로 나가기",
      "back": "← 뒤로",
      "next": "다음 →",
      "finish": "완료"
    },
    "spectate": {
      "listTitle": "경기 관전",
      "listTitleRanked": "랭크 경기 관전",
      "listTitleCasual": "일반전 경기 관전",
      "lead": "진행 중인 경기를 관전하세요 — 보기만 가능하며 상호작용은 불가합니다.",
      "barReadOnly": "관전 중 — 읽기 전용",
      "barNames": "관전 중 {p1} vs {p2}",
      "leave": "나가기",
      "reconnected": "관전에 다시 연결됨.",
      "watch": "관전",
      "loading": "불러오는 중…",
      "noMatches": "관전할 수 있는 경기가 없습니다.",
      "count": "관전자: {n}",
      "matchEnded": "매치가 종료되어 로비로 돌아갑니다.",
      "sessionEnded": "관전 세션이 종료되었습니다.",
      "switchPerspective": "시점 전환"
    },
    "missions": {
      "claim": "수령",
      "claimedStarterToast": "{title} 수령 — {deck} 해금",
      "claimedToast": "{title} 수령 (+{reward})",
      "completeToast": "미션 완료: {title}",
      "daily": {
        "completeAll": "일일 미션을 모두 완료",
        "openAllBoosters": "일일 부스터 팩을 모두 개봉",
        "rankedMatch": "랭크 매치 1회 플레이",
        "useStamp": "매치 중 스탬프 사용"
      },
      "empty": "이 탭에 미션이 없습니다.",
      "loading": "미션 불러오는 중…",
      "milestone": {
        "cards1200": "카드 1,200장 보유",
        "cards1600": "카드 1,600장 보유",
        "cards400": "카드 400장 보유",
        "cards800": "카드 800장 보유",
        "profileBanner": "프로필 배너 변경",
        "profileFlag": "프로필에 국기 설정",
        "profileStamps": "즐겨찾는 스티커 설정",
        "ranked1": "랭크 매치 1회 플레이",
        "ranked10": "랭크 매치 10회 플레이",
        "ranked100": "랭크 매치 100회 플레이",
        "ranked1000": "랭크 매치 1,000회 플레이",
        "ranked5": "랭크 매치 5회 플레이",
        "ranked50": "랭크 매치 50회 플레이",
        "ranked500": "랭크 매치 500회 플레이",
        "unranked1": "언랭크 매치 1회 플레이",
        "winAqours": "Aqours 전용 메인 덱으로 승리",
        "winHasunosora": "하스노소라 전용 메인 덱으로 승리",
        "winLiella": "Liella! 전용 메인 덱으로 승리",
        "winMuse": "μ's 전용 메인 덱으로 승리",
        "winNijigasaki": "니지가사키 전용 메인 덱으로 승리"
      },
      "rewardStarter": "스타터 덱 선택",
      "rewardStarterOwned": "이미 보유 중",
      "starterPickCancel": "취소",
      "starterPickConfirm": "스타터 해금",
      "starterPickTitle": "스타터 덱 선택",
      "statusActive": "진행 중",
      "statusClaimed": "수령 완료",
      "statusLocked": "잠김",
      "statusReady": "수령 가능",
      "tabDaily": "일일",
      "tabMilestone": "마일스톤",
      "title": "미션"
    },
    "stamps": {
      "audio": "스탬프 오디오",
      "audioMenu": "스탬프 보이스 클립",
      "voiceVolume": "스탬프 보이스 음량",
      "cooldown": "잠시만 기다려 주세요…",
      "done": "완료",
      "editProfile": "즐겨찾는 스탬프 편집",
      "empty": "스탬프가 없습니다.",
      "favoritesEmpty": "즐겨찾기가 없습니다 — 옵션에서 설정하거나 스탬프의 ☆를 누르세요.",
      "pickerTitle": "스탬프 보내기",
      "profileCount": "{n} / {max} 선택됨",
      "profileFull": "즐겨찾는 스탬프는 최대 {max}개까지 저장할 수 있습니다.",
      "profileHint": "PvP에서 스탬프를 보낼 때 ★ 즐겨찾기 탭에 표시됩니다.",
      "profileHintEmpty": "선택 사항 — 매치에서 빠르게 쓰려면 스탬프를 최대 20개까지 고르세요.",
      "profilePickLead": "스탬프를 눌러 추가/제거 (최대 20). PvP의 ★ 즐겨찾기 탭에서 사용됩니다.",
      "profilePickTitle": "즐겨찾는 스탬프",
      "profileSection": "즐겨찾는 스탬프",
      "send": "💬 스탬프",
      "tabEn": "English",
      "tabFavorites": "★ 즐겨찾기",
      "tabJa": "日本語"
    },
    "ui": {
      "fullscreen": "전체 화면",
      "rankedSearch": "랭크 검색",
      "casualSearch": "캐주얼 검색",
      "skipToResults": "결과로 건너뛰기",
      "clickToOpen": "클릭하여 열기"
    },
    "cardType": {
      "member": "멤버",
      "live": "라이브",
      "energy": "에너지"
    }
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
    loc.hub.tournament = {
      title: loc.hub.tournamentMode || 'Tournament Mode',
      sub: loc.hub.tournamentModeSub || 'Coming Soon',
    };
    loc.auth.tournament = {
      title: loc.hub.tournament.title,
      sub: loc.hub.tournament.sub,
    };
    loc.hub.tutorial = loc.hub.tutorial || {};
    loc.hub.tutorial.title = loc.menu && loc.menu.howToPlay;
    loc.hub.tutorial.sub = loc.menu && loc.menu.howToPlaySub;
    loc.auth.replay = loc.auth.replay || {};
    loc.auth.replay.title = loc.replay && loc.replay.menuTitle;
    loc.auth.replay.sub = loc.replay && loc.replay.menuSubAuth;
    loc.hub.replay = loc.hub.replay || {};
    loc.hub.replay.title = loc.replay && loc.replay.menuTitle;
    loc.hub.replay.sub = loc.replay && loc.replay.menuSubHub;
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
  mergeLocaleAliases(STRINGS.es);
  mergeLocaleAliases(STRINGS.ko);
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
    try {
      if (document.body) {
        document.body.classList.remove('locale-ja', 'locale-ko');
        if (loc === 'ja' || loc === 'ko') document.body.classList.add('locale-' + loc);
      }
    } catch (e3) { /* ignore */ }
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
    if (val == null && (loc === 'ja' || loc === 'es' || loc === 'ko')) val = lookupPath(STRINGS.en, key);
    if (typeof val === 'string') return interpolate(val, vars);
    return key;
  }

  function applyI18n(root) {
    var el = root || document;
    if (!el || !el.querySelectorAll) return;

    el.querySelectorAll('[data-i18n]').forEach(function (node) {
      if (node.id === 'auth-status') {
        var boot = node.getAttribute('data-auth-boot') || 'pending';
        if (boot === 'pending' || boot === 'booting') return;
      }
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

    // Keep language picker closed-state flag + label aligned with active locale
    if (typeof LOCALE_PICKER_IDS !== 'undefined' && LOCALE_PICKER_IDS) {
      LOCALE_PICKER_IDS.forEach(function (id) {
        syncLocaleSelect(document.getElementById(id));
      });
    }
  }

  var KO_NAME_MAP = {
  "Ai Miyashita": "미야시타 아이",
  "Anju Yuuki": "유우키 안쥬",
  "Ayumu Uehara": "우에하라 아유무",
  "Ayumu Uehara & Kanon Shibuya & Kaho Hinoshita": "우에하라 아유무 & 시부야 카논 & 히노시타 카호",
  "Ceras Yanagida Lilienfeld": "세라스 야나기다 릴리엔펠트",
  "Chika Takami": "타카미 치카",
  "Chisato Arashi": "아라시 치사토",
  "Chisato Arashi ＆ Natsumi Onitsuka": "아라시 치사토 ＆ 오니츠카 나츠미",
  "Dia Kurosawa": "쿠로사와 다이아",
  "Eli Ayase": "아야세 에리",
  "Eli Ayase & Karin Asaka & Ren Hazuki": "아야세 에리 & 아사카 카린 & 하즈키 렌",
  "Emma Verde": "엠마 베르데",
  "Erena Todo": "토도 에레나",
  "Ginko Momose": "모모세 긴코",
  "Hanamaru Kunikida": "쿠니키다 하나마루",
  "Hanayo Koizumi": "코이즈미 하나요",
  "Hime Anyoji": "안요지 히메",
  "Honoka Kosaka": "코우사카 호노카",
  "Izumi Katsuragi": "카츠라기 이즈미",
  "Kaho Hinoshita": "히노시타 카호",
  "Kanan Matsuura": "마츠우라 카난",
  "Kanata Konoe": "코노에 카나타",
  "Kanon Shibuya": "시부야 카논",
  "Karin Asaka": "아사카 카린",
  "Kasumi Nakasu": "나카스 카스미",
  "Keke Tang": "탕 쿠쿠",
  "Kinako Sakurakoji": "사쿠라코지 키나코",
  "Kosuzu Kachimachi": "카치마치 코스즈",
  "Kotori Minami": "미나미 코토리",
  "Kotori Minami & Dia Kurosawa & Kosuzu Kachimachi": "미나미 코토리 & 쿠로사와 다이아 & 카치마치 코스즈",
  "Kozue Otomune": "오토무네 코즈에",
  "Lanzhu Zhong": "쇼우 란쥬",
  "Maki Nishikino": "니시키노 마키",
  "Mao Hiiragi": "히이라기 마오",
  "Mari Ohara": "오하라 마리",
  "Megumi Fujishima": "후지시마 메구미",
  "Mei Yoneme": "요네메 메이",
  "Mia Taylor": "미아 테일러",
  "Natsumi Onitsuka": "오니츠카 나츠미",
  "Nico Yazawa": "야자와 니코",
  "Nozomi Tojo": "토죠 노조미",
  "Ren Hazuki": "하즈키 렌",
  "Ria Kazuno": "카즈노 리아",
  "Riko Sakurauchi": "사쿠라우치 리코",
  "Rin Hoshizora": "호시조라 린",
  "Rina Tennoji": "텐노지 리나",
  "Ruby Kurosawa": "쿠로사와 루비",
  "Rurino Osawa": "오사와 루리노",
  "Sayaka Murano": "무라노 사야카",
  "Seira Kazuno": "카즈노 세이라",
  "Setsuna Yuki": "유키 세츠나",
  "Shiki Wakana": "와카나 시키",
  "Shioriko Mifune": "미후네 시오리코",
  "Shizuku Osaka": "오사카 시즈쿠",
  "Sumire Heanna": "헤안나 스미레",
  "Tomari Onitsuka": "오니츠카 토마리",
  "Tsubasa Kira": "키라 츠바사",
  "Tsuzuri Yugiri": "유기리 츠즈리",
  "Umi Sonoda": "소노다 우미",
  "Umi Sonoda & Yoshiko Tsushima & Rina Tennoji": "소노다 우미 & 츠시마 요시코 & 텐노지 리나",
  "Wien Margarete": "빈 마르가레테",
  "Yoshiko Tsushima": "츠시마 요시코",
  "You Watanabe": "와타나베 요우",
  "You Watanabe & Natsumi Onitsuka & Rurino Osawa": "와타나베 요우 & 오니츠카 나츠미 & 오사와 루리노",
  "Yuuna Seizawa": "히지리사와 유우나",
  "ウィーン・マルガレーテ": "빈 마르가레테",
  "エマ・ヴェルデ": "엠마 베르데",
  "セラス 柳田 リリエンフェルト": "세라스 야나기다 릴리엔펠트",
  "ミア・テイラー": "미아 테일러",
  "三船栞子": "미후네 시오리코",
  "上原歩夢": "우에하라 아유무",
  "上原歩夢&澁谷かのん&日野下花帆": "우에하라 아유무 & 시부야 카논 & 히노시타 카호",
  "中須かすみ": "나카스 카스미",
  "乙宗 梢": "오토무네 코즈에",
  "優木あんじゅ": "유우키 안쥬",
  "優木せつ菜": "유키 세츠나",
  "南 ことり": "미나미 코토리",
  "南 ことり&黒澤ダイヤ&徒町小鈴": "미나미 코토리 & 쿠로사와 다이아 & 카치마치 코스즈",
  "南ことり": "미나미 코토리",
  "唐 可可": "탕 쿠쿠",
  "国木田花丸": "쿠니키다 하나마루",
  "園田海未": "소노다 우미",
  "園田海未&津島善子&天王寺璃奈": "소노다 우미 & 츠시마 요시코 & 텐노지 리나",
  "夕霧綴理": "유기리 츠즈리",
  "大沢瑠璃乃": "오사와 루리노",
  "天王寺璃奈": "텐노지 리나",
  "安養寺 姫芽": "안요지 히메",
  "安養寺姫芽": "안요지 히메",
  "宮下 愛": "미야시타 아이",
  "小原鞠莉": "오하라 마리",
  "小泉 花陽": "코이즈미 하나요",
  "小泉花陽": "코이즈미 하나요",
  "嵐 千砂都": "아라시 치사토",
  "嵐 千砂都＆鬼塚夏美": "아라시 치사토 ＆ 오니츠카 나츠미",
  "平安名すみれ": "헤안나 스미레",
  "徒町 小鈴": "카치마치 코스즈",
  "徒町小鈴": "카치마치 코스즈",
  "日野下花帆": "히노시타 카호",
  "星空 凛": "호시조라 린",
  "星空凛": "호시조라 린",
  "朝香果林": "아사카 카린",
  "村野さやか": "무라노 사야카",
  "東條 希": "토죠 노조미",
  "松浦果南": "마츠우라 카난",
  "柊摩央": "히이라기 마오",
  "桂城 泉": "카츠라기 이즈미",
  "桜内梨子": "사쿠라우치 리코",
  "桜坂しずく": "오사카 시즈쿠",
  "桜小路きな子": "사쿠라코지 키나코",
  "津島善子": "츠시마 요시코",
  "渡辺 曜": "와타나베 요우",
  "渡辺 曜&鬼塚夏美&大沢瑠璃乃": "와타나베 요우 & 오니츠카 나츠미 & 오사와 루리노",
  "澁谷かのん": "시부야 카논",
  "百生 吟子": "모모세 긴코",
  "百生吟子": "모모세 긴코",
  "矢澤 にこ": "야자와 니코",
  "矢澤にこ": "야자와 니코",
  "米女メイ": "요네메 메이",
  "絢瀬 絵里": "아야세 에리",
  "絢瀬絵里": "아야세 에리",
  "絢瀬絵里&朝香果林&葉月 恋": "아야세 에리 & 아사카 카린 & 하즈키 렌",
  "統堂英玲奈": "토도 에레나",
  "綺羅ツバサ": "키라 츠바사",
  "聖澤悠奈": "히지리사와 유우나",
  "若菜四季": "와카나 시키",
  "葉月 恋": "하즈키 렌",
  "藤島 慈": "후지시마 메구미",
  "西木野 真姫": "니시키노 마키",
  "西木野真姫": "니시키노 마키",
  "近江彼方": "코노에 카나타",
  "鐘 嵐珠": "쇼우 란쥬",
  "高坂 穂乃果": "코우사카 호노카",
  "高坂穂乃果": "코우사카 호노카",
  "高海千歌": "타카미 치카",
  "鬼塚冬毬": "오니츠카 토마리",
  "鬼塚夏美": "오니츠카 나츠미",
  "鹿角理亞": "카즈노 리아",
  "鹿角聖良": "카즈노 세이라",
  "黒澤ダイヤ": "쿠로사와 다이아",
  "黒澤ルビィ": "쿠로사와 루비"
};
  var KO_SONG_MAP = {
  "365 Days": "365 데이즈",
  "?←HEARTBEAT": "?←하트비트",
  "A song for You! You? You!!": "어 송 포 유! 유? 유!!",
  "AURORA FLOWER": "오로라 플라워",
  "AWOKE": "어웨이크",
  "Aikotoba!": "아이코토바!",
  "Aishiteru Banzai!": "아이시테루 반자이!",
  "Ai♡Scream!": "아이♡스크림!",
  "Angelic Angel": "안젤릭 엔젤",
  "Aoku Haruka": "푸르게, 아득히",
  "Aozora Jumping Heart": "파란 하늘 점핑 하트",
  "Aspire": "어스파이어",
  "Atarayo Hanabi": "아타라요 하나비",
  "Awaken the Power": "어웨이큰 더 파워",
  "Awakening Promise": "어웨이크닝 프라미스",
  "Binetsu Kara Mystery": "미열에서 미스터리",
  "Birdcage": "버드케이지",
  "Bloom the smile, Bloom the dream!": "블룸 더 스마일, 블룸 더 드림!",
  "Blue Moment": "블루 모먼트",
  "Blue!": "블루!",
  "Bokura no Hashitte Kita Michi wa...": "우리가 달려온 길은...",
  "Bokura no LIVE Kimi to no LIFE": "우리의 LIVE, 너와의 LIFE",
  "Bokura wa Ima no Naka de": "우리들은 지금 속에서",
  "Bouken Type A, B, C!!": "모험 Type A, B, C!!",
  "Bring the LOVE!": "브링 더 러브!",
  "Bubble Rise": "버블 라이즈",
  "Butterfly": "버터플라이",
  "Butterfly Wing": "버터플라이 윙",
  "CHASE!": "체이스!",
  "COMPASS": "컴파스",
  "Cara Tesoro": "카라 테소로",
  "Chance Day, Chance Way!": "찬스 데이, 찬스 웨이!",
  "Colorful Dreams! Colorful Smiles!": "컬러풀 드림즈! 컬러풀 스마일즈!",
  "Cutie Panther": "큐티 팬서",
  "DAISUKI FULL POWER": "다이스키 풀 파워",
  "DIVE!": "다이브!",
  "DREAMY COLOR": "드리미 컬러",
  "Daisuki dattara Daijoubu!": "좋아한다면 괜찮아!",
  "Dakishimeru Hanabira": "끌어안은 꽃잎",
  "Dancing stars on me!": "댄싱 스타즈 온 미!",
  "Daydream Mermaid": "데이드림 머메이드",
  "Dazzling Game": "대즐링 게임",
  "Deep Resonance": "딥 레저넌스",
  "Diamond Princess no Yuuutsu": "다이아몬드 프린세스의 우울",
  "Distortion": "디스토션",
  "Do! Do! Do!": "두! 두! 두!",
  "Doko ni Itemo Kimi wa Kimi": "어디에 있어도 너는 너",
  "Dream Believers": "드림 빌리버즈",
  "Dream Believers (104th Ver.)": "드림 빌리버즈 (104th Ver.)",
  "Dream Believers (105th Ver.)": "드림 빌리버즈 (105th Ver.)",
  "Dream with You": "드림 위드 유",
  "Dreamin' Go! Go!!": "드리밍 고! 고!!",
  "EMOTION": "이모션",
  "Echoes Beyond": "에코즈 비욘드",
  "Edelied": "에델리드",
  "Egao no Promise": "미소의 프라미스",
  "Eternalize Love!!": "이터널라이즈 러브!!",
  "Eutopia": "유토피아",
  "Fanfare!!!": "팡파레!!!",
  "Fantastic Departure!": "판타스틱 디파처!",
  "Fusion Crust": "퓨전 크러스트",
  "GALAXY HidE and SeeK": "갤럭시 하이드 앤 시크",
  "Genki Zenkai DAY! DAY! DAY!": "원기전개 DAY! DAY! DAY!",
  "Genyou Yakou": "환야야행",
  "Go!! Restart": "고!! 리스타트",
  "HAPPY PARTY TRAIN": "해피 파티 트레인",
  "HOT PASSION!!": "핫 패션!!",
  "Hajimari wa Kimi no Sora": "시작은 너의 하늘",
  "Hanamusubi": "꽃의 매듭",
  "Holiday∞Holiday": "홀리데이∞홀리데이",
  "I Do Me!": "아이 두 미!",
  "Identity": "아이덴티티",
  "JIMO-AI Dash!": "지모아이 대시!",
  "Jellyfish": "젤리피쉬",
  "Joushou Kiryuu": "상승기류",
  "Jump Into the New World": "점프 인투 더 뉴 월드",
  "Jump up HIGH!!": "점프 업 하이!!",
  "Just Believe!!!": "저스트 빌리브!!!",
  "KOKORO Magic \"A to Z\"": "코코로 매직 \"A to Z\"",
  "Kaguya no Shiro de Odoritai": "카구야의 성에서 춤추고 싶어",
  "KiRa-KiRa Sensation!": "키라키라 센세이션!",
  "Kimi no Kokoro wa Kagayaiteru kai?": "너의 마음은 빛나고 있니?",
  "Kinmirai Happy End": "근미래 해피 엔드",
  "Kitto Seishun ga Kikoeru": "분명 청춘이 들려와",
  "Koi ni Naritai AQUARIUM": "사랑이 되고 싶어 AQUARIUM",
  "Kokon Touzai": "고금동서",
  "Korekara no Someday": "이제부터의 Someday",
  "Kowareyasuki": "부서지기 쉬운",
  "La Bella Patria": "라 벨라 파트리아",
  "Ladybug": "레이디버그",
  "Landing action Yeah!!": "랜딩 액션 예!!",
  "Let's be ONE": "렛츠 비 원",
  "Link to the FUTURE": "링크 투 더 퓨처",
  "Link to the FUTURE (104th Ver.)": "링크 투 더 퓨처 (104th Ver.)",
  "Live with a smile!": "라이브 위드 어 스마일!",
  "Love U my friends": "러브 유 마이 프렌즈",
  "Love Wing Bell": "러브 윙 벨",
  "MIRACLE NEW STORY": "미라클 뉴 스토리",
  "MIRACLE WAVE": "미라클 웨이브",
  "MIRAI TICKET": "미라이 티켓",
  "MONSTER GIRLS": "몬스터 걸즈",
  "MY Mai☆TONIGHT": "MY 마이☆투나이트",
  "Mi wa μ'sic no Mi": "미 와 뮤직 노 미",
  "Mijuku DREAMER": "미숙 드리머",
  "Mira-Creation": "미라 크리에이션",
  "Miracle STAY TUNE!": "미라클 스테이 튠!",
  "Mirage Voyage": "미라지 보야지",
  "Mirai Harmony": "미라이 하모니",
  "Mirai Yohou Hallelujah!": "미래예보 할렐루야!",
  "Mirai no Bokura wa Shitteru yo": "미래의 우리는 알고 있어",
  "Mirai wa Kaze no You ni": "미래는 바람처럼",
  "Mitaiken HORIZON": "미체험 호라이즌",
  "Mogyutto \"love\" de Sekkinchuu!": "모귯토 \"love\"로 접근중!",
  "Music S.T.A.R.T!!": "뮤직 스타트!!",
  "Muteki-kyuu*Believer": "무적급*빌리버",
  "NEO SKY, NEO MAP!": "네오 스카이, 네오 맵!",
  "Natsuiro Egao de 1,2,Jump!": "여름빛 미소로 1,2,Jump!",
  "Natsumeki Pain": "나츠메키 페인",
  "Neutral": "뉴트럴",
  "Next SPARKLING!!": "넥스트 스파클링!!",
  "Nightingale Love Song": "나이팅게일 러브 송",
  "Nijiiro Passions!": "무지개빛 패션즈!",
  "No Brand Girls": "노 브랜드 걸즈",
  "Nonfiction!!": "논픽션!!",
  "Oh, Love & Peace!": "오, 러브 앤 피스!",
  "Oikakeru Yume no Saki de": "좇아가는 꿈의 저편에서",
  "Omoi yo Hitotsu ni Nare": "마음이여 하나가 되어라",
  "Otome Heart de Love Kyuden": "소녀의 마음으로 사랑의 궁전",
  "PASTEL": "파스텔",
  "PHOENIX": "피닉스",
  "Poppin' Up!": "파핀 업!",
  "Private Wars": "프라이빗 워즈",
  "Proof": "프루프",
  "Reflection in the mirror": "리플렉션 인 더 미러",
  "Retrofuture": "레트로퓨처",
  "Rise Up High!": "라이즈 업 하이!",
  "Ryouran! Victory Road": "요란! 빅토리 로드",
  "SELF CONTROL!!": "셀프 컨트롤!!",
  "SENTIMENTAL StepS": "센티멘탈 스텝스",
  "SINGING, DREAMING, NOW!": "싱잉, 드리밍, 나우!",
  "START!! True dreams": "스타트!! 트루 드림즈",
  "START:DASH!!": "스타트 대시",
  "SUKI for you, DREAM for you!": "스키 포 유, 드림 포 유!",
  "SUNNY DAY SONG": "써니 데이 송",
  "Saikou Heart": "최고 하트",
  "Sakaku♡CROSSROADS": "착각♡크로스로드",
  "Second Sparkle": "세컨드 스파클",
  "Shekira☆☆☆": "셰키라☆☆☆",
  "Shiranai Love * Oshiete Love": "모르는 Love * 알려줘 Love",
  "Shooting Voice!!": "슈팅 보이스!!",
  "Sing! Shine! Smile!": "싱! 샤인! 스마일!",
  "Snow halation": "스노우 할레이션",
  "Solitude Rain": "솔리튜드 레인",
  "Sore wa Bokutachi no Kiseki": "그것은 우리들의 기적",
  "Sparkly Spot": "스파클리 스팟",
  "Special Color": "스페셜 컬러",
  "Starlight Prologue": "스타라이트 프롤로그",
  "Stellar Stream": "스텔라 스트림",
  "Step! ZERO to ONE": "스텝! 제로 투 원",
  "Strawberry Trapper": "스트로베리 트래퍼",
  "Suisai Sekai": "수채세계",
  "THE SECRET NiGHT": "더 시크릿 나이트",
  "TOKIMEKI Runners": "토키메키 러너즈",
  "Takaramonozu": "타카라모노즈",
  "Tiny Stars": "타이니 스타즈",
  "Tokonatsu☆Sunshine": "만년 여름☆Sunshine",
  "Torikoriko PLEASE!!": "토리코리코 플리즈!!",
  "Tousou Meisou Mobius Loop": "도주미주 모비우스 루프",
  "Towa hours": "토와 하워즈",
  "Tsubasa・La・Liberte": "츠바사・라・리베르테",
  "Tsukuyomi Kurage": "츠쿠요미 쿠라게",
  "Tsunagaru Connect": "츠나가루 커넥트",
  "UNIVERSE!!": "유니버스!!",
  "VIVID WORLD": "비비드 월드",
  "Very! Very! COCO Natsu": "베리! 베리! 코코 나츠",
  "Vitamin SUMMER!": "비타민 서머!",
  "WAO-WAO Powerful day!": "와오와오 파워풀 데이!",
  "WATER BLUE NEW WORLD": "워터 블루 뉴 월드",
  "WE WILL!!": "위 윌!!",
  "Watashi no Symphony ~Kanon Shibuya Ver.~": "나의 심포니 ~시부야 카논 Ver.~",
  "Welcome to 僕らのセカイ": "Welcome to 우리들의 세계",
  "Wish Song": "위시 송",
  "Wonder Zone": "원더 존",
  "Wonderful Rush": "원더풀 러시",
  "Yuki Mau Sora to Nibyou no Eien": "눈 내리는 하늘과 2초의 영원",
  "Yume Kataru yori Yume Utaou": "꿈을 말하기보다 꿈을 노래하자",
  "Yume de Yozora o Terashitai": "꿈으로 밤하늘을 비추고 싶어",
  "Yume ga Bokura no Taiyou sa": "꿈이 우리의 태양이야",
  "Yume no Tobira": "꿈의 문",
  "Yumewazurai": "꿈앓이",
  "Yuuki wa Doko ni? Kimi no Mune ni!": "용기는 어디에? 너의 가슴에!",
  "Zenhoui Kyun♡": "전방위 큥♡",
  "not ALONE not HITORI": "not ALONE not 히토리",
  "stars we chase": "스타즈 위 체이스",
  "sweet&sweet holiday": "스위트&스위트 홀리데이",
  "絶対的LOVER": "절대적 러버"
};

  function cardLocaleName(card) {
    if (!card) return '';
    var loc = getLocale();
    if (loc === 'ja') return card.name || card.name_en || '';
    if (loc === 'ko') {
      var koName = (card.name_en && KO_NAME_MAP[card.name_en])
        || (card.name && KO_NAME_MAP[card.name])
        || (card.name_en && KO_SONG_MAP[card.name_en])
        || (card.name && KO_SONG_MAP[card.name]);
      if (koName) return koName;
    }
    return card.name_en || card.name || '';
  }

  function cardLocaleText(card) {
    if (!card) return '';
    var loc = getLocale();
    if (loc === 'ja') return card.text_jp || card.text || '';
    if (loc === 'es') return card.text_es || card.text || '';
    if (loc === 'ko') return card.text_ko || card.text || '';
    return card.text || card.text_jp || '';
  }

  var KO_CARD_TYPE_MAP = {
    'メンバー': '멤버',
    'ライブ': '라이브',
    'エネルギー': '에너지',
  };
  var KO_CARD_TYPE_EN_MAP = {
    'Member': '멤버',
    'Live': '라이브',
    'Energy': '에너지',
  };

  function cardLocaleType(card) {
    if (!card) return '';
    var loc = getLocale();
    if (loc === 'ja') return card.card_type || card.card_type_en || '';
    if (loc === 'es') return card.card_type_es || card.card_type_en || card.card_type || '';
    if (loc === 'ko') {
      if (card.card_type_ko) return card.card_type_ko;
      if (card.card_type && KO_CARD_TYPE_MAP[card.card_type]) return KO_CARD_TYPE_MAP[card.card_type];
      if (card.card_type_en && KO_CARD_TYPE_EN_MAP[card.card_type_en]) return KO_CARD_TYPE_EN_MAP[card.card_type_en];
      return KO_CARD_TYPE_EN_MAP[card.card_type] || card.card_type_en || card.card_type || '';
    }
    return card.card_type_en || card.card_type || '';
  }

  function tutorialDialogue(step) {
    if (!step) return '';
    var loc = getLocale();
    if (loc === 'ja') {
      if (_tutorialJa && _tutorialJa[step.id]) return _tutorialJa[step.id];
      var jaTranslated = t('tutorial.' + step.id);
      if (jaTranslated !== 'tutorial.' + step.id) return jaTranslated;
    }
    if (loc === 'es') {
      if (_tutorialEs && _tutorialEs[step.id]) return _tutorialEs[step.id];
      var esTranslated = t('tutorial.' + step.id);
      if (esTranslated !== 'tutorial.' + step.id) return esTranslated;
    }
    if (loc === 'ko') {
      if (_tutorialKo && _tutorialKo[step.id]) return _tutorialKo[step.id];
      var koTranslated = t('tutorial.' + step.id);
      if (koTranslated !== 'tutorial.' + step.id) return koTranslated;
    }
    return step.dialogue || '';
  }

  var LOCALE_FLAG_SRC = {
    en: 'assets/flags/US_United_States_rect.png',
    ja: 'assets/flags/JP_Japan_rect.png',
    es: 'assets/flags/MX_Mexico_rect.png',
    ko: 'assets/flags/KR_South_Korea_rect.png'
  };

  var LOCALE_PICKER_IDS = ['sel-locale-auth', 'sel-locale-hub', 'sel-locale-options'];

  function closeLocalePicker(picker) {
    if (!picker) return;
    var toggle = picker.querySelector('.locale-picker-toggle');
    var menu = picker.querySelector('.locale-picker-menu');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    if (menu) menu.hidden = true;
  }

  function closeAllLocalePickers(except) {
    document.querySelectorAll('[data-locale-picker]').forEach(function (picker) {
      if (except && picker === except) return;
      closeLocalePicker(picker);
    });
  }

  function syncLocalePicker(toggle) {
    if (!toggle) return;
    var picker = toggle.closest('[data-locale-picker]') || toggle.parentElement;
    var loc = getLocale();
    var flag = toggle.querySelector('.locale-flag');
    var label = toggle.querySelector('.locale-picker-current');
    if (flag) flag.src = LOCALE_FLAG_SRC[loc] || LOCALE_FLAG_SRC.en;
    if (label) {
      label.setAttribute('data-i18n', 'language.' + loc);
      label.textContent = t('language.' + loc);
    }
    if (!picker) return;
    picker.querySelectorAll('.locale-picker-option').forEach(function (opt) {
      var active = opt.getAttribute('data-locale') === loc;
      opt.classList.toggle('is-active', active);
      opt.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  }

  function syncLocaleSelect(sel) {
    // Back-compat: native <select> or custom picker toggle button
    if (!sel) return;
    if (sel.tagName === 'SELECT') {
      var loc = getLocale();
      if (sel.value !== loc) sel.value = loc;
      return;
    }
    syncLocalePicker(sel);
  }

  function applyLocaleChoice(loc) {
    if (LOCALES.indexOf(loc) === -1) loc = 'en';
    setLocale(loc);
    LOCALE_PICKER_IDS.forEach(function (id) {
      syncLocaleSelect(document.getElementById(id));
    });
    try { document.documentElement.lang = loc; } catch (e) { /* ignore */ }
    closeAllLocalePickers();
    applyI18n();
    localeChangeCallbacks.forEach(function (fn) {
      try { fn(loc); } catch (e2) { console.error(e2); }
    });
  }

  function onLocaleSelectChange() {
    applyLocaleChoice(this.value);
  }

  function bindLocalePicker(toggle) {
    if (!toggle || toggle._lltcgLocaleBound) return;
    toggle._lltcgLocaleBound = true;
    var picker = toggle.closest('[data-locale-picker]');
    if (!picker) return;
    var menu = picker.querySelector('.locale-picker-menu');
    toggle.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      var open = toggle.getAttribute('aria-expanded') === 'true';
      closeAllLocalePickers(picker);
      if (!open) {
        toggle.setAttribute('aria-expanded', 'true');
        if (menu) menu.hidden = false;
      } else {
        closeLocalePicker(picker);
      }
    });
    picker.querySelectorAll('.locale-picker-option').forEach(function (opt) {
      if (opt._lltcgLocaleBound) return;
      opt._lltcgLocaleBound = true;
      opt.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        applyLocaleChoice(opt.getAttribute('data-locale') || 'en');
      });
    });
  }

  function onLocaleChange(fn) {
    if (typeof fn === 'function') localeChangeCallbacks.push(fn);
  }

  function deckLocaleName(deck, id) {
    if (!deck) return id || '';
    var loc = getLocale();
    var key = id || deck.id || '';
    if (key) {
      var mapped = t('deck.starters.' + key);
      if (mapped && mapped !== 'deck.starters.' + key) return mapped;
    }
    if (loc === 'ja') return deck.name || deck.name_en || key || '';
    if (loc === 'ko') {
      // Translate "X Start Deck" using group / name maps when possible
      var en = deck.name_en || '';
      var m = en.match(/^(.*)\s+Start Deck$/i);
      if (m) {
        var group = cardLocaleName({ name_en: m[1].trim() }) || m[1].trim();
        return group + ' 스타터 덱';
      }
      return cardLocaleName(deck) || en || deck.name || key || '';
    }
    return deck.name_en || deck.name || key || '';
  }

  function loadTutorialJa() {
    if (_tutorialJa) return Promise.resolve(_tutorialJa);
    return fetch('./tutorial_ja.json?v=6', { cache: 'no-store' })
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

  function loadTutorialEs() {
    if (_tutorialEs) return Promise.resolve(_tutorialEs);
    return fetch('./tutorial_es.json?v=6', { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('tutorial_es HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        _tutorialEs = data && typeof data === 'object' ? data : {};
        return _tutorialEs;
      })
      .catch(function () {
        _tutorialEs = {};
        return _tutorialEs;
      });
  }

  function loadTutorialKo() {
    if (_tutorialKo) return Promise.resolve(_tutorialKo);
    return fetch('./tutorial_ko.json?v=2', { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('tutorial_ko HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        _tutorialKo = data && typeof data === 'object' ? data : {};
        return _tutorialKo;
      })
      .catch(function () {
        _tutorialKo = {};
        return _tutorialKo;
      });
  }

  function initLocale(onChange) {
    if (typeof onChange === 'function') onLocaleChange(onChange);
    var curLoc = getLocale();
    try { document.documentElement.lang = curLoc; } catch (e) { /* ignore */ }
    try {
      if (document.body) {
        document.body.classList.remove('locale-ja', 'locale-ko');
        if (curLoc === 'ja' || curLoc === 'ko') document.body.classList.add('locale-' + curLoc);
      }
    } catch (e0) { /* ignore */ }
    LOCALE_PICKER_IDS.forEach(function (id) {
      var sel = document.getElementById(id);
      syncLocaleSelect(sel);
      if (!sel) return;
      if (sel.tagName === 'SELECT') {
        if (!sel._lltcgLocaleBound) {
          sel._lltcgLocaleBound = true;
          sel.addEventListener('change', onLocaleSelectChange);
        }
      } else {
        bindLocalePicker(sel);
      }
    });
    if (!document.documentElement._lltcgLocaleDocBound) {
      document.documentElement._lltcgLocaleDocBound = true;
      document.addEventListener('click', function () {
        closeAllLocalePickers();
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') closeAllLocalePickers();
      });
    }
    applyI18n();
    // Keep closed-toggle flag + current label in sync after i18n rewrite
    LOCALE_PICKER_IDS.forEach(function (id) {
      syncLocaleSelect(document.getElementById(id));
    });
    void loadTutorialJa();
    void loadTutorialEs();
    void loadTutorialKo();
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
    loadTutorialEs: loadTutorialEs,
    loadTutorialKo: loadTutorialKo,
    tutorialDialogue: tutorialDialogue,
    initLocale: initLocale,
    initLocaleUi: initLocaleUi,
    onLocaleChange: onLocaleChange
  };
})();
