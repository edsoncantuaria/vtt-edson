# Design

## Current redesign — September 2026

The current implementation replaces the earlier presentation described below. The palette uses slate (`#121516`), warm white (`#eeede7`), muted gold (`#d6b17a`) and forest accents. Cinzel carries headings, Archivo controls, and JetBrains Mono numeric information. A shared SVG icon set always has accessible labels or adjacent text.

The account library leads into a map-centered table with a narrow tool rail, collapsible right panel and bottom shortcuts. Character play and editing are separate modes. Native dialogs provide keyboard focus and Escape handling. Images for this continuation are generated through Gemini in the browser, as requested by the user. See `../../docs/frontend-redesign.md` for scope and asset provenance.

The remaining sections record the earlier design pass; their palette, icon restriction and verification statements do not describe the current implementation.

<!-- impeccable:design -->

## World

**Dark fantasy adventuring app — Foundry VTT's register, unmistakably D&D, built with current craft.** User-pinned direction (beats the first pass's rolled "War Room" direction — see history below). Near-black warm charcoal ground. ONE committed primary accent — a rich gold/amber (Foundry's signature register, refined) — for every action/selection/current-state moment: primary buttons, active tool, active tab, current combat turn, focus rings. A deep garnet/crimson secondary accent for danger, critical hits, HP loss. A muted arcane blue-violet tertiary accent for purely informational reads (numeric data, links) — kept fully separate from the primary accent so "this is selected/active" never competes visually with "this is just a number you're reading." Full direction contract at `.impeccable/surfaces/apps-web.md`.

**Direction history:** the first pass rolled "War Room" (industrial battle-mat/wet-erase aesthetic) via `impeccable concept-seed`. User feedback: too generic/operator-dashboard, lost the D&D identity. This second pass is a user-pinned override (Foundry VTT reference + "modern" register) — per new-work.md, "a user- or brief-pinned direction beats the roll, always." Recorded as a brand commitment in `PRODUCT.md`.

## Tokens

Defined in `src/index.css`.

| Token | Value | Use |
|---|---|---|
| `--mat` / `--mat-deep` / `--mat-raised` / `--mat-panel` | `#18140f` / `#0d0a07` / `#221c15` / `#2b241a` | Ground → raised panel → card, warm charcoal ramp (not brown-heavy, not cool-industrial) |
| `--ink` / `--ink-muted` / `--ink-faint` | `#f1ece1` / `#b6a892` / `#7d715c` | Primary / secondary / tertiary text, warm off-white |
| `--gold` / `--gold-hot` / `--gold-ink` | `#d9a441` / `#f0bd5f` / `#1c1408` | Primary accent: every action, selection, current-state moment — the one color spent on "this is live right now" |
| `--arcane` / `--arcane-hot` | `#7c8fd9` / `#a0b0ea` | Informational accent only: numeric readouts (mono), links, category badges — never selection/action |
| `--heal` `#4fac7a` · `--danger` `#b3273d`/`#d94a5f` | | HP-bar/state semantics: healthy / low-or-error / critical |
| `--border` / `--border-strong` | gold-tinted low-alpha whites | Hairline separators |
| `--radius` `8px` / `--radius-lg` `14px` | | Moderate, crafted rounding — warmer than a sharp utility grid, short of a soft SaaS pill |
| `--font-ui` | Archivo | Workhorse grotesk: body, labels, controls |
| `--font-display` | Cinzel | Carved-stone/book-title register for real headings only (H1/H2, scene title, character-sheet name) — never a UI label, button, or data value |
| `--font-mono` | JetBrains Mono | Every numeric readout: HP, AC, initiative, dice results, room codes |

## Component language

- **Gold is the only "this is active/selected/actionable" signal**, everywhere, consistently — filled buttons, active tab underline/background, focus rings, current combat turn, hover states. Arcane is reserved strictly for informational reads and never doubles as a selection color (a real inconsistency from the first draft of this pass, caught and fixed: tab-active states and focus rings that had drifted to arcane were moved back to gold).
- **Moderate rounded corners** (8–14px) — crafted, not the "military grid" sharp corners of the superseded direction, not a soft dashboard pill either.
- **No literal parchment/wood-grain texture, no scroll borders.** The fantasy register comes from color + Cinzel headers + the canvas's own gold/crimson token-and-door language, not from skeuomorphic material rendering.
- **No icon system, no kicker/eyebrow above headings, no page-load animation** — unchanged from the first pass, these were craft-floor rules, not direction-specific.
- Tried and rejected during this pass: a small gold corner-bracket ornament on primary panels. Impeccable's own `detect` flagged it as the "side-tab accent border" AI-slop pattern; removed rather than argued past.

## Canvas (Pixi, `src/pixi/VttTable.ts`)

Recolored to match: warm-panel grid fill, gold-tinted grid lines (at meaningfully higher alpha than the first attempt — an arcane-blue line at low alpha read as invisible against the warm ground; gold at the same hue family as the border tokens reads correctly), ink-white walls, heal-green/gold doors (open/closed), token rings by actor type (arcane = character, danger = monster, gold = NPC/unlinked), HP bars in heal/gold/danger by percentage, wall/door drag-preview stroke in gold.

## Typography

Headings (H1/H2, scene title, character name) set in Cinzel. Everything else — labels, buttons, body, form controls — stays Archivo, unchanged from the first pass. Numeric data stays JetBrains Mono, unchanged (a functional choice independent of either fantasy direction).

## Color strategy

Committed: gold carries the "what's live right now" signal at full consistency across the whole app (Operate mode's permitted exception to Restrained, same justification as the first pass). Crimson and arcane are spent narrowly and only for their named semantic roles.

## Verification on record

`pnpm build` + `pnpm lint` clean. `impeccable detect --json` run twice on every changed file — first pass caught and fixed a "side-tab accent border" false-start (the corner ornament); second pass returned zero findings. Visually verified live (desktop 1440px + mobile 375px) through the running app — Lobby, TableView shell, Fichas, Compêndio panels — via the Browser tool against a real `php artisan serve` + SQLite backend.

**Caught mid-verification:** a genuine rendering regression where the Pixi canvas rendered fully black — root cause was a stale dev-server module graph (Vite HMR not picking up the token-rename edits), not application code; confirmed by restarting the preview server and re-verifying with raw WebGL `readPixels` before and after. Documented here because the same class of staleness bit this session twice before — if the canvas ever again looks unstyled/blank after an edit, restart the dev server before assuming a code bug.

**Disclosed deviations from the full Impeccable flow** (unchanged from the first pass): no image-generation tool available, so no comp-led build; the interactive decision-page server was replaced with direct chat confirmation; no `impeccable-finish-reviewer` / `impeccable-documenter` subagent was spawned.
