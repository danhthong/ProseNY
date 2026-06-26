# Plan 04 — Workflow Engine Hardening

**Status:** Complete  
**Priority:** P1  
**Depends on:** —  
**Estimated effort:** Medium (3–5 days)

---

## Goal

Complete the **Workflow Engine** as the procedural source of truth: resolution, progression, node/edge graph, and stage transitions — all driven from JSON, not hardcoded PHP.

Architecture: Implements the workflow state machine documented in [ADR-002](../adr/ADR-002-workflow-state-machine.md) and [Platform Architecture](../architecture/platform-architecture.md) §4.

## Requirements reference

- PRD Ch. 16–17 — Workflow Node Library, standardized nodes
- PRD Ch. 28–29 — Graph-based nodes and edges
- Workflow README — Phase 1 complete; Phase 2 engine builder

## Current state

| Done | Gap |
|------|-----|
| 12 workflow JSON files + schema | ~~Stage progression after intake incomplete~~ |
| `Workflow_Catalog` loader | ~~Node library in JSON `internal` only~~ |
| `Workflow_Resolver` + priority (routing) | ~~Edge rules not exposed~~ |
| `Workflow_Progression_Service` | Case package sequences still partially hardcoded |
| `validate-workflows.php` stricter checks | DB-backed graph seeders (Plan 16) |

## Scope

### In scope

- Formalize workflow graph: nodes, edges, conditions in JSON
- `Workflow_Progression_Service` — given workflow + facts + current stage → next stage
- Align `internal.node_sequence` across all 12 workflows with PRD node IDs
- Wire procedural navigator to read stages from resolved workflow
- Validation script checks node_sequence ↔ stages consistency
- Document stage model for discovery, settlement, trial (inside contested divorce)

### Out of scope

- Admin UI to edit workflows (Plan 14)
- Timeline deadlines (Plan 09)

## Deliverables

1. Workflow progression API: `get_current_stage()`, `get_next_stage()`, `get_stage_forms()`
2. Updated workflow JSON with `internal.progression` and contested `internal.edges`
3. Engine documentation in `docs/workflows/README.md`
4. PHPUnit: `WorkflowProgressionTest`, updated `CaseEngineTest` contested path

## Acceptance criteria

- [x] No workflow transition logic hardcoded in intake or AI modules
- [x] Contested divorce path: commencement → … → judgment reachable via graph
- [x] `validate-workflows.php` passes with stricter node checks
- [x] Procedural navigator consumes same stage list as package builder

## Files touched

- `modules/forms/engine/class-workflow-progression-service.php` (new)
- `modules/forms/engine/class-case-catalog.php`
- `modules/forms/engine/class-case-event-service.php`
- `modules/forms/engine/class-case-service.php`
- `modules/forms/engine/class-case-progress-service.php`
- `modules/procedural/class-guidance-resolver.php`
- `modules/procedural/class-procedural-navigator.php`
- `docs/workflows/**/*.json` — progression metadata on all 12 workflows
- `docs/workflows/schema/workflow.schema.json`
- `docs/workflows/README.md`
- `bin/validate-workflows.php`
- `tests/unit/WorkflowProgressionTest.php`
- `tests/unit/CaseEngineTest.php`

## Review questions

1. When overlap applies, show **one combined package** or **separate packages per court**?
