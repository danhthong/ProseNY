# ADR-002: Workflow State Machine

**Status:** Accepted  
**Date:** 2026-06-26  
**Related:** Plan 04, Plan 21, `prose-core/docs/workflows/`

## Context

Workflow procedure is defined in 12 JSON files with `stages[]`, `internal.node_sequence`, `internal.progression`, and `internal.edges`. Plan 04 implemented `Workflow_Progression_Service` as the runtime reader. Plan 21 adds post-filing lifecycle stages.

Forces:

- Stages must not be hardcoded in PHP or AI prompts
- Contested divorce requires branching (settlement vs trial)
- Users need predictable progression with validation and prerequisites

## Decision

Treat each workflow as a **deterministic state machine**:

- **States** = `stages[]` slugs + lifecycle milestones (Plan 21)
- **Transitions** = `internal.progression[]` and `internal.edges[]` evaluated by `Workflow_Progression_Service` and lifecycle rules
- **Completion** = required facts satisfied + progression triggers met
- **Validation** = `validate-workflows.php` + runtime fact checks
- **Rollback** = not user-facing in MVP; future via compensating events (see ADR-006)

Document the state model in architecture docs; **do not modify workflow JSON** unless a plan explicitly requires it.

## Alternatives

| Alternative | Why not chosen |
|-------------|----------------|
| AI-inferred stage from conversation | Violates core rule; non-deterministic |
| Separate workflow per phase (discovery, trial) | Rejected in workflow README — phases are stages |
| BPMN engine external to JSON | Adds complexity; JSON graph is sufficient |

## Consequences

**Positive:**

- Package Builder, Navigator, and Timeline share one stage list
- Testable transitions via PHPUnit (`WorkflowProgressionTest`)
- Plan 21 lifecycle plugs into same progression service

**Negative:**

- Lifecycle + intake stages require careful UX (two rails or handoff — Plan 21 Phase B)

**Neutral:**

- Optional branches exposed but not auto-selected by AI

## Future impact

- Admin workflow editor (Plan 14) edits the same graph structure
- Event Engine may trigger transitions without user manually advancing stage
- State machine documentation is the contract for new workflows (Family Court + statewide)
