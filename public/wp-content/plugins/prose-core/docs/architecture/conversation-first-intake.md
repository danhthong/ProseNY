# Conversation-First AI Intake — Architectural Review

Part of the Application Layer. See also [Conversational AI Intake](./conversational-ai-intake.md) and [ADR-003 AI Boundary](../../../docs/adr/ADR-003-ai-boundary.md).

## Executive summary

ProSeNY already had a **single-call conversational AI path** (`AI_Intake_Interpreter` → `Conversation_Engine`), but several layers still treated workflow JSON as a **conversation script**. This review documents those failure modes and the refactor that separates **reasoning** (ChatGPT) from **workflow authority** (Workflow Engine).

**Design principle:** Conversation first. Reasoning first. Workflow second. Forms third.

```
User
  ↓
ChatGPT (extract facts + natural reply)
  ↓
Workflow Engine (identify workflow, stage, forms, missing facts)
  ↓
Case Memory (structured snapshot)
  ↓
ChatGPT response context
  ↓
User
```

Workflow JSON remains the data source. It is never read aloud as a questionnaire.

---

## Architectural problems identified

### 1. `required_fields[].question` drove chat copy

| Location | Problem |
|----------|---------|
| `Required_Fields_Provider::resolve()` | Attached JSON `question` text to every missing field |
| `Conversation_Engine::compact_missing()` | Sent `missing_fields[].question` to the model |
| `AI_Intake_Interpreter::build_gathering_fallback()` | Returned the first JSON question verbatim when the model failed |

**Why this caused repetitive conversations:** The model was explicitly given scripted question strings and trained to paraphrase them — producing form-wizard phrasing ("Which county?", "Question 2") instead of legal-assistant prose.

**Fix:** `conversation_missing_information()` returns **semantic topics** (`topic: "whether you have children under 21"`), not question text. Fallbacks use `Routing_Discriminator_Catalog::combined_gathering_prompt()` to merge topics into one natural sentence.

### 2. `entry_questions` unused at runtime

`entry_questions` in workflow JSON are validated and documented but **never consumed** by PHP routing or chat code. Classification uses `triggers[]`, `routing_rules[]`, and hardcoded discriminator maps instead.

**Why this matters:** Authors may believe `entry_questions` control chat; they do not. Future work may wire them into `Trigger_Matcher` hints — not into chat prompts.

### 3. Hardcoded routing question maps

| Location | Duplicate map |
|----------|----------------|
| `Question_Selector::RESOLUTION_QUESTIONS` | "Do you have any children under 21?" |
| `Required_Fields_Provider::resolution_question()` | Same strings |

**Fix:** Centralized in `Routing_Discriminator_Catalog` as **topics**, not questions.

### 4. Deterministic `Intake_Agent` still question-tree shaped

`Intake_Agent` + `Question_Selector` return one `next_question` per turn with `pending_field` for yes/no chips. This path is used when `prose_intake_use_ai_interpreter` is false (admin tester, legacy endpoint).

**Mitigation:** Once workflow resolves, deterministic path stops asking document fields. AI path is default.

### 5. Fragmented memory

Facts lived in `Intake_State`, routing in `Case_Profile`, notes in `conversation_summary`, and procedure in `Case_Summary_Presenter` — no single object the model could read holistically.

**Fix:** `Case_Memory` unifies workflow, facts, `missing_information`, stages, and completion for every turn.

### 6. No Workflow Engine facade

Routing, completion, stages, and forms were spread across `Routing_Engine`, `Required_Fields_Provider`, `Stage_Form_Presenter`, and package services with no single non-conversational API.

**Fix:** `Workflow_Engine` exposes `identify_workflow()`, `evaluate_conditions()`, `determine_stage()`, `get_required_forms()`, `get_missing_facts()`, `generate_package()` — **never conversational text**.

### 7. Forms before workflow determined

`chat.js` can show actions from partial `case_profile`. `Stage_Form_Presenter` gates `forms_visible` until intake complete — enforced in `Conversation_Engine` role guidance and interpreter reconcilers.

### 8. Duplicate questions

Caused by: (a) model not seeing pre-filled facts, (b) JSON question fallback after extraction, (c) `pending_field` re-asking.

**Fixes already present + extended:** `apply_message_prefill()`, `reconcile_reply_after_intake()`, `sync_child_facts()`. Case Memory now lists only conversation-safe gaps.

### 9. JSON explanations replacing reasoning

`Filing_Guidance_Brief_Resolver`, `Procedural_Roadmap_Presenter`, and stage reconcilers inject deterministic prose — appropriate for **guidance mode**, but must not run during **routing gathering**.

**Boundary:** Briefs apply when `forms_visible` and workflow resolved; gathering uses `missing_information` topics only.

---

## New components

### Case_Memory (`ProSe\Core\Ai_Intake\Case_Memory`)

```json
{
  "workflow": "uncontested_divorce_children_nyc",
  "confidence": 0.95,
  "facts": { "spouse_agrees": true },
  "missing_information": [
    { "key": "children", "topic": "whether you have any children under 21", "type": "boolean" }
  ],
  "completed_forms": [],
  "current_stage": { "id": "commencement", "title": "Commencement" },
  "next_stage": null,
  "completion": 42,
  "routing_status": "gathering"
}
```

Updated every interpreter turn. Returned in API `case_memory` and stored on `case_profile`.

### Workflow_Engine (`ProSe\Core\Routing\Workflow_Engine`)

Deterministic only. Consumed by the interpreter; output folded into Case Memory and `case_summary`.

### Routing_Discriminator_Catalog

Engine-owned semantic topics for routing discriminators (`children`, `spouse_agrees`, etc.).

---

## Conversation rules (enforced)

1. **Never** send JSON `question` strings to ChatGPT.
2. **Combine** multiple `missing_information` topics in one message when appropriate.
3. **Extract** all facts from free text; never re-ask known facts.
4. **Chat** collects routing discriminators only; document fields are form-phase.
5. **Workflow Engine** decides court, workflow, forms, completion — not the model.

### Example

User: *"I want to divorce my husband."*

**Bad (old):** Three separate JSON questions.

**Good (new):** *"I can help with that. To determine the correct New York divorce process, could you tell me whether your spouse agrees, whether you have children under 21, and whether a case has already been started?"*

User: *"We have two children."*

→ `has_minor_children=true`, `child_count=2` — never ask about children again.

---

## Files changed in this refactor

| File | Change |
|------|--------|
| `modules/ai-intake/class-case-memory.php` | New unified memory object |
| `modules/routing/class-workflow-engine.php` | New deterministic engine facade |
| `modules/routing/class-routing-discriminator-catalog.php` | Semantic routing topics |
| `modules/ai-intake/class-required-fields-provider.php` | Topics not questions for chat |
| `modules/ai-intake/class-conversation-engine.php` | `case_memory` payload, updated guidance |
| `modules/ai-intake/class-ai-intake-interpreter.php` | Workflow Engine + Case Memory integration |

---

## What was intentionally not changed

- Workflow JSON schema (`required_fields`, `entry_questions`, etc.)
- All workflow JSON files under `docs/workflows/`
- Deterministic `Intake_Agent` (legacy/offline path)
- Rules engine ownership of legal determinations (ADR-003)

---

## Follow-up recommendations

1. Wire `entry_questions` into workflow resolver scoring (data only, not chat).
2. Migrate deterministic `Intake_Agent` to combined-topic fallbacks for parity.
3. Persist `case_memory` in `Conversation_Persistence` for logged-in users.
4. Add integration tests for multi-fact single-reply gathering scenarios with live OpenAI (optional).
