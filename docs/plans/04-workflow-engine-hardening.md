# Plan 04 — Workflow Engine Hardening

**Status:** Draft — awaiting review  
**Priority:** P1  
**Depends on:** —  
**Estimated effort:** Medium (3–5 days)

---

## Goal

Complete the **Workflow Engine** as the procedural source of truth: resolution, progression, node/edge graph, and stage transitions — all driven from JSON, not hardcoded PHP.

## Requirements reference

- PRD Ch. 16–17 — Workflow Node Library, standardized nodes
- PRD Ch. 28–29 — Graph-based nodes and edges
- Workflow README — Phase 1 complete; Phase 2 engine builder

## Current state

| Done | Gap |
|------|-----|
| 12 workflow JSON files + schema | Stage **progression** after intake incomplete |
| `Workflow_Catalog` loader | Node library (NODE_1001–1010) in JSON `internal` only |
| `Workflow_Resolver` + priority | Edge rules / conditional transitions not fully exposed |
| `validate-workflows.php` | Enforcement/modification as stages only — need stage metadata |

## Scope

### In scope

- Formalize workflow graph: nodes, edges, conditions in JSON or DB seed
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
2. Updated workflow JSON where `internal.node_sequence` incomplete
3. Engine documentation in `docs/workflows/README.md`
4. PHPUnit coverage for stage transitions on contested + uncontested divorce

## Acceptance criteria

- [ ] No workflow transition logic hardcoded in intake or AI modules
- [ ] Contested divorce path: commencement → … → judgment reachable via graph
- [ ] `validate-workflows.php` passes with stricter node checks
- [ ] Procedural navigator consumes same stage list as package builder

## Implementation tasks

1. Inventory existing `modules/forms/engine/` vs `modules/routing/` overlap — consolidate ownership
2. Define edge condition vocabulary (reuse `Condition_Evaluator` if exists)
3. Implement progression service
4. Seed node metadata from PRD V3 Unified (NODE_1001–1010, etc.)
5. Tests + validation script update

## Files likely touched

- `modules/routing/` or `modules/forms/engine/class-workflow-resolver.php`
- `docs/workflows/schema/workflow.schema.json`
- `bin/validate-workflows.php`
- `modules/procedural/class-procedural-navigator.php`

## Review questions

1. Keep workflow graph in **JSON files** for MVP or migrate to **DB tables** (Plan 16)?
