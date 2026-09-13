---
version: 1
slug: "apps-web"
primary_target: "apps/web"
related_targets: []
---

## Direction contract

Scope: apps/web (whole app shell — Lobby, TableView, ActorSheet, Compendium, CombatTracker, Pixi canvas). Visitor mode: Lobby leans Persuade; everything past login is Operate.

**Supersedes the "War Room" direction from the first pass.** User feedback: too generic/industrial, lost the D&D identity. New direction is user-pinned (beats the roll per new-work.md), recorded as a brand commitment in PRODUCT.md.

THESIS: A dark fantasy adventuring app in Foundry VTT's register — unmistakably RPG/D&D — built with current craft instead of Foundry's actual dated execution. Refuses two things at once: the generic "dark SaaS dashboard" look (what "War Room" drifted into) and literal skeuomorphic parchment/wood kitsch (what the original pre-session theme was).

OWN-WORLD: Near-black warm charcoal ground (not brown-heavy, not cool-blue-industrial). ONE committed accent: a rich gold/amber (Foundry's signature register, refined) for primary actions, active state, current turn, selection. A deep garnet/crimson as the secondary semantic accent for danger, critical hits, HP loss — gold-and-blood is the D&D palette this world actually earns. Type: Cinzel for display headers (H1s, the current-turn/room-title moments) — a carved-stone/book-title register distinct from generic AI-default serifs — Archivo stays for UI/body/labels (workhorse, unchanged from the first pass), JetBrains Mono stays for numeric readouts (HP/AC/initiative/dice — functional choice, not a fantasy-register decision, no reason to change it). Moderate rounded corners (~8px, warmer than War Room's sharp military 4px) with a restrained warm vignette and a subtle drawn corner accent on primary panels — crafted, not flat-utility, but no literal parchment texture, no wood-grain, no scroll borders.

STORY: A GM opens the app and it reads as a real adventuring tool built for D&D specifically — not a generic operator dashboard reskinned in dark mode — while still resolving instantly under time pressure mid-combat.

FIRST VIEWPORT: Lobby's title sets in Cinzel over the charcoal ground with the gold accent; the card keeps the moderate-radius warm treatment. TableView's canvas still commands the frame; the sidebar's active states (current tool, current tab, current turn) all read through the same gold accent, consistently, everywhere.

FORM: User-pinned (Foundry VTT reference + "modern" register), not a concept-seed roll — the roll produced the superseded War Room direction.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance.
