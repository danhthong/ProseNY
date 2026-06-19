# Plan 07 — Intake Chat UX & Prompts

**Status:** Draft — awaiting review  
**Priority:** P1  
**Depends on:** Plan 01, Plan 02  
**Estimated effort:** Medium (3–4 days)

---

## Goal

Polish intake **UX** so all required entry paths are discoverable and the conversation feels guided, not like a form wizard.

## Requirements reference

- UI: `docs/ui/intake-flow.md`, `docs/ui/chat-ui.md`
- Entry points from Workflow Routing Engine V1 (10 options)
- PRD — guided intake, not 50-question form

## Current state

- Homepage hero overpromises (“any legal questions”)
- Prompt chips: divorce, divorce+children, not sure (3 only)
- Workspace has separate chip set in `courtflow-intake-chat.php`
- Completion meter exists; missing-info panel partial

## Scope

### In scope

- Unified prompt chips covering all MVP entry paths:
  - File for divorce
  - Divorce with children
  - Custody / visitation
  - Child support
  - Order of protection / family offense
  - Received court papers
  - Not sure where to start
  - Spousal maintenance (in divorce context)
- Align homepage + workspace chip sets
- Fix hero/subtitle copy to match supported scope
- “Next question” / missing fields panel per `intake-flow.md`
- Suggested replies for boolean/discriminator questions (yes/no, county picker)
- Save progress via localStorage (already started) — document behavior

### Out of scope

- Account-based save/resume (Plan 16)
- Document upload UI (Plan 11)

## Deliverables

1. Updated prompt chips (homepage + workspace)
2. Copy audit: hero, disclaimer, restriction messages
3. Missing information panel component wired to `missing_fields`
4. UX spec update in `docs/ui/intake-flow.md`

## Acceptance criteria

- [ ] Every chip sends a message that passes domain guard (Plan 01)
- [ ] User sees completion % and what is still needed
- [ ] No legal advice disclaimer visible on all chat surfaces
- [ ] Mobile layout usable (composer, chips, meter)

## Implementation tasks

1. Define final chip list with product owner
2. Update `front-page.php`, `courtflow-intake-chat.php`, prose shortcode if needed
3. Implement missing-info panel in context panel or chat header
4. Add suggested reply buttons from `pending_field` type
5. Cross-browser smoke test

## Files likely touched

- `themes/prose-app/front-page.php`
- `themes/prose-app/template-parts/courtflow-intake-chat.php`
- `themes/prose-app/build/courtflow.js`
- `modules/intake/assets/chat.js`
- `docs/ui/intake-flow.md`

## Review questions

1. Show **county picker** as chips (5 boroughs) early in intake?
2. Maximum number of starter chips before UI clutter?
