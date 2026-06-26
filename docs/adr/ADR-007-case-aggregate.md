# ADR-007: Case Aggregate

**Status:** Accepted  
**Date:** 2026-06-26  
**Related:** Plan 08, Plan 16, Plan 21

## Context

User procedural matters involve workflow, facts, forms, packages, timeline, documents, events, and AI context. Without a unifying concept, features attach ad hoc to session state or disparate tables.

Forces:

- Dashboard and workspace need one case identity
- Plan 16 introduces `prose_cases` persistence
- Plan 21 extends case with lifecycle stage and events

## Decision

Document the **Case** as the primary domain aggregate — a conceptual root object containing:

workflow, facts, forms, packages, timeline, documents, events, court, county, AI context, audit history, and progress.

This ADR does **not** require a single database table for all data. Plan 16's normalized tables (`prose_cases`, `prose_case_messages`, `prose_case_documents`, `prose_case_deadlines`) are an infrastructure mapping of the aggregate.

`case_profile` in REST/session is the application DTO of the aggregate.

## Alternatives

| Alternative | Why not chosen |
|-------------|----------------|
| Session-only ephemeral state | Blocks dashboard, cross-device, Plan 16 |
| One JSON blob per case | Hard to query deadlines/documents; Plan 16 splits appropriately |
| Case per workflow stage | Wrong boundary — one matter, one case, many stages |

## Consequences

**Positive:**

- Clear API contract for workspace and dashboard
- Lifecycle features attach to case, not chat session alone
- Case Memory (facts with metadata) has a natural owner

**Negative:**

- Guest vs logged-in case migration complexity (Plan 16)

**Neutral:**

- Overlap scenarios may show multiple courts within one case aggregate

## Future impact

- Case Intelligence Engine operates on the aggregate
- Parallel matter tracks (Plan 21 dual court) are views on one or linked cases — RFC if split-case model needed
- Export/portability (Plan 17) exports the aggregate shape
