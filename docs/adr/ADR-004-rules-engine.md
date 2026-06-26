# ADR-004: Rules Engine

**Status:** Accepted  
**Date:** 2026-06-26  
**Related:** Plan 03, Plan 13, Plan 21, `modules/routing/`

## Context

Users arrive with varied intents across Supreme Court and Family Court matters, including overlap scenarios (divorce + custody, divorce + OP). Procedure must be consistent, auditable, and data-driven.

Forces:

- 12 entry workflows with `routing_rules` and priority fields
- County-specific instructions without county-specific routing (MVP)
- Lifecycle branching after filing (default vs contested)

## Decision

The **Rules Engine** (`modules/routing/` + workflow JSON rules + county/deadline catalogs) is the sole authority for:

- Court assignment and overlap detection
- Workflow selection and re-routing when facts change
- Required fields and intake completion
- Lifecycle branch suggestions (informational, rule-based — Plan 21)

Rules are **reusable configuration objects** with documented inputs, outputs, prerequisites, priority, and effects. See [reference/rules.md](../reference/rules.md).

AI and UI **display** rule outputs; they do not compute alternatives unless explicitly running the engine again with new facts.

## Alternatives

| Alternative | Why not chosen |
|-------------|----------------|
| Rules in PHP conditionals | Violates "configuration before code" |
| Rules in AI system prompt | Non-deterministic; unauditable |
| Per-county workflow files | Explodes maintenance; county rules layer preferred |

## Consequences

**Positive:**

- PHPUnit coverage (`RoutingEngineTest`, overlap tests)
- New workflows primarily add JSON + seeds, not engine forks
- Clear separation for Plan 13 county rules and Plan 21 lifecycle rules

**Negative:**

- Complex overlap UX still requires careful copy (not AI-generated routing)

**Neutral:**

- `routing_priority` vs `intake_priority` serve different engines — both required

## Future impact

- Case Intelligence Engine wraps rule evaluation for unified recommendations
- Statewide expansion (Plan 20) adds county/court configuration, not new engine type
- Legal Knowledge Graph links to rules for explanation, not evaluation
