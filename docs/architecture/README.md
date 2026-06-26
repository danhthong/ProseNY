# CourtFlow AI — Platform Architecture

Long-term platform architecture documentation. This folder describes **what the platform is** and **how it evolves** — not implementation tasks.

## Start here

| Document | Purpose |
|----------|---------|
| [Guiding Principles](./guiding-principles.md) | Immutable decision rules for all future work |
| [Platform Architecture](./platform-architecture.md) | Layered architecture, domain model, engines, and future evolution |
| [Architecture Decision Records](../adr/README.md) | Why major decisions were made |
| [RFC Process](../rfc/README.md) | How to propose architectural changes |
| [Implementation Plans](../plans/README.md) | What to build and in what order |
| [Reference](../reference/README.md) | Workflows, forms, rules, knowledge, metadata |

## Relationship to other documentation

```
AGENTS.md                    → Product rules (read first)
docs/architecture/           → Platform architecture (this folder)
docs/adr/                    → Decision history
docs/rfc/                    → Proposed changes (discussion)
docs/plans/                  → Implementation roadmap
docs/reference/              → Authoritative specifications
prose-core/docs/workflows/   → Workflow JSON source of truth
prose-core/docs/forms/       → Forms catalog source of truth
docs/knowledge-center/       → Knowledge article content
```

## Core vision (unchanged)

CourtFlow AI is a **procedural navigation platform** — workflow driven, data driven, forms driven, rule based, and AI assisted.

It is **not** a legal advice platform, an autonomous legal AI, or an AI-first system. The platform remains the source of truth; AI is an assistant.

## Architectural layers

```
┌─────────────────────────────────────────────────────────┐
│  Application Layer                                      │
│  Workspace UI · Dashboard · Intake Chat · Admin         │
├─────────────────────────────────────────────────────────┤
│  Domain Layer (conceptual — see platform-architecture)  │
│  Case · Workflow · Rules · Timeline · Knowledge · Events│
├─────────────────────────────────────────────────────────┤
│  Infrastructure Layer                                   │
│  WordPress · REST API · Database · PDF · OpenAI         │
└─────────────────────────────────────────────────────────┘
```

The Domain Layer is documented as an architectural concept. Existing modules in `prose-core/modules/` map to domain responsibilities today; a dedicated domain package is a future evolution, not a breaking change.

## Status of concepts in this folder

| Concept | Status |
|---------|--------|
| Rules Engine, Workflow Engine, Forms Library | **Implemented** (Plans 03–06, 04) |
| Timeline Engine, Knowledge Center | **Implemented** (Plans 09, 15) |
| Case persistence | **Implemented** (Plan 16) |
| Domain Layer packaging | **Documented** — future consolidation |
| Case Aggregate (conceptual model) | **Documented** — aligns with `case_profile` |
| Event Model | **Documented** — future direction; timeline is partial projection |
| Case Intelligence Engine | **Documented** — future service |
| Legal Knowledge Graph | **Documented** — complements RAG; does not replace repositories |
| Case Memory (rich fact metadata) | **Documented** — partial in intake state today |

Nothing in this folder invalidates completed work or changes implementation priorities.
