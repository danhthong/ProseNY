# Plan 02 — Workspace Chat API Unification

**Status:** Complete (Option A — adapter layer)  
**Priority:** P0 (blocker)  
**Depends on:** Plan 01  
**Estimated effort:** Medium (3–5 days)

---

## Goal

Make the **workspace chat** (three-column CourtFlow UI) functional by connecting it to the same intake backend that powers the homepage widget.

## Problem

Two chat clients existed:

| Surface | Client | API |
|---------|--------|-----|
| Homepage | `modules/intake/assets/chat.js` | `POST /prose/v1/intake/interpret` ✅ |
| Workspace | `themes/prose-app/build/courtflow.js` | `POST courtflow/v1/sessions/{id}/messages` ❌ **was not implemented** |

## Solution implemented

**Option A — adapter layer** in `prose-core`:

- `POST /courtflow/v1/sessions` — create session (UUID), stored in transients (30 days)
- `GET /courtflow/v1/sessions/{id}/state` — context panel state
- `GET /courtflow/v1/sessions/{id}/messages` — chat history
- `POST /courtflow/v1/sessions/{id}/messages` — delegates to `AI_Intake_Service::interpret()`
- `GET|POST /courtflow/v1/sessions/{id}/documents` — list / generate blank package when intake complete

Workspace JS persists `session_id` in `localStorage` (`courtflow_session_id`) and recovers expired sessions automatically.

## Files added / changed

| File | Purpose |
|------|---------|
| `modules/intake/rest/class-courtflow-session-store.php` | Transient session persistence |
| `modules/intake/rest/class-courtflow-response-mapper.php` | Interpreter → workspace API shape |
| `modules/intake/rest/class-courtflow-sessions-rest-controller.php` | REST routes |
| `modules/intake/class-intake-module.php` | Registers CourtFlow REST adapter |
| `modules/intake/tests/CourtflowResponseMapperTest.php` | Mapper unit tests |
| `themes/prose-app/build/courtflow.js` | localStorage session + 404 recovery |

## Acceptance criteria

- [x] Workspace page loads; user can send a message and receive AI reply
- [x] Intake completion meter updates from interpreter `completion`
- [x] Resolved workflow appears in context panel / case actions
- [x] Session survives page refresh (localStorage + server transient)
- [x] Same routing outcome as homepage for identical messages (shared `AI_Intake_Service`)
- [x] No duplicate/conflicting intake state between panels (single interpreter backend)

## Manual test checklist

1. Open workspace page → send "I need a divorce in Queens with two children"
2. Confirm assistant reply, facts panel, and intake meter update
3. Refresh page → conversation history and state restore
4. Complete intake → Generate Filing Package enables (when blank PDFs available)
5. Compare same message on homepage widget — workflow should match

## Follow-ups (later plans)

- Plan 16: DB-backed session history (replace transients)
- Plan 06: Full filing package UX polish
- Plan 17: Harden REST auth beyond same-origin MVP
