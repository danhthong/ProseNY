# Plan 19 — NYSCEF Integration (Phase 2)

**Status:** Draft — deferred  
**Priority:** Future (post-MVP)  
**Depends on:** Plan 16, Plan 17  
**Estimated effort:** Large (3+ weeks)

---

## Goal

Integrate with **NYSCEF** for e-filing, docket tracking, and order retrieval for unrepresented litigants in NYC counties.

## Requirements reference

- PRD Ch. 38 — Phase 2 Scope
- PRD Ch. 25 — Phase 2: NYSCEF Integration
- Divorce Ecosystem — NYSCEF unrepresented litigants

## Prerequisites

- MVP launched (Plan 18)
- Case persistence (Plan 16)
- Security baseline (Plan 17)
- Legal/compliance review of e-filing automation boundaries

## Scope (high level)

### In scope

- Research NYSCEF API/access for unrepresented filers (or guided manual e-file workflow)
- Link case to NYSCEF index number
- Upload generated package to NYSCEF where permitted
- Pull docket entries → update timeline (Plan 09)
- Notify user of new filings/orders

### Out of scope

- Automated filing without user confirmation
- Non-NYC courts initially

## Deliverables

1. NYSCEF integration design doc
2. Proof-of-concept: link case + display docket read-only
3. User flow: “File via NYSCEF” step-by-step guide with deep links
4. Full integration phased by document type

## Acceptance criteria

- TBD after design phase and API access confirmed

## Risks

- NYSCEF may not expose public API — may be **guided manual** integration only
- Authentication and attorney vs pro se differences

## Review questions

1. Is Phase 2 **read-only docket sync** acceptable first milestone?
