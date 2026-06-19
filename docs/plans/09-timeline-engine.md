# Plan 09 — Timeline Engine

**Status:** Complete  
**Priority:** P2  
**Depends on:** Plan 04, Plan 08  
**Estimated effort:** Medium (4–6 days)

---

## Goal

Track **current stage**, completed/pending tasks, deadlines, and upcoming hearings/conferences per case.

## Requirements reference

- PRD Ch. 19 — Timeline Engine
- Workflow stages in each JSON file
- Divorce Ecosystem — phase deadlines (PC order, compliance, etc.)

## Current state

- `class-case-timeline.php` in forms engine
- Guidance module has stage JSON seeds
- No user-facing timeline UI in workspace yet

## Scope

### In scope

- Timeline model: `{ stage, status, tasks[], deadlines[], events[] }`
- Derive initial timeline from resolved workflow stages
- Mark stages complete as user progresses (manual + future doc intelligence)
- Deadline catalog integration (`class-deadline-catalog.php`)
- Timeline UI in workspace (vertical stepper or calendar list)
- Alerts for approaching deadlines (UI badge, not email for MVP)

### Out of scope

- Calendar sync / reminders email
- Automatic deadline extraction from uploaded orders (Plan 11)
- NYSCEF docket sync (Plan 19)

## Deliverables

1. `Timeline_Service` — build from workflow + facts + optional documents
2. REST endpoint: `GET /prose/v1/case/{id}/timeline` or session-scoped equivalent
3. Timeline block in workspace
4. Seed deadline rules for uncontested + contested divorce key milestones

## Acceptance criteria

- [x] Contested divorce timeline shows commencement through judgment stages
- [x] Current stage highlighted; prior stages marked complete when criteria met
- [x] Deadlines display with plain-language explanation
- [x] Timeline updates when workflow stage advances (Plan 04)

## Implementation tasks

1. Unify timeline code paths in forms vs guidance modules
2. Define MVP deadline rules (static catalog, not AI)
3. Build timeline UI component
4. Wire document intelligence hooks (stub for Plan 11)
5. Tests for timeline generation per workflow

## Files likely touched

- `modules/forms/engine/class-case-timeline.php`
- `modules/forms/engine/class-deadline-catalog.php`
- `modules/guidance/`
- New timeline REST + theme block

## Review questions

1. MVP: **static stage list** only, or include **computed deadlines** (e.g. answer in 20 days)?
