# ADR-003: AI Boundary

**Status:** Accepted  
**Date:** 2026-06-26  
**Related:** AGENTS.md, Plan 12, `prose-core/docs/ai/system-prompt.md`

## Context

CourtFlow uses OpenAI for natural conversation and fact extraction. Legal procedure must remain deterministic. Users may ask strategy questions; the product must not become an advice engine.

Forces:

- Conversational UX requires flexible replies
- Regulatory and product trust require hard boundaries
- Operators need auditable, versioned prompt policy

## Decision

Enforce a **strict AI boundary** via architecture, not prompt politeness alone:

1. **Pre-resolve (deterministic):** Routing, missing fields, workflow, package computed before OpenAI call
2. **Single OpenAI call:** Extract facts + generate reply; model receives authoritative context
3. **Post-resolve (deterministic):** Recompute all procedural outputs after fact merge
4. **Mode selection (deterministic):** `ask_question` vs `complete_intake` vs `guidance` — never model-chosen
5. **Injected read-only context:** `procedural_navigator`, `reference_knowledge`, `procedural_roadmap` — AI explains only
6. **Versioned system prompt** in repo with red-team tests

AI **may:** explain, summarize, educate, collect information, generate natural language.

AI **may not:** determine court/workflow/stage, override rules, recommend strategy, invent requirements, render roadmap in chat.

## Alternatives

| Alternative | Why not chosen |
|-------------|----------------|
| AI chooses workflow with human confirm | Still violates "AI never determines legal procedure" |
| Fine-tuned legal model | Does not solve boundary problem; higher risk |
| No AI — forms wizard only | Rejects product UX goals; deterministic fallback exists |

## Consequences

**Positive:**

- `Stub_Ai_Provider` enables offline/test installs
- Clear operator documentation
- Strategy questions get procedural explanation, not advice

**Negative:**

- Some users expect legal advice; scope disclaimers required
- Two pipelines to maintain (pre/post-resolve)

**Neutral:**

- Document intelligence may use LLM for **summary only**; classification stays engine-owned (Plan 11)

## Future impact

- Case Intelligence Engine outputs structured recommendations for AI to consume
- Any new AI capability requires RFC + prompt audit + boundary tests
- NYSCEF integration (Plan 19) must not delegate filing decisions to AI
