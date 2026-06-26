# ADR-005: Knowledge Layer

**Status:** Accepted  
**Date:** 2026-06-26  
**Related:** Plan 10, Plan 13, Plan 15, `docs/knowledge-center/`

## Context

Users need educational content, filing instructions, county notes, and FAQs alongside deterministic workflow navigation. Content must not silently change which court or forms apply.

Forces:

- Divorce Ecosystem Manual describes extensive knowledge base requirements (§23)
- RAG/search can surface content but must not replace rules
- County differences are instructional, not routing (for MVP)

## Decision

Establish a **Knowledge Layer** separate from workflow logic:

| Contains | Does not contain |
|----------|------------------|
| Filing instructions, FAQs, articles | `routing_rules` |
| County/court notes | `required_fields` |
| Examples, references, videos (future) | Stage transition logic |
| Guidance seeds for navigator | Workflow selection |

Workflows and the Procedural Navigator **reference** knowledge by topic, stage, or article key. The Knowledge Engine (`modules/guidance/`, `modules/search/`, knowledge articles) serves content; the Rules Engine serves decisions.

AI receives `reference_knowledge` and `procedural_navigator` as read-only context (ADR-003).

## Alternatives

| Alternative | Why not chosen |
|-------------|----------------|
| Embed all copy in workflow JSON | Bloats procedural source of truth |
| AI-generated filing instructions | Non-deterministic; hard to audit |
| Knowledge determines workflow via NLP | Violates core architecture |

## Consequences

**Positive:**

- Content team can expand Knowledge Center without code deploys (future CMS)
- Search indexes forms + workflows + articles (Plan 15)
- County rules (Plan 13) fit naturally as knowledge + light metadata

**Negative:**

- Must keep knowledge in sync when forms or procedures change — admin tooling (Plan 14) helps

**Neutral:**

- Legal Knowledge Graph (future) links entities for discovery, not routing

## Future impact

- Graph complements RAG; does not replace `docs/workflows/` or `docs/forms/`
- Multilingual content is knowledge-layer concern
- Plan 20 statewide content expands knowledge, not workflow engine architecture
