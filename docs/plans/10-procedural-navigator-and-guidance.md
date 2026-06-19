# Plan 10 — Procedural Navigator & Guidance

**Status:** Complete  
**Priority:** P2  
**Depends on:** Plan 04, Plan 09  
**Estimated effort:** Medium (4–6 days)

---

## Goal

After intake, guide users through **next procedural steps** — filing, service, conferences, motions — using deterministic guidance, not AI strategy.

## Requirements reference

- PRD Ch. 24 — AI explains procedures; Rules Engine owns next steps
- Procedural chain: Stage → Forms → Deadlines → Next Step
- Guidance module + procedural navigator already started

## Current state

- `Procedural_Module`, `Procedural_Navigator`, tests exist
- `Guidance_Module` with stage JSON, county guidance resolver
- Chat gives filing help templates post-intake (generic)

## Scope

### In scope

- Procedural cards in chat: “What happens next”, “How to file”, “How to serve”
- Step content from guidance database / JSON seeds (not LLM-generated steps)
- Link each step to relevant forms in package
- County-specific filing notes where available (stub → Plan 13)
- REST: procedural next-step for current workflow + stage

### Out of scope

- Legal strategy (“should I file motion X?”)
- Motion practice wizard (future sub-plan)
- Full discovery engine UI

## Deliverables

1. Procedural step content for 4 divorce + top 4 family court workflows
2. Chat cards rendered from `Procedural_Navigator` output
3. “Next step” in context panel matches navigator
4. PHPUnit: `ProceduralNavigatorTest` extended

## Acceptance criteria

- [x] User asks “what happens next” after uncontested intake → stage-specific guidance
- [x] Guidance cites correct court and forms
- [x] AI conversation layer paraphrases navigator content, cannot invent steps
- [x] Same next-step whether user uses chat or reads context panel

## Implementation tasks

1. Audit guidance seed data vs workflow stages
2. Fill content gaps for NYC divorce filing + service
3. Wire navigator to chat `card` response type in Plan 02 mapper
4. Add entry points for enforcement/modification as in-workflow stages
5. Content review for non-advice compliance

## Files likely touched

- `modules/procedural/`
- `modules/guidance/`
- `modules/ai-intake/class-ai-intake-interpreter.php` (guidance context only)
- `themes/prose-app/build/courtflow.js` (card rendering)

## Review questions

1. Who provides **approved procedural copy** — dev seeds vs legal review workflow?
