# Plan 08 — User Dashboard & Case Summary

**Status:** Draft — awaiting review  
**Priority:** P1  
**Depends on:** Plan 02, Plan 06  
**Estimated effort:** Medium (4–6 days)

---

## Goal

Build the **user-facing case workspace**: case status, summary, required forms, deadlines placeholder, and actions — matching PRD Ch. 22.

## Requirements reference

- PRD Ch. 22 — User Dashboard
- UI: `docs/ui/dashboard.md`, `docs/ui/case-summary.md`
- Executive Blueprint — Timeline, Forms, Alerts

## Current state

- Workspace shell: progress rail, chat, context panel blocks
- `Case_Actions_Resolver` builds summary from case profile
- Step catalog in `themes/prose-app/inc/courtflow/steps.php` (9 steps)
- Context panel partially wired

## Scope

### In scope

- Context panel sections:
  - Matter type & workflow
  - Court(s) involved
  - County
  - Key facts collected (non-PII display rules)
  - Required forms list (from package)
  - Case actions (Get Documents, View Summary)
- Progress rail synced to intake completion + later steps
- Case summary expandable list
- Empty states before intake complete

### Out of scope

- Full timeline/deadlines (Plan 09)
- Admin views (Plan 14)
- Multi-case management

## Deliverables

1. Context panel fully data-driven from `case_profile` + package
2. Progress rail states: locked / current / completed / error
3. Case summary component per UI spec
4. REST: `POST /prose/v1/case/actions` stable contract documented

## Acceptance criteria

- [ ] During intake, panel shows partial facts + missing fields
- [ ] After intake, panel shows workflow title, court, forms count
- [ ] Get Documents triggers package download (Plan 06)
- [ ] Step rail advances when intake hits 100%
- [ ] Responsive: panel collapses on mobile

## Implementation tasks

1. Audit `courtflow-context-panel.php` vs UI specs
2. Bind panel to interpreter response on each turn
3. Implement summary field formatter (dates, county names, yes/no labels)
4. Sync stepper with `completion` threshold
5. Visual QA against Figma if available (`docs/ui/figma-build-plan.md`)

## Files likely touched

- `themes/prose-app/template-parts/courtflow-context-panel.php`
- `themes/prose-app/blocks/courtflow-context-panel/`
- `themes/prose-app/blocks/courtflow-progress-rail/`
- `modules/intake/class-case-actions-resolver.php`

## Review questions

1. Show **full fact dump** in summary or curated “key facts” only?
