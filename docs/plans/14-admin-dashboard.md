# Plan 14 — Admin Dashboard

**Status:** Draft — awaiting review  
**Priority:** P3  
**Depends on:** Plan 04, Plan 05, Plan 13  
**Estimated effort:** Large (1–2 weeks)

---

## Goal

Internal **admin dashboard** to manage workflows, forms, county rules, and verification — PRD Ch. 23.

## Requirements reference

- PRD Ch. 23 — Admin Dashboard
- PRD Ch. 33 — Verification System
- Workflow management, form management, county rules, verification

## Current state

- WordPress admin pages: intake tester, AI usage, guidance admin, chat packet admin
- No unified admin hub
- Workflows edited via JSON files in repo (correct for dev; admin needed for ops)

## Scope

### In scope

- Admin menu: CourtFlow AI hub
- Read-only views: workflows, forms inventory, routing matrix
- CRUD for county rules (Plan 13 data)
- Form link verification status dashboard
- Intake tester (existing — integrate)
- Workflow validation trigger (run `validate-workflows.php` from admin)

### Out of scope

- Visual workflow graph editor (future)
- Production CMS for non-technical editors without review workflow

## Deliverables

1. Admin hub page with sections
2. County rules CRUD
3. Forms catalog browser with search
4. Verification job: check official URLs, flag stale
5. Role capability: `manage_courtflow` or reuse `manage_options`

## Acceptance criteria

- [ ] Admin can view all 12 workflows and their required forms
- [ ] Admin can edit county rule text without code deploy
- [ ] Verification run reports broken links
- [ ] Intake tester accessible from hub

## Implementation tasks

1. Design admin IA (information architecture)
2. Register menu + capability
3. Build list tables for workflows (read-only from catalog)
4. County rules CRUD with sanitization
5. Wire verification cron/manual job

## Files likely touched

- `includes/class-admin.php`
- New `modules/admin/` or extend existing admin pages
- Plan 17 verification system

## Review questions

1. Admin edits to **workflows** — still JSON/git-only for MVP, or DB override layer?
