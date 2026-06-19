# Plan 18 — MVP Acceptance & QA

**Status:** Draft — awaiting review  
**Priority:** P1 (gate before launch)  
**Depends on:** Plans 01–08 minimum  
**Estimated effort:** Medium (4–6 days)

---

## Goal

Define and execute **MVP acceptance** so Phase 1 NYC launch meets requirements with measurable success metrics.

## Requirements reference

- PRD Ch. 25 — MVP Roadmap (NYC 5 counties)
- PRD Ch. 26 — Success Metrics: workflow completion, procedural accuracy, retention, satisfaction
- PRD Ch. 37 — MVP Scope

## MVP minimum feature set (launch gate)

| # | Capability | Plan |
|---|------------|------|
| 1 | All 12 entry workflows reachable via chat | 01, 07 |
| 2 | Workspace chat functional | 02 |
| 3 | Court routing + overlap explained | 03 |
| 4 | Intake → workflow → completion → case summary | 01, 08 |
| 5 | Blank filing package download | 06 |
| 6 | Procedural next-step guidance (basic) | 10 |
| 7 | NYC 5 counties | 13 (minimal) |
| 8 | AI boundary compliance | 12 |
| 9 | Security baseline | 17 (partial) |

## Test matrix

### Intake routing (automated)

Run existing PHPUnit suites plus new cases:

- `IntakeAgentTest` — all 12 workflows
- `DomainScopeGuardTest` — all entry phrases
- `AiIntakeInterpreterTest` — stub provider paths
- `CourtResolutionTest` — overlap scenarios

### Manual UAT scripts (per workflow)

For each workflow, script:

1. Start from prompt chip or free text
2. Answer required fields (county + workflow-specific)
3. Verify workflow key, court, completion 100%
4. Download package; confirm forms list
5. Ask “what happens next”; verify procedural response

Workflows to UAT:

- [ ] uncontested_divorce_no_children_nyc
- [ ] uncontested_divorce_children_nyc
- [ ] contested_divorce_nyc
- [ ] default_divorce_nyc
- [ ] custody_nyc
- [ ] visitation_nyc
- [ ] child_support_nyc
- [ ] family_offense_nyc
- [ ] order_of_protection_nyc
- [ ] paternity_nyc
- [ ] guardianship_nyc
- [ ] adoption_nyc

### Cross-cutting scenarios

- [ ] Divorce + children + Kings County
- [ ] Active divorce + custody routing_rules
- [ ] Matter switch mid-intake (support → divorce)
- [ ] Domain blocked: unrelated topic
- [ ] AI provider down → deterministic fallback
- [ ] Mobile viewport workspace + homepage

## Success metrics (instrumentation)

| Metric | Target (MVP) | How measured |
|--------|--------------|--------------|
| Workflow completion rate | Track baseline | intake_complete / sessions started |
| Procedural accuracy | 100% on test matrix | automated + manual |
| Package download rate | Track baseline | downloads / intake_complete |
| AI escalation rate | < 10% | needs_review / turns |

## Deliverables

1. `docs/plans/qa/mvp-test-matrix.md` (checklist export from this plan)
2. CI runs PHPUnit on PR
3. Sign-off document with known limitations list
4. Launch blocker bug list triaged

## Acceptance criteria

- [ ] All P0 plans (01, 02) complete
- [ ] Test matrix 100% pass on staging
- [ ] Known limitations documented and approved
- [ ] No P0/P1 bugs open

## Review questions

1. Which workflows are **launch-critical** vs **supported but beta**?
2. Accept launch with **blank forms only** (no auto-fill)?
