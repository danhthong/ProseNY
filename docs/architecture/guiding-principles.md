# Guiding Principles

Immutable platform principles for CourtFlow AI. Use these as the reference for architectural decisions, code reviews, and RFC evaluations.

These principles **do not replace** `AGENTS.md` or the implementation plans. They explain *why* those rules exist.

---

## 1. Workflow before AI

Procedural flow is defined in workflow JSON and the Workflow Engine. AI explains and collects information; it never selects or advances workflows.

**Implication:** New procedural phases belong in workflow `stages[]` and `internal` graph metadata — not in prompts.

## 2. Rules before Prompts

Court routing, overlap resolution, form requirements, and lifecycle branching are computed by the Rules Engine from facts and configuration. Prompts receive the output; they do not produce it.

**Implication:** If a decision affects which court, workflow, forms, or stage applies, it belongs in rules — not in `Conversation_Engine`.

## 3. Domain before Interface

Business logic lives in domain services (routing, progression, timeline, package assembly). UI and REST adapters consume domain outputs without re-deriving procedure.

**Implication:** Theme blocks and REST controllers should not embed routing or stage-transition logic.

## 4. Configuration before Code

Workflow definitions, county rules, deadline catalogs, and knowledge articles are data. PHP/JS implements engines that read configuration — not hardcoded procedure.

**Implication:** Prefer extending JSON, seeds, or database configuration over new conditional branches in application code.

## 5. Events before State Mutation

Lifecycle changes should be recorded as procedural events (user confirmed filing, service completed, answer received). Derived state (current stage, timeline, progress) is computed from events and rules.

**Implication:** Plan 21 lifecycle checkpoints follow this direction. Full event sourcing is future scope; the principle guides API design now.

## 6. Knowledge separate from Workflow

Educational content, filing instructions, FAQs, and county notes live in the Knowledge Layer. Workflows **reference** knowledge by key or topic; knowledge does not determine workflow selection.

**Implication:** Knowledge Center articles and guidance seeds must not embed routing logic. Routing stays in workflow JSON and `modules/routing/`.

## 7. AI consumes decisions rather than creates them

The AI Assistant receives structured outputs: resolved workflow, missing fields, procedural navigator, roadmap, reference knowledge, and (future) Case Intelligence recommendations. It explains and gathers; it does not override.

**Implication:** See [ADR-003](../adr/ADR-003-ai-boundary.md) and `prose-core/docs/ai/system-prompt.md`.

## 8. Forms Library is authoritative

Official form codes, stage mappings, and package composition come from the Forms Repository and workflow `required_forms`. AI must not invent form requirements.

## 9. Package Builder is deterministic

Given workflow, stage, county, and facts, the package output is reproducible. No LLM involvement in which PDFs are included.

## 10. Timeline reflects workflow progress

The Timeline Engine projects stages, tasks, and deadlines from workflow definitions, lifecycle events, and the deadline catalog — not from conversation history.

## 11. Backward compatibility is mandatory

Architecture evolution extends the platform; it does not invalidate completed plans or break existing APIs without an explicit migration plan and RFC.

## 12. Incremental roadmap

Implementation proceeds one approved plan at a time. Architectural concepts may be documented before they are implemented.

---

## Applying these principles

| Question | Check against |
|----------|---------------|
| Should AI do this? | Principles 1, 2, 7 |
| Where does this logic live? | Principles 3, 4 |
| How do we track lifecycle changes? | Principle 5 |
| Is this routing or education? | Principle 6 |
| Does this change workflow JSON? | RFC required — see [RFC process](../rfc/README.md) |

## Related documents

- [Platform Architecture](./platform-architecture.md)
- [AGENTS.md](../../AGENTS.md)
- [Implementation Plans](../plans/README.md)
- [Architecture Decision Records](../adr/README.md)
