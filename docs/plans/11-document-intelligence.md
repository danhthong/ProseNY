# Plan 11 — Document Intelligence

**Status:** Complete  
**Priority:** P2  
**Depends on:** Plan 04, Plan 05  
**Estimated effort:** Large (1–2 weeks)

---

## Goal

Users upload court documents (OSC, Answer, Order, Motion, Judgment) and the system **classifies** them, identifies court/workflow stage, and suggests next procedural step.

## Requirements reference

- PRD Ch. 20 — Document Intelligence
- Workflow Routing Engine V1 — Document Intelligence Engine section
- Entry path: “Received Court Papers”

## Current state

- `Classification_Engine`, `Ai_Summarizer` in forms module
- `Document_Request_Detector` in intake (download intent only)
- No upload UI in intake chat
- Domain guard does not yet support received-papers path (Plan 01)

## Scope

### In scope

- Upload endpoint: PDF/image → document type classification
- Map document types to workflow stages (OSC → temporary relief stage, etc.)
- Update case profile / timeline from classified document
- Chat entry: “I received court papers” → prompt upload
- Display: identified document, court, stage, suggested next step (deterministic)
- AI may **summarize** document in plain language; **classification rules** are engine-owned

### Out of scope

- Full OCR for handwritten documents
- NYSCEF docket import (Plan 19)
- Legal analysis of merits

## Deliverables

1. `POST /prose/v1/documents/classify` (or session-scoped)
2. Upload UI in workspace (drag-drop or button)
3. Classification rules table / config keyed by document type
4. Integration with timeline (Plan 09) — advance stage on known orders
5. Test corpus: sample OSC, Answer, Judgment PDFs (redacted)

## Acceptance criteria

- [x] Upload OSC → type `order_to_show_cause`, stage suggestion populated
- [x] Upload unknown doc → graceful fallback, ask user to describe
- [x] Classification does not override workflow without rule match
- [ ] PII handling documented; files stored securely (Plan 17)

## Implementation tasks

1. Define document type taxonomy aligned with requirements
2. Implement rule-based classifier MVP (keyword + metadata)
3. Optional: LLM assist for summary only, not routing
4. Build upload UI + chat flow
5. Security review before enabling uploads

## Files likely touched

- `modules/forms/classification/`
- New `modules/documents/` or extend intake module
- `themes/prose-app/build/courtflow.js`
- Plan 01 keywords for received papers

## Review questions

1. MVP: **rule-based classification** only, or **AI classification** with human review flag?
2. Store uploaded files on server for MVP, or **client-side only**?
