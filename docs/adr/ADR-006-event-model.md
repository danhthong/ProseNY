# ADR-006: Event Model

**Status:** Accepted (future implementation phases)  
**Date:** 2026-06-26  
**Related:** Plan 09, Plan 21, [Platform Architecture](../architecture/platform-architecture.md)

## Context

Case progression involves milestones: filed, served, answer received, conference held, judgment entered. Today, timeline stages derive largely from workflow JSON and partial lifecycle events on `case_profile`.

Forces:

- Timeline should reflect what actually happened, not just theoretical stages
- Audit and compliance need a clear history
- State mutations without history are hard to debug

## Decision

Adopt an **event-oriented procedural model** as the long-term direction:

1. Record discrete **procedural events** with type, timestamp, source, and payload
2. Derive **current stage, timeline, and progress** from events + workflow rules + deadline catalog
3. Timeline is a **projection**, not the primary store

**Not in scope for this ADR:** Full event sourcing, event store database, or CQRS. Plan 21 Phase A implements `lifecycle_events[]` on case profile as the first concrete step.

Example events: `case_created`, `facts_updated`, `workflow_selected`, `forms_generated`, `case_filed`, `service_completed`, `answer_received`, `judgment_entered`.

## Alternatives

| Alternative | Why not chosen |
|-------------|----------------|
| Mutable stage field only | Loses history; hard to audit |
| Full event sourcing now | Over-engineered for current MVP; incremental path preferred |
| AI-inferred events from chat | Unreliable; user confirmation or document rules required |

## Consequences

**Positive:**

- Aligns with Plan 21 lifecycle PATCH API
- Enables future notifications, analytics, and NYSCEF sync (Plan 19)
- Rollback via compensating events (future) without deleting history

**Negative:**

- Dual model during transition (stage field + events)
- Requires idempotent event handlers

**Neutral:**

- Document intelligence may emit events with `source: document` after rule-based classification

## Future impact

- Event Engine becomes first-class domain component (ADR-001)
- RFC required before event store schema or replay semantics
- Case Intelligence consumes event stream for warnings and recommendations
