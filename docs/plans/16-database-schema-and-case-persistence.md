# Plan 16 — Database Schema & Case Persistence

**Status:** Complete  
**Priority:** P2  
**Depends on:** Plan 02  
**Estimated effort:** Large (1–2 weeks)

---

## Goal

Persist **cases, sessions, documents, and deadlines** in the database per PRD Ch. 21 — moving beyond localStorage-only MVP.

## Requirements reference

- PRD Ch. 21 — Database Schema: Courts, Counties, Forms, WorkflowNodes, WorkflowEdges, Cases, Documents, Deadlines, Users
- PRD Ch. 35 — Security: authentication, audit logs

## Current state

- Forms module has DB tables, repositories, seeders (graph, routing, packages)
- Intake uses localStorage + ephemeral REST state
- No unified `cases` table for end users

## Scope

### In scope

- Schema design document + migrations
- Core tables:
  - `prose_cases` — user/session, workflow, county, status, facts JSON
  - `prose_case_messages` — chat history
  - `prose_case_documents` — uploaded files metadata
  - `prose_case_deadlines` — timeline deadlines
- Optional: mirror workflow nodes/edges in DB (or keep JSON as source, DB for overrides only)
- WordPress user association (optional login) + guest session token
- Migration from localStorage session to DB on login

### Out of scope

- Full multi-tenant SaaS isolation
- Data export GDPR tooling (note for Plan 17)

## Deliverables

1. `docs/plans/schema/case-persistence.sql` or WP dbDelta migrations
2. Repository classes with tests
3. Session REST backed by DB (upgrade Plan 02 Option A)
4. Admin case list (read-only)

## Acceptance criteria

- [ ] Guest can complete intake; case retrievable via session token
- [ ] Logged-in user sees case history across devices
- [ ] Facts stored match `case_profile` structure
- [ ] Plugin uninstall policy documented (keep vs purge data)

## Implementation tasks

1. Finalize ERD with review
2. Implement migrations on plugin activate
3. Refactor session controller to use repositories
4. Backward compat: import localStorage on first authenticated request
5. PHPUnit repository tests

## Files likely touched

- `includes/class-activator.php`
- New `modules/cases/` or extend intake module
- Plan 02 session API

## Review questions

1. **Guest cases** allowed indefinitely or expire after N days?
2. Workflow graph stays **JSON-only** or duplicated to DB?
