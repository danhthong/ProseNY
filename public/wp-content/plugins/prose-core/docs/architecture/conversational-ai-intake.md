# Conversational AI Intake Engine

Part of the Application Layer. Domain decisions are made before and after each AI call — see [ADR-003 AI Boundary](../../../../docs/adr/ADR-003-ai-boundary.md) and [Platform Architecture](../../../../docs/architecture/platform-architecture.md).

## Goal

The chat must feel like talking to one knowledgeable legal intake specialist —
not a form wizard. The AI is aware of the full conversation, all known facts,
all missing facts, workflow progress, and legal context, and decides how to
continue the conversation naturally (including collecting several missing facts
in a single message).

## Hard boundary (unchanged)

The ProSe legal engine stays deterministic. Only ProSe decides:

- court
- workflow
- package
- forms
- completion status

OpenAI receives legal facts and produces conversation. OpenAI never makes a
legal determination.

## Single-call conversation turn

Each user turn performs **one** OpenAI call that does extraction and conversation
together. The model receives the full context and returns both `fact_updates`
and a `conversation_reply`.

### Context sent to OpenAI (every request)

```json
{
  "conversation_summary": "",
  "recent_messages": [],
  "case_memory": {
    "workflow": null,
    "facts": {},
    "missing_information": [],
    "current_stage": null,
    "routing_status": "gathering"
  },
  "workflow": {},
  "package": {},
  "contradictions": []
}
```

See [Conversation-First AI Intake](./conversation-first-intake.md) for the full architectural review.

### Model output (every response)

```json
{
  "fact_updates": { "field": { "value": "...", "confidence": 0.0 } },
  "conversation_reply": "..."
}
```

## Turn pipeline (interpreter)

1. Hydrate `Intake_State` from request (facts, summary, conversation id).
2. Direct-forms short-circuit (existing feature) for "just give me the forms".
3. **Pre-resolve (ProSe, deterministic):** route the matter, compute the
   currently missing required fields, completion %, contradictions. This is the
   grounding context handed to the model — never the model's own idea of what is
   required.
4. **Conversation_Engine.converse() — one OpenAI call:** extracts all facts in
   the message and writes a natural reply that asks for whatever is still missing
   or, if nothing is missing, confirms and explains next steps.
5. Merge fact updates into state (confidence rules; never overwrite confirmed;
   deterministic regex supplement for dates/booleans/county hardening).
6. **Post-resolve (ProSe, deterministic):** recompute workflow, missing fields,
   completion %, contradictions with the new facts. This is authoritative.
7. Decide mode deterministically:
   - missing remains → `ask_question` (gathering)
   - missing empty + workflow resolved → `complete_intake` (first time) then
     `guidance` (follow-ups). Completion is always 100 here.
8. Return the model reply as the chat message plus the full state/case_profile so
   the package preview and progress bar update.

## No question tree

There is no Question 1 → Question 2 → Question 3. `pending_field` is retained
only as a soft hint for interpreting short follow-up answers; it is not a tree
pointer. The model may gather several fields at once and must never re-ask for a
fact already present in `intake_state`.

## Guidance mode

Once ProSe reports completion with a resolved workflow, the model transitions
into plain-English guidance using the workflow title/description, the required
form codes/titles, stages, and supporting documents supplied in context. It
explains the package, the forms, and the next filing steps.

## Components

- `Conversation_Engine` — builds context with `case_memory`, makes the single OpenAI call,
  normalizes output via `Fact_Extractor`.
- `Case_Memory` — unified structured snapshot (facts, missing topics, stage, workflow).
- `Workflow_Engine` — deterministic workflow authority (no conversational text).
- `Routing_Discriminator_Catalog` — semantic topics for routing facts.
- `Fact_Extractor::process_raw()` (new public) — shared normalization +
  deterministic supplement, reused by the engine and the legacy extractor.
- `Required_Fields_Provider` — unchanged authority for required/missing fields.
- `Completion_Calculator` / `Consistency_Checker` — unchanged deterministic
  completion + contradiction detection.
- `Conversation_Memory` — rolling summary for long conversations.
- `Stub_Ai_Provider` — gains a `converse` mode so offline installs still work.

## Resilience

The engine always returns a non-empty reply: if the provider fails or returns no
text, deterministic fallbacks produce a natural gathering question or guidance
summary, so the chat never shows a raw "Something went wrong" during normal use.
