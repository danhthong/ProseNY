# Proseny — Figma Design System Build Plan

> Source-of-truth plan for building the Proseny app UI in Figma from the docs in
> `app/docs/ui/*` and the Cursor rules in `app/.cursor/rules/*`.
>
> Run ID: `proseny-ds-2026-06-02`
> Plan team: `team::1419422215531812066` (Thông Đặng's team, **Pro** tier)
> File: **Proseny — App UI** — `xVPgq7caO1qyHwi7EbkPRw`
> File URL: https://www.figma.com/design/xVPgq7caO1qyHwi7EbkPRw

## Build status (2026-06-08, Forms module + PDF viewer)

| Phase | Status | Notes |
|---|---|---|
| 0 — Create file | ✅ Done | `xVPgq7caO1qyHwi7EbkPRw` |
| 1a — Primitives (34 vars) | ✅ Done | gray, brand, success, warning, danger ramps + white/black |
| 1b — Semantic Color (29 vars) | ✅ Done | **Light + Dark** modes (Pro upgrade) |
| 1c — Spacing (13) + Radius (7) | ✅ Done | Scoped to GAP/WIDTH_HEIGHT and CORNER_RADIUS |
| 1d — Text (10) + Effect (4) styles | ✅ Done | Display/L → Caption; Shadow/sm-md-lg + Focus ring |
| 2 — Page skeleton | ✅ Done | 28 pages + 2 Homepage pages = 30 |
| 2b — Foundations docs | 🟡 Partial | Cover + Colors swatches; Typography/Spacing/Effects pages empty |
| 3 — App components | 🟡 Partial | Button (24), Card (5), Input (4); Badge/Avatar/Stepper/Alert/etc. pending |
| 4 — App screens | ✅ Done | Dashboard, Intake Flow, Chat UI, Case Summary |
| 5 — Validation screenshot | ✅ Done | Dashboard frame `19:2` |
| 6 — **Homepage components** | ✅ Done | Logo, NavLink (Default/Active), IconButton, SendButton, ChatInput, SuggestedPromptCard, Header / Desktop, Header / Mobile |
| 7 — **Homepage screens (responsive)** | ✅ Done | Desktop 1440×1024 (`30:2`), Tablet 834×1112 (`31:52`), Mobile 390×844 (`32:102`), Mobile menu open (`34:138`) |
| 8 — **Forms module** | ✅ Done | Forms Library + Form Details (PDF viewer **left**) + 7 components; MCP scripts in `app/docs/ui/figma-scripts/forms-module/` |

### Starter plan limitations encountered

1. **6 MCP tool calls / month, total** — write tools are NOT exempt for Starter.
   Failed retries also count.
2. **1 mode per variable collection** — `addMode()` throws. Dark mode deferred.

### Resume protocol (after upgrade)

When the Figma plan is upgraded (Pro / Org / Enterprise), continue by:

1. Re-read this file to confirm `fileKey` + completed phases.
2. Re-load the `figma-use` + `figma-generate-library` skills.
3. Re-run a read-only `use_figma` to rehydrate state:
   ```js
   const cols = await figma.variables.getLocalVariableCollectionsAsync();
   const vars = await figma.variables.getLocalVariablesAsync();
   const pages = figma.root.children.map(p => ({ id: p.id, name: p.name }));
   return { collections: cols.map(c => c.name), varCount: vars.length, pages };
   ```
4. Resume Phase 2 (page skeleton script in conversation history) → Phase 3 → Phase 4.

If Pro/Org is acquired, also re-run Phase 1b with two modes (`Light`, `Dark`) using
the dark-mapping table preserved in the conversation history (now in this file's
`Dark mode mapping (for later)` section below).

## Why this plan exists

The user originally shared a Figma **Sites** file (`/site/...`), which the Figma MCP
does not support. The plan below creates a fresh Figma **Design** file
(`Proseny — App UI`) and builds the system out from the existing docs.

## Quota note (Starter plan)

- Read MCP calls capped at **6/month** for the whole plan.
- Write tools (`use_figma`, `create_new_file`, `upload_assets`, `generate_figma_design`,
  `add_code_connect_map`) are exempt or cheap, per Figma's rate-limit doc.
- Strategy: do all structural work via `use_figma` writes; reserve `get_screenshot`
  and `get_metadata` for milestone validation only.

## Inputs (existing docs)

| Doc | Used for |
|---|---|
| `app/docs/ui/design-system.md` | Principles, layout rules, card taxonomy, typography ramp |
| `app/docs/ui/dashboard.md` | Dashboard screen composition |
| `app/docs/ui/intake-flow.md` | Multi-step intake screen + progress stepper |
| `app/docs/ui/chat-ui.md` | Conversational intake screen |
| `app/docs/ui/case-summary.md` | Right-rail case summary screen |
| `app/.cursor/rules/legal-saas-ui.mdc` | Always-visible Progress / Status / Missing Info / Docs / Next Steps |
| `app/.cursor/rules/component-library.mdc` | Reusable atom list (Card, Stepper, Modal, Drawer, Alert, FormField, ProgressTracker, DocumentChecklist) |

## Phase 0 — Bootstrap

1. `create_new_file` → design file named **"Proseny — App UI"** in Thông Đặng's team.
2. Capture `fileKey` + `file_url` and record them in this doc.
3. Read-only `use_figma` to inspect the starter page set (no separate `get_metadata`
   call — saves a read quota slot).

## Phase 1 — Foundations (tokens)

All variables get explicit `scopes` and WEB `codeSyntax` (`var(--…)`).

### Collection: `Primitives` (1 mode: `Value`, scopes hidden = `[]`)

| Family | Variables |
|---|---|
| Gray | `gray/50, 100, 200, 300, 400, 500, 600, 700, 800, 900` |
| Brand (Indigo) | `brand/50, 100, 200, 300, 400, 500, 600, 700, 800, 900` |
| Success | `success/50, 500, 600, 700` |
| Warning | `warning/50, 500, 600, 700` |
| Danger | `danger/50, 500, 600, 700` |
| Neutral | `white`, `black` |

Anchors:
- `brand/500` = `#4F46E5` (Indigo 600) — trustworthy, professional
- `gray/900` = `#0F172A` — primary text
- `gray/50` = `#F8FAFC` — surface tint

### Collection: `Color` (modes: `Light` ONLY on Starter; Dark mapping deferred)

> Starter plan caps each variable collection at 1 mode. The Dark column below
> is preserved for the future upgrade — re-run Phase 1b with `addMode('Dark')`
> and call `setValueForMode(darkMode, aliasOf(primByName[darkSrc]))` for each.

Semantic tokens (scope = `FRAME_FILL, SHAPE_FILL` unless noted):

- `bg/canvas`, `bg/surface`, `bg/surface-raised`, `bg/muted`, `bg/inverse`
- `text/primary`, `text/secondary`, `text/muted`, `text/inverse`, `text/brand`, `text/danger`, `text/success`, `text/warning` (scope `TEXT_FILL`)
- `border/subtle`, `border/default`, `border/strong`, `border/focus` (scope `STROKE_COLOR`)
- `brand/solid`, `brand/solid-hover`, `brand/soft`, `brand/on-solid`
- `status/success-bg`, `status/success-fg`
- `status/warning-bg`, `status/warning-fg`
- `status/danger-bg`, `status/danger-fg`
- `status/info-bg`, `status/info-fg`

### Collection: `Spacing` (1 mode `Value`, scope `GAP, WIDTH_HEIGHT`)

`spacing/0 = 0`, `2 = 2`, `4`, `6`, `8`, `12`, `16`, `20`, `24`, `32`, `40`, `48`, `64`

### Collection: `Radius` (1 mode `Value`, scope `CORNER_RADIUS`)

`radius/none = 0`, `sm = 4`, `md = 8`, `lg = 12`, `xl = 16`, `2xl = 24`, `full = 9999`

### Text styles (Inter)

| Style | Size / Line / Weight |
|---|---|
| `Display/L` | 36 / 44 / 700 |
| `Heading/XL` | 28 / 36 / 700 |
| `Heading/L` | 22 / 30 / 600 |
| `Heading/M` | 18 / 26 / 600 |
| `Heading/S` | 16 / 24 / 600 |
| `Body/L` | 16 / 24 / 400 |
| `Body/M` | 14 / 22 / 400 |
| `Body/S` | 13 / 20 / 400 |
| `Label` | 13 / 18 / 500 |
| `Caption` | 12 / 16 / 500 |
| `Mono/S` | 13 / 20 / 400 (JetBrains Mono fallback Inter) |

### Effect styles

- `Shadow/sm` – soft 1px ambient
- `Shadow/md` – card hover
- `Shadow/lg` – modal/drawer
- `Focus ring` – 2px brand outline at 40% opacity

## Phase 2 — File skeleton

Pages, in order:

```
Cover
Getting Started
─── FOUNDATIONS ───
Colors
Typography
Spacing & Radius
Effects
─── COMPONENTS ───
Button
Input / Field
Card
Badge / Tag
Avatar
Progress Bar
Stepper
Alert
Modal
Drawer
FormField
ProgressTracker
DocumentChecklist
ChatMessage
SidebarItem
─── SCREENS ───
Dashboard
Intake Flow
Chat UI
Case Summary
Forms Library
Form Details
```

## Phase 3 — Components (atoms → molecules → organisms)

Build order (one component = one `use_figma` call, exit-validated visually):

1. **Button** — variants: Style {Primary, Secondary, Ghost, Danger} × Size {SM, MD, LG} × State {Default, Hover, Disabled} + bool `Has Icon`.
2. **Input / Field** — variants: State {Default, Focus, Error, Disabled} × Size {MD, LG}.
3. **Badge** — variants: Tone {Neutral, Brand, Success, Warning, Danger} × Variant {Solid, Soft}.
4. **Avatar** — variants: Size {SM, MD, LG, XL} × Type {Initials, Image, Icon}.
5. **Progress Bar** — bool `Show Percent`, prop `Value` (text).
6. **Stepper** — horizontal step list with `Active`, `Complete`, `Pending` states.
7. **Alert** — Tone {Info, Success, Warning, Danger} + bool `Dismissible`.
8. **Card** — Type {Info, Warning, Success, Document, Workflow} (matches docs).
9. **FormField** — composes Label + Input + Helper / Error.
10. **ProgressTracker** — vertical case-progress checklist used in sidebar.
11. **DocumentChecklist** — uploaded / missing / pending review tri-state list.
12. **ChatMessage** — Role {User, AI, System} (per `chat-ui.md`).
13. **Modal** & **Drawer** — overlay shells.
14. **SidebarItem** — nav row, active + icon.

Every component: bound to color/spacing/radius variables, no hardcoded values.

## Phase 4 — Screens

Composed from instances of Phase-3 components, matching docs.

| Screen | Source doc | Notable comp |
|---|---|---|
| Dashboard | `dashboard.md` | Case Progress + Next Steps + Documents + AI Assistant widgets |
| Intake Flow | `intake-flow.md` | Header + Progress Stepper + Question + Footer Actions |
| Chat UI | `chat-ui.md` | Conversation + Sidebar (Case Summary, Missing Info, Required Docs, Next Steps) |
| Case Summary | `case-summary.md` | Section list with completion indicators + Quick Edit |

## Validation gates

- After Phase 1: list collections + variable count (write-only return).
- After Phase 2: list pages (write-only return).
- After Phase 3 atoms: ONE `get_screenshot` of the Button page (uses 1 read quota).
- After Phase 4: ONE `get_screenshot` of the Dashboard screen (uses 1 read quota).
- Total planned reads ≤ 4/6 monthly quota.

## Dark mode mapping (for later, after upgrade)

| Semantic | Light alias | Dark alias |
|---|---|---|
| `bg/canvas` | `gray/50` | `gray/900` |
| `bg/surface` | `white` | `gray/800` |
| `bg/surface-raised` | `white` | `gray/700` |
| `bg/muted` | `gray/100` | `gray/800` |
| `bg/inverse` | `gray/900` | `white` |
| `text/primary` | `gray/900` | `gray/50` |
| `text/secondary` | `gray/700` | `gray/300` |
| `text/muted` | `gray/500` | `gray/400` |
| `text/inverse` | `white` | `gray/900` |
| `text/brand` | `brand/600` | `brand/400` |
| `text/danger` | `danger/600` | `danger/500` |
| `text/success` | `success/600` | `success/500` |
| `text/warning` | `warning/700` | `warning/500` |
| `border/subtle` | `gray/100` | `gray/800` |
| `border/default` | `gray/200` | `gray/700` |
| `border/strong` | `gray/300` | `gray/600` |
| `border/focus` | `brand/500` | `brand/400` |
| `brand/solid` | `brand/600` | `brand/500` |
| `brand/solid-hover` | `brand/700` | `brand/400` |
| `brand/soft` | `brand/50` | `brand/900` |
| `brand/on-solid` | `white` | `white` |
| `status/success-bg` | `success/50` | `success/700` |
| `status/success-fg` | `success/700` | `success/50` |
| `status/warning-bg` | `warning/50` | `warning/700` |
| `status/warning-fg` | `warning/700` | `warning/50` |
| `status/danger-bg` | `danger/50` | `danger/700` |
| `status/danger-fg` | `danger/700` | `danger/50` |
| `status/info-bg` | `brand/50` | `brand/700` |
| `status/info-fg` | `brand/700` | `brand/50` |

## Homepage spec (chat-first, ChatGPT-style)

Reference: `assets/67d1ef07-…-9a9e75507215.png` provided by the user.

### Components (on `Homepage / Components` page)
- **Logo** — shield + "Proseny" wordmark
- **NavLink** — variants: `State=Default` / `State=Active` (with brand underline)
- **IconButton** — 36×36 round, neutral border
- **SendButton** — 36×36 brand-fill circle with up arrow
- **ChatInput** — multi-line input shell, left tool icons (+ / globe / spark), right side mic + SendButton, shadow + border
- **SuggestedPromptCard** — title + 1-line description
- **Header / Desktop** — Logo · centered Nav (Home FAQ Contact Us) · Login outline + Register filled
- **Header / Mobile** — Logo · hamburger

### Screens (on `Homepage` page)
| Frame | Size | Notes |
|---|---|---|
| Homepage / Desktop | 1440 × 1024 | Sticky header, centered 720-wide hero, ChatInput, 2×2 prompts, privacy line |
| Homepage / Tablet | 834 × 1112 | Centered 620-wide hero, narrower prompts |
| Homepage / Mobile | 390 × 844 | Mobile header, stacked prompts, **ChatInput pinned at bottom** with top border |
| Homepage / Mobile (menu open) | 390 × 844 | 45% black backdrop + 300-wide right-side drawer with nav rows + Login/Register |

### Tokens / styles used
- Surfaces: `bg/canvas`, `bg/surface`
- Text: `text/primary`, `text/secondary`, `text/muted`, `text/brand`, `brand/on-solid`
- Borders: `border/subtle`, `border/default`
- Brand: `brand/solid`, `brand/soft`
- Effects: `Shadow/md` on ChatInput card

## Forms module spec

> Primary public-facing library for Divorce and Family Court forms.
> Extends the Proseny Design System (Dashboard, Intake Flow, Chat UI, Case Summary).
> **No new token system** — reuse existing Color, Typography, Spacing, Radius, Effects.

### New pages (under SCREENS)

| Page | Frames |
|---|---|
| **Forms Library** | Desktop 1440px, Tablet, Mobile |
| **Form Details** | Desktop 1440px, Mobile |

### New components (under COMPONENTS)

| Component | Purpose |
|---|---|
| **FormCard** | Form ID, title, case type, file name; actions: View Form (+ future Start Guided Interview); variants: Default, Hover, Featured |
| **FormDownloadCard** | File name + primary Download PDF CTA + secondary Start Guided Interview (disabled placeholder) |
| **FilterPill** | Case-type filter chips |
| **SearchBar** | Search by Form ID or Form Title |
| **Breadcrumb** | Forms Library → Case Type → Form |
| **EmptyState** | No results / empty library |
| **PdfViewer** | Left-rail PDF preview (toolbar, page canvas, footer controls) — **UI placeholder**; real PDF rendering deferred to WordPress |

### Forms Library screen

**Desktop (1440px)**

1. **Header** — title *Court Forms Library*, description *Browse Divorce and Family Court forms.*
2. **SearchBar** — filter by Form ID or Form Title
3. **Case Type filters** (FilterPill row): All Forms, Divorce, Child Support, Custody, Visitation, Paternity, Family Offense, Orders of Protection
4. **Forms grid** — 3-col desktop / 2-col tablet / 1-col mobile; FormCard instances
5. **Pagination** — bottom of page

### Form Details screen (updated layout — PDF viewer on LEFT)

**Desktop — ~65 / 35 split**

```
Breadcrumb (full width)
├── LEFT ~65% — PdfViewer
│   ├── Toolbar: file name · page indicator (1 / N) · zoom −/+ · download
│   ├── Canvas: white page on bg/muted, Shadow/md
│   └── Footer: Prev · Page X of N · Next
└── RIGHT ~35% — Sidebar
    ├── Form Title, Form ID, Case Type
    ├── Description placeholder
    ├── FormDownloadCard (Download PDF primary)
    ├── Start Guided Interview (disabled placeholder)
    └── AI explanation placeholder card
Related Forms (full width below) — 4–6 FormCard instances, same case type
```

**Mobile — single column (top → bottom)**

1. Breadcrumb
2. PdfViewer (full width, shorter height)
3. Form metadata (title, ID, case type, description)
4. FormDownloadCard
5. Start Guided Interview + AI placeholders
6. Related Forms (stacked FormCards)

### Future integration (placeholders only — do not implement in Figma v1)

- AI Assistant
- Guided Interview
- Generate Documents
- Case Workflow

### Design style

- Modern SaaS legal-tech: clean, trustworthy
- Tokens: `bg/canvas`, `bg/surface`, `bg/muted`, `text/primary`, `text/secondary`, `border/default`, `brand/solid`, `radius/lg`, `Shadow/md`
- Typography: `Heading/L` page title, `Heading/M` sections, `Body/M` body, `Label` meta, `Caption` PDF chrome

### WordPress theme implementation

The Forms module is implemented in the `prose-app` theme (PDF viewer rendered as a real embedded PDF, not a placeholder):

| File | Role |
|---|---|
| `themes/prose-app/inc/forms.php` | Opts the private `prose_form` CPT into public front-end viewing (rewrite slug `forms`, archive), registers the case-type filter query var + `pre_get_posts`, helpers, rewrite flush |
| `themes/prose-app/archive-prose_form.php` | **Forms Library** — search, case-type filter pills, responsive grid (3/2/1), pagination, empty state |
| `themes/prose-app/single-prose_form.php` | **Form Details** — PDF viewer left (`<iframe>` of `prose_file_url`) + sidebar right (metadata, Download PDF, disabled Start Guided Interview, AI placeholder) |
| `themes/prose-app/template-parts/prose-site-header.php` | Shared Proseny header + mobile drawer |

Form data comes from `prose_form` meta (`prose_form_id`, `prose_file_name`, `prose_file_url`) and the `prose_case_type` taxonomy. Tailwind is recompiled via `npm run build` in the theme.

## Out-of-scope (v1)

- Code Connect mappings (deferred — repo is WordPress, not React).
- Auto-generated REST file listing (would need a Figma personal access token).
- The original `/site/ProSeNY` Sites file (not supported by MCP).
- Live PDF rendering inside Figma (placeholder UI only).
