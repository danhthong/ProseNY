# Plan 02 — Workspace Chat API Unification

**Status:** Draft — awaiting review  
**Priority:** P0 (blocker)  
**Depends on:** Plan 01  
**Estimated effort:** Medium (3–5 days)

---

## Goal

Make the **workspace chat** (three-column CourtFlow UI) functional by connecting it to the same intake backend that powers the homepage widget.

## Problem

Two chat clients exist:

| Surface | Client | API |
|---------|--------|-----|
| Homepage | `modules/intake/assets/chat.js` | `POST /prose/v1/intake/interpret` ✅ |
| Workspace | `themes/prose-app/build/courtflow.js` | `POST courtflow/v1/sessions/{id}/messages` ❌ **not implemented** |

`courtflowConfig.restUrl` points to `courtflow/v1/` but no REST routes register that namespace.

## Requirements reference

- User Dashboard (PRD Ch. 22) — case status, forms, timeline in workspace
- Intake as Step 1 of guided workflow UI

## Options (pick one at review)

### Option A — Adapter layer (recommended)

Implement thin `courtflow/v1` REST controller in theme or plugin that:

- `POST /sessions` — create session, return ID
- `GET /sessions/{id}/messages` — load history
- `POST /sessions/{id}/messages` — delegate to `AI_Intake_Service::interpret()`
- Maps response shape expected by `courtflow.js` ↔ interpreter result

**Pros:** Minimal JS changes; workspace keeps session model.  
**Cons:** Two API surfaces to maintain until consolidated.

### Option B — Refactor workspace JS

Change `courtflow.js` to call `/prose/v1/intake/interpret` directly with localStorage session (same as homepage widget).

**Pros:** Single API.  
**Cons:** Larger JS refactor; session/history model must be rebuilt in theme.

### Option C — Full session persistence (Plan 16)

Implement DB-backed sessions first, then wire chat.

**Pros:** Production-ready persistence.  
**Cons:** Blocks MVP longer; depends on Plan 16.

## Recommended approach

**Option A for MVP**, with session stored in user meta or transient + localStorage mirror. Migrate to Option C in Plan 16.

## Scope

### In scope

- Register `courtflow/v1` routes (plugin preferred for REST standards)
- Message send/receive wired to AI intake interpreter
- Sync workspace state: completion %, workflow, case profile, newly captured facts
- Error handling consistent with homepage widget
- Nonce + capability checks

### Out of scope

- User accounts / login requirement (stay account-free for MVP unless already required)
- Full DB session history (Plan 16)
- Document generation from chat (Plan 06)

## Deliverables

1. `Courtflow_Sessions_Rest_Controller` (or equivalent)
2. Response mapper: interpreter → `{ message, newly_captured, card?, requirements?, ... }`
3. Workspace chat sends/receives without HTTP 404
4. Context panel updates when intake completes

## Acceptance criteria

- [ ] Workspace page loads; user can send a message and receive AI reply
- [ ] Intake completion meter updates from interpreter `completion`
- [ ] Resolved workflow appears in context panel / case actions
- [ ] Session survives page refresh (localStorage minimum)
- [ ] Same routing outcome as homepage for identical messages
- [ ] No duplicate/conflicting intake state between panels

## Implementation tasks

1. Document `courtflow.js` expected request/response contract (read `sendMessage`, `updateState`)
2. Choose Option A/B/C with stakeholder sign-off
3. Implement REST routes + mapper
4. Wire context panel to `case_profile` from response
5. Manual test: divorce, custody, OP paths end-to-end in workspace

## Files likely touched

- New: `prose-core/modules/intake/rest/class-courtflow-sessions-rest-controller.php` (or theme `inc/courtflow/rest.php`)
- `themes/prose-app/build/courtflow.js` (if Option B)
- `themes/prose-app/inc/enqueue.php`
- `prose-core/includes/class-plugin.php` (register module)

## Review questions

1. Prefer Option A (adapter) or Option B (single API)?
2. Should workspace require WordPress login for session persistence?
