# ADR-001: Domain Layer

**Status:** Accepted  
**Date:** 2026-06-26  
**Related:** [Platform Architecture](../architecture/platform-architecture.md), Plans 04, 16

## Context

CourtFlow AI procedural logic is spread across `modules/routing/`, `modules/forms/engine/`, `modules/procedural/`, `modules/guidance/`, and `modules/ai-intake/`. This works for MVP but increases coupling risk as lifecycle, timeline, and intelligence features grow (Plans 09, 21).

Forces:

- Application UI must not embed routing or stage logic
- Multiple engines read the same workflow JSON and case facts
- Long-term maintainability requires a clear home for procedural business rules

## Decision

Document a **Domain Layer** between Application and Infrastructure with six conceptual engines:

1. Case Engine
2. Workflow Engine
3. Rules Engine
4. Timeline Engine
5. Knowledge Engine
6. Event Engine

The Domain Layer describes **responsibilities only**. Existing modules map to these engines today. A future PHP namespace consolidation is optional and must preserve public APIs.

## Alternatives

| Alternative | Why not chosen |
|-------------|----------------|
| Immediate rewrite into `Domain/` package | Breaks incremental roadmap; high risk for no user value |
| Keep logic in UI modules | Violates "Domain before Interface" principle |
| Microservices per engine | Over-engineered for current scale; WordPress monolith is correct |

## Consequences

**Positive:**

- Clear ownership for new features (lifecycle → Event Engine + Case Engine)
- Easier onboarding — one architecture doc maps modules to concepts
- RFCs can reference domain boundaries

**Negative:**

- Temporary duplication until consolidation (two names for same code paths)

**Neutral:**

- No database or API changes required by this ADR alone

## Future impact

- New domain services should align with engine boundaries
- Case Intelligence Engine (future) sits in Domain Layer, consumes Rules + Workflow + Events
- Plan 16 repositories become Infrastructure; domain services use them via interfaces
