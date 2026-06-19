# Plan 12 — AI Procedural Assistant

**Status:** Complete  
**Priority:** P2  
**Depends on:** Plan 01, Plan 10  
**Estimated effort:** Medium (3–5 days)

---

## Goal

Harden the **AI Procedural Assistant** so it explains forms, workflows, and deadlines within strict boundaries — never legal strategy, never overriding the Rules Engine.

## Requirements reference

- PRD Ch. 24 — AI Procedural Assistant
- AGENTS.md core rule
- Business idea doc — procedural writing assistance, neutral tone

## Current state

- `Conversation_Engine` with ROLE_GUIDANCE constraints
- `AI_Settings` system prompt
- Escalation detector, consistency checker, clarification engine
- Stub provider for tests

## Scope

### In scope

- System prompt audit against requirements
- Inject navigator/guidance content into AI context (read-only)
- Procedural writing help: explain form sections, neutral wording (no strategy)
- Escalation paths: repeated confusion → `needs_review`
- Admin: AI usage logging page (exists — verify)
- Cost/rate limits and error fallbacks to deterministic messages

### Out of scope

- Fine-tuning custom models
- Attorney referral marketplace

## Deliverables

1. Prompt template versioned in repo (`docs/ai/system-prompt.md`)
2. Red-team test cases: AI must refuse strategy questions
3. Fallback when OpenAI unavailable → deterministic intake still works
4. Documentation for operators: what AI can/cannot say

## Acceptance criteria

- [x] “Should I ask for sole custody?” → procedural info only, no recommendation
- [x] “What is UD-1 for?” → explains purpose, does not invent requirements
- [x] Workflow/court/package never change based on AI reply alone
- [x] All AI turns logged with latency (admin page)

## Implementation tasks

1. Review and tighten `Conversation_Engine::ROLE_GUIDANCE`
2. Add strategy-refusal examples to tests
3. Wire `Procedural_Navigator` output into converse payload
4. Document fallback chain: AI fail → `build_gathering_fallback`
5. Optional: procedural rewrite mode for form text (separate intent)

## Files likely touched

- `modules/ai-intake/class-conversation-engine.php`
- `modules/ai-intake/class-ai-settings.php`
- `modules/ai-intake/class-escalation-detector.php`
- `modules/ai-intake/tests/`

## Review questions

1. Allow **procedural drafting suggestions** (sentence-level) in MVP?
