# Architecture Decision Records (ADR)

Architecture Decision Records explain **why** major architectural choices were made. They are not implementation plans.

## Index

| ADR | Title | Status |
|-----|-------|--------|
| [ADR-001](./ADR-001-domain-layer.md) | Domain Layer | Accepted |
| [ADR-002](./ADR-002-workflow-state-machine.md) | Workflow State Machine | Accepted |
| [ADR-003](./ADR-003-ai-boundary.md) | AI Boundary | Accepted |
| [ADR-004](./ADR-004-rules-engine.md) | Rules Engine | Accepted |
| [ADR-005](./ADR-005-knowledge-layer.md) | Knowledge Layer | Accepted |
| [ADR-006](./ADR-006-event-model.md) | Event Model | Accepted (future implementation) |
| [ADR-007](./ADR-007-case-aggregate.md) | Case Aggregate | Accepted |

## Format

Each ADR contains:

- **Context** — problem or forces at play
- **Decision** — what was decided
- **Alternatives** — options considered
- **Consequences** — positive, negative, and neutral outcomes
- **Future impact** — how this affects later work

## When to add an ADR

Create a new ADR when:

- Introducing a new architectural layer or engine
- Changing AI boundaries or trust model
- Changing the source of truth for workflows, rules, or forms
- Adopting event sourcing, graph storage, or major persistence changes

RFCs propose; ADRs record accepted decisions after discussion.

## Related

- [Guiding Principles](../architecture/guiding-principles.md)
- [Platform Architecture](../architecture/platform-architecture.md)
- [RFC Process](../rfc/README.md)
