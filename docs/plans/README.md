# CourtFlow AI — Implementation Plans

Review this index first. Each plan is a self-contained file you can approve, revise, or defer before any code is written.

## Core rule (all plans)

**Rules Engine decides** court, workflow, required forms, and next steps.  
**AI only** explains, collects facts, summarizes, and assists. Never hardcode workflow logic in PHP/JS when it belongs in workflow JSON or the database.

## How to use these plans

1. Read plans in **recommended order** (dependencies flow top → bottom).
2. Mark each plan: **Approve**, **Revise**, or **Defer**.
3. Implementation proceeds **one approved plan at a time**.
4. After each plan ships, update its **Status** line at the top of that file.

## Recommended execution order

| # | Plan | Priority | Depends on | Est. effort |
|---|------|----------|------------|-------------|
| 01 | [Intake domain scope & entry points](./01-intake-domain-scope-and-entry-points.md) | **P0 — done** | — | Small |
| 02 | [Workspace chat API unification](./02-workspace-chat-api-unification.md) | **P0 — done** | 01 | Medium |
| 03 | [Court routing & overlap UX](./03-court-routing-and-overlap-ux.md) | **P1 — done** | 01 | Medium |
| 04 | [Workflow engine hardening](./04-workflow-engine-hardening.md) | **P1 — done** | — | Medium |
| 05 | [Forms library & form mapping](./05-forms-library-and-form-mapping.md) | **P1 — done** | 04 | Large |
| 06 | [Package builder & filing package](./06-package-builder-and-filing-package.md) | **P1 — done** | 05 | Medium |
| 07 | [Intake chat UX & prompts](./07-intake-chat-ux-and-prompts.md) | **P1 — done** | 01, 02 | Medium |
| 08 | [User dashboard & case summary](./08-user-dashboard-and-case-summary.md) | **P1 — done** | 02, 06 | Medium |
| 09 | [Timeline engine](./09-timeline-engine.md) | **P2 — done** | 04, 08 | Medium |
| 10 | [Procedural navigator & guidance](./10-procedural-navigator-and-guidance.md) | **P2 — done** | 04, 09 | Medium |
| 11 | [Document intelligence](./11-document-intelligence.md) | **P2 — done** | 04, 05 | Large |
| 12 | [AI procedural assistant](./12-ai-procedural-assistant.md) | **P2 — done** | 01, 10 | Medium |
| 13 | [County rules layer](./13-county-rules-layer.md) | P2 — done | 05 | Medium |
| 14 | [Admin dashboard](./14-admin-dashboard.md) | P3 — done | 04, 05, 13 | Large |
| 15 | [Search & knowledge center](./15-search-and-knowledge-center.md) | P3 — done | 05, 10 | Medium |
| 16 | [Database schema & case persistence](./16-database-schema-and-case-persistence.md) | P2 — done | 02 | Large |
| 17 | [Security, verification & compliance](./17-security-verification-and-compliance.md) | P2 — done | 05, 14 | Medium |
| 18 | [MVP acceptance & QA](./18-mvp-acceptance-and-qa.md) | P1 — done | 01–08 min | Medium |
| 19 | [NYSCEF integration (Phase 2)](./19-nyscef-integration-phase-2.md) | Future | 16, 17 | Large |
| 20 | [Statewide expansion (Phase 3)](./20-statewide-expansion-phase-3.md) | Future | 19 | Large |

## MVP definition (Phase 1)

From `docs/requires/` and `AGENTS.md`:

- **Geography:** NYC 5 counties only (Manhattan, Brooklyn, Queens, Bronx, Staten Island).
- **Courts:** Supreme Court (matrimonial) + Family Court + overlap scenarios.
- **Entry workflows:** 12 JSON workflows in `prose-core/docs/workflows/` (4 divorce + 8 family court).
- **User outcome:** Pro se litigant completes intake → sees correct court/workflow → gets blank filing package → understands next procedural step.
- **Not in MVP:** NYSCEF e-filing, statewide counties, legal strategy, attorney replacement.

## Current codebase map

| Area | Location | Plan |
|------|----------|------|
| Workflow JSON (source of truth) | `prose-core/docs/workflows/` | 04 |
| Routing engine | `prose-core/modules/routing/` | 03, 04 |
| Deterministic intake | `prose-core/modules/intake/` | 01, 07 |
| AI intake interpreter | `prose-core/modules/ai-intake/` | 01, 12 |
| Forms engine & catalog | `prose-core/modules/forms/` | 05 |
| Package builder | `prose-core/modules/packagebuilder/` | 06 |
| Procedural navigator | `prose-core/modules/procedural/` | 10 |
| Guidance engine | `prose-core/modules/guidance/` | 10, 13 |
| County rules seed | `docs/county-rules/` | 13 |
| Search & knowledge center | `prose-core/modules/search/`, `docs/knowledge-center/` | 15 |
| Security (rate limit, audit) | `prose-core/modules/security/` | 17 |
| Case persistence schema | `docs/plans/schema/case-persistence.sql` | 16 |
| MVP QA matrix | `docs/plans/qa/mvp-test-matrix.md` | 18 |
| Assembly / PDF fill | `prose-core/modules/assembly/` | 06 |
| Workspace UI | `themes/prose-app/` (blocks, `build/courtflow.js`) | 02, 07, 08 |
| Homepage intake widget | `[prose_intake_chat]` shortcode | 01, 07 |
| Requirements docs | `docs/requires/*.docx` | All |

## What is already done

- [x] Workflow repository — 12 NYC workflows, schema, validation script
- [x] Routing engine pipeline (intent → issue → court → workflow → missing fields)
- [x] Deterministic `Intake_Agent` with PHPUnit coverage for all 12 workflows
- [x] AI `Conversation_Engine` with deterministic pre/post-resolve
- [x] Forms JSON catalog (hundreds of OCA forms under `docs/forms/`)
- [x] Package preview / merged blank PDF (partial)
- [x] Workspace shell UI (progress rail, chat, context panel blocks)

## Known blockers (fix in plans 01–02)

1. **Domain scope guard** blocks valid entry paths (OP, family offense, “not sure”, received papers).
2. **Workspace chat** calls `courtflow/v1/sessions/*` — **no backend registered**; homepage uses `prose/v1/intake/interpret` instead.

## Related docs

- Requirements: `docs/requires/`
- UI specs: `docs/ui/`
- Workflow repo README: `prose-core/docs/workflows/README.md`
- Agent rules: `AGENTS.md`
