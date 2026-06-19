# Plan 01 — Intake Domain Scope & Entry Points

**Status:** Complete  
**Priority:** P0 (blocker)  
**Depends on:** —  
**Estimated effort:** Small (1–2 days)  
**Completed:** 2026-06-19

---

## Goal

Align the AI domain scope guard and supported-issue catalog with the **12 workflow entry points** so users are never blocked on valid NYC divorce/family court intake starters.

## Problem

`Supported_Issue_Catalog` only lists 4 issue types (`divorce`, `custody`, `child_support`, `visitation`) but the workflow repo defines 12 entry workflows. Worse, **order of protection** and **restraining order** are listed as *unsupported* keywords while `order_of_protection_nyc` has the highest intake priority in the repo.

First-message users asking for OP, family offense, adoption, paternity, guardianship, received court papers, or ambiguous starters like “not sure which forms” may hit `domain_restricted` before routing runs.

## Requirements reference

- Master Decision Tree entry options (Workflow Routing Engine V1)
- PRD Ch. 5 — issue type → court → workflow
- Safety-sensitive workflows (OP, family offense) must be reachable immediately

## Current state

| Component | File |
|-----------|------|
| Supported issue types | `modules/ai-intake/class-supported-issue-catalog.php` |
| Domain guard | `modules/ai-intake/class-domain-scope-guard.php` |
| Service orchestration | `modules/ai-intake/class-ai-intake-service.php` |
| Tests | `modules/ai-intake/tests/DomainScopeGuardTest.php` |

Routing engine (`Intake_Agent` tests) already resolves all 12 workflows correctly when messages reach it.

## Scope

### In scope

- Expand `issue_types()` to match all 12 workflow `issue_type` values
- Remove OP/family offense from `unsupported_keywords`; keep truly out-of-scope topics (criminal, immigration, etc.)
- Add supported keywords for: OSC, received court papers, order to show cause, spousal maintenance, enforcement, modification, family offense, not sure / help me figure out
- Derive supported triggers from **all** workflows in catalog (not filtered by narrow issue list)
- Add PHPUnit cases for every previously-blocked entry phrase
- Update restriction copy to list all supported matter types accurately

### Out of scope

- New workflows JSON
- UI prompt chips (Plan 07)
- Document upload (Plan 11)

## Deliverables

1. Updated `Supported_Issue_Catalog`
2. Updated `Domain_Scope_Guard` tests (minimum 8 new cases)
3. Optional: filter `unsupported_keywords` so in-scope phrases never conflict with supported workflow triggers

## Acceptance criteria

- [x] “I need an order of protection in Queens” → `supported: true`, reaches interpreter
- [x] “My husband abused me” → routes toward family offense / OP (interpreter + routing)
- [x] “I received an OSC” / “I got court papers” → `supported: true`
- [x] “I am not sure which court forms I need” → `supported: true`
- [x] “I want to adopt a child” → `supported: true`
- [x] “What is the weather?” → still `domain_restricted`
- [x] Mid-intake short answers (“Brooklyn”, “2”, “yes”) still bypass guard
- [x] Hybrid messages (divorce + immigration) still supported with scope note

## Implementation tasks

1. Audit all 12 workflows’ `issue_type` and `triggers` from `docs/workflows/inventory.json`
2. Replace hardcoded 4-type list with catalog-driven issue types (or explicit list of 12)
3. Remove conflicting entries from `unsupported_keywords`
4. Add keyword weights for received-papers and ambiguous-intake starters
5. Extend `DomainScopeGuardTest` + add integration test via `AI_Intake_Service`
6. Run existing `IntakeAgentTest` — must remain green

## Files likely touched

- `modules/ai-intake/class-supported-issue-catalog.php`
- `modules/ai-intake/tests/DomainScopeGuardTest.php`
- Possibly `modules/ai-intake/tests/AiIntakeInterpreterTest.php`

## Risks

- Over-broadening scope guard could allow unrelated legal topics — mitigate with keep-list for clearly unsupported areas and confidence threshold tuning.

## Review questions

1. Should adoption/paternity/guardianship be **MVP in scope** for intake, or deferred with guard allowing but low priority?
2. Should “received court papers” route to a dedicated intake mode in Plan 11, or conversation-only for now?
