# Request for Comments (RFC)

RFCs are **discussion documents** for proposed architectural changes. They are reviewed before implementation — they do not replace implementation plans.

## RFC vs Plan vs ADR

| Document | Purpose | When |
|----------|---------|------|
| **RFC** | Propose and discuss a change | Before commitment |
| **Plan** | Define implementation tasks, acceptance criteria, files | After approval |
| **ADR** | Record the decision and rationale | After acceptance |

```
Idea → RFC (discussion) → Approved → Plan (implementation) → ADR (decision record)
```

## When an RFC is required

Submit an RFC before:

- New workflows or major workflow JSON structural changes
- Domain model changes (Case aggregate shape, new engines)
- Storage/schema changes beyond Plan 16 incremental migrations
- New AI capabilities or boundary changes
- External integrations (NYSCEF, e-filing, third-party APIs)
- Rules engine enhancements that change evaluation semantics
- Event sourcing or knowledge graph storage

Small bug fixes, copy changes, and plan-approved deliverables do **not** need an RFC.

## RFC template

Create `docs/rfc/NNN-short-title.md`:

```markdown
# RFC-NNN: Title

**Author:**  
**Status:** Draft | Under Review | Accepted | Rejected | Superseded  
**Created:** YYYY-MM-DD  

## Summary

One paragraph: what and why.

## Motivation

Problem, user need, or architectural gap.

## Proposal

Concrete description of the change.

## Compatibility

- Backward compatibility impact
- Migration path
- API changes (if any)

## Alternatives considered

## Open questions

## References

- Related plans, ADRs, issues
```

## Review process

1. Author opens RFC as Draft in `docs/rfc/`
2. Review against [Guiding Principles](../architecture/guiding-principles.md)
3. Check no contradiction with existing [ADRs](../adr/README.md) or [Plans](../plans/README.md)
4. Discuss revisions; update status
5. If **Accepted** → create or update implementation Plan; add or update ADR
6. If **Rejected** → record reason in RFC; keep for history

## Current RFCs

| RFC | Title | Status |
|-----|-------|--------|
| — | *No open RFCs* | — |

## Related

- [Platform Architecture](../architecture/platform-architecture.md)
- [Implementation Plans](../plans/README.md)
- [Architecture Decision Records](../adr/README.md)
