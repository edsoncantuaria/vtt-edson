# Product

## Current scope — September 2026

The user requested a complete frontend reconstruction while reusing the backend. Campaigns select D&D 5e 2014 or revised 2024; “Next” refers to the rules, not Next.js. Rooms remain and are now listed by authenticated account membership. The backend gains a room-list endpoint and campaign ruleset field to support this flow. React, Vite, Pixi, Zustand, Laravel and Reverb remain in place.

The current compendium is still 2014 SRD, clearly labeled even in 2024 campaigns. Full 2024 content and automation are not implemented. The earlier presentation-only restriction and older visual commitments below are historical; the current behavior is documented in `../../docs/frontend-redesign.md`.

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary users are tabletop RPG groups playing **D&D 5e**, split into two roles with different needs in the same session:

- **GM (mestre)** — runs the session: uploads the map, draws walls/doors/lights, controls fog, creates NPC/monster tokens and actors, runs combat/initiative, manages the shared compendium. Needs fast, low-friction controls because they're doing this live while narrating.
- **Player (jogador)** — joins via a room code, controls their own character token and sheet, rolls checks/saves/attacks, reads the shared map and chat. Needs clarity and speed more than configurability.

Confirmed: the product is meant to be **shareable with other GM groups**, not only the author's own table — so first impressions (auth/lobby) matter, not just in-session utility.

Language: Portuguese (Brazil) throughout the UI — confirmed by existing copy and dev seed accounts (`gm@vtt.local`, `player@vtt.local`).

## Product Purpose

A self-hosted virtual tabletop purpose-built for D&D 5e, positioned between a from-scratch generic VTT and a full Foundry/Roll20 install: tactical map (grid, tokens, walls/doors, manual lighting/fog), a full 5e character sheet (abilities, skills, saves, inventory, spells, HP/AC), a searchable SRD compendium (spells/items/monsters, OGL-licensed content), an initiative/combat tracker, and dice rolling with advantage/disadvantage and critical detection — all synced in real time over WebSocket (Laravel Reverb) so the GM and players see the same table update live.

Success looks like: a GM can run a full D&D 5e combat encounter — map, tokens, initiative, character sheets, dice — without leaving the app or falling back to a physical sheet or a separate dice roller.

## Positioning

Lighter and more focused than Foundry VTT or Roll20: no plugin ecosystem, no general-system flexibility — it is opinionated toward D&D 5e specifically, self-hosted (the user's own Laravel + Postgres + Reverb stack via Docker Compose, no subscription), and the SRD compendium is bundled/importable rather than requiring a separate paid content source.

## Operating Context

- A GM creates a campaign, gets a short room code, and shares it; players join with that code.
- Real-time session: everyone connected sees token moves, wall/door/light edits, fog changes, chat/rolls, and combat turn changes live via WebSocket.
- Sessions happen live, often with a shared screen or each participant on their own device (desktop primarily; the existing 800px responsive breakpoint stacks the sidebar under the map for narrower viewports/tablets).
- GM-only actions (map upload, walls/doors/lights, fog, NPC/monster creation, combat control) are enforced server-side, not just hidden in the UI.

## Capabilities and Constraints

- Stack is fixed and not part of this redesign's decision space: React 19 + Vite + PixiJS 8 (canvas/map rendering) + Zustand (client state) + plain CSS (no Tailwind/UI framework) on the frontend; Laravel 12 + Sanctum + Reverb + PostgreSQL on the backend. This redesign is presentation-only — no API/contract changes.
- The tactical map is rendered in a PixiJS canvas, not DOM — any new visual system must extend to canvas-drawn elements (grid lines, walls, doors, token rings, HP bars, fog, light glow), not just HTML/CSS.
- Content is D&D 5e SRD (OGL) only — no proprietary D&D Beyond/paid content integration (evaluated and explicitly deferred).
- Available: dynamic lighting with wall and closed-door line of sight, multiple scenes per campaign, campaign journal with GM-only visibility, and opt-in ambient audio from an HTTPS URL. Area-of-effect measurement templates remain future work.

## Brand Commitments

Name: "VTT Edson". No logo.

**Pinned visual reference (user-confirmed, overrides the rolled "War Room" direction from the first pass):** closer to Foundry VTT's visual register — dark fantasy adventuring-app chrome, warm gold/amber as the signature accent, unmistakably RPG/D&D-coded — but executed with current, modern craft rather than Foundry's actual somewhat dated execution. "Modern" here means contemporary typography/spacing/component conventions, not a retreat from the fantasy identity into generic dark-SaaS or industrial/utility aesthetics (the previous "War Room" pass over-corrected into that and was explicitly rejected for it).

## Evidence on Hand

None. No existing screenshots, testimonials, or case studies to preserve — this is a personal/early-stage project with no external users yet.

## Product Principles

1. **In-session speed over configurability.** Every screen used during live play (table, sheet, compendium, combat tracker) optimizes for glanceability and few clicks over exposing every option.
2. **GM and player see the same truth.** No client-side-only state that could desync between participants during a live session — the server and WebSocket broadcast are the source of truth.
3. **D&D 5e-first, not system-agnostic.** Don't hedge the design or the data model to accommodate hypothetical other game systems.
4. **Self-hosted, not SaaS.** No assumption of a hosted multi-tenant product; the audience is GMs willing to run their own Docker Compose stack.

## Accessibility & Inclusion

No product-specific accessibility requirement has been established yet; follow Impeccable's standard web accessibility floor (contrast, focus states, keyboard operability) by default.
