# CourtFlow AI — Platform Architecture

This document describes the long-term platform architecture while preserving every existing principle, module, and implementation plan. It **extends** the architecture; it does not redesign it.

For immutable decision rules, see [Guiding Principles](./guiding-principles.md). For implementation tasks, see [Plans](../plans/README.md).

---

## 1. Platform identity

CourtFlow AI is:

- A **procedural navigation platform**
- **Workflow driven** — workflow JSON is the procedural source of truth
- **Data driven** — facts, rules, and configuration over hardcoded logic
- **Forms driven** — Forms Library is authoritative for document requirements
- **Rule based** — Rules Engine determines court, workflow, forms, and next steps
- **AI assisted** — AI explains, collects, summarizes, and assists

CourtFlow AI is **not**:

- A legal advice platform
- An autonomous legal AI
- An AI-first system where prompts determine procedure

---

## 2. Layered architecture

### Application Layer

User-facing surfaces and API adapters:

| Component | Location | Role |
|-----------|----------|------|
| Workspace UI | `themes/prose-app/` | Chat, context panel, timeline, roadmap |
| Dashboard | `themes/prose-app/` | Case summary, lifecycle widget |
| Homepage intake | `[prose_intake_chat]` | Entry-point chat |
| REST API | `modules/intake/`, `modules/ai-intake/` | Session, case, timeline endpoints |
| Admin hub | `modules/` admin pages | Workflows, forms, county rules, AI logs |

The Application Layer **consumes** domain outputs. It does not determine procedure.

### Domain Layer (conceptual)

A documented layer between Application and Infrastructure that centralizes procedural business logic, reduces coupling, and provides a single conceptual source of truth.

**This is an architectural concept, not an immediate implementation task.** Existing modules already fulfill these responsibilities:

| Domain component | Current implementation | Responsibility |
|------------------|------------------------|----------------|
| **Case Engine** | `modules/forms/engine/` (`Case_Service`, `Case_Catalog`, `Case_Progress_Service`) | Case lifecycle, facts, progression |
| **Workflow Engine** | `modules/forms/engine/class-workflow-progression-service.php`, workflow JSON | Stage graph, progression, form lookup per stage |
| **Rules Engine** | `modules/routing/` (`Routing_Engine`, `Workflow_Resolver`, `Court_Overlap_Resolver`) | Court, workflow, overlap, routing_rules |
| **Timeline Engine** | `modules/forms/engine/class-case-timeline.php`, `class-deadline-catalog.php` | Stages, deadlines, events projection |
| **Knowledge Engine** | `modules/guidance/`, `modules/search/`, `docs/knowledge-center/` | Procedural articles, county notes, search |
| **Event Engine** | `class-case-event-service.php`, Plan 21 lifecycle events | Record procedural milestones; derive state |

Future work may consolidate these under a `Domain` namespace without changing external behavior. See [ADR-001](../adr/ADR-001-domain-layer.md).

### Infrastructure Layer

| Component | Role |
|-----------|------|
| WordPress | Host, users, options, media |
| Database | Cases, messages, documents, deadlines (Plan 16) |
| PDF / Assembly | Form fill, package merge |
| OpenAI | Conversation and extraction only — bounded by pre/post-resolve |

---

## 3. Case Aggregate

The **Case** is the primary domain object — a conceptual aggregate that logically contains everything about a user's procedural matter.

This is a **domain model**, not a mandate to change the database schema. Today's `case_profile` and Plan 16 `prose_cases` already align with this model.

```
Case
├── identity          (id, user/session, created, status)
├── court             (primary court, overlap courts[])
├── county            (normalized borough → county)
├── workflow          (resolved workflow key, category, branch)
├── facts             (collected intake + lifecycle facts — see Case Memory)
├── forms             (required, generated, uploaded)
├── packages          (stage-based filing packages)
├── timeline          (stages, tasks, deadlines — projection)
├── documents         (uploads, classified types)
├── events            (procedural milestones — see Event Model)
├── progress          (intake %, lifecycle stage, node position)
├── ai_context        (summary, contradictions — not primary memory)
└── audit_history     (security, verification, admin actions)
```

**Invariants:**

- Court and workflow are set by the Rules Engine, not AI.
- Progress advances via workflow graph + lifecycle events, not chat turns alone.
- Packages are deterministic given workflow + stage + county + facts.

See [ADR-007](../adr/ADR-007-case-aggregate.md).

---

## 4. Workflow State Machine

Workflow stages in JSON are correct and remain the source of truth. This section documents the **state model** that engines implement — without requiring workflow JSON changes.

### States

Each workflow defines an ordered procedural graph:

1. **`stages[]`** — human-facing stage slugs (`commencement`, `discovery`, `judgment`, …)
2. **`internal.node_sequence[]`** — engine node IDs aligned with entry-path stages
3. **`internal.progression[]`** — linear advance rules into each node
4. **`internal.edges[]`** — optional branch rules (e.g. settlement vs trial)

**Lifecycle states** (Plan 21) extend beyond intake stages for post-filing tracking: `eligibility` → `intake` → `forms_ready` → `filed` → `served` → `awaiting_answer` → branch tracks → `closed`.

Intake-stage progression and lifecycle-stage progression are **orthogonal but linked**: lifecycle events may trigger workflow branch evaluation without AI involvement.

### Allowed transitions

| Transition type | Determined by | Example |
|-----------------|---------------|---------|
| Stage advance (intra-workflow) | `Workflow_Progression_Service` + facts + events | commencement → service |
| Branch selection | `internal.edges[]` + lifecycle facts | contested → settlement or trial |
| Workflow re-route | `routing_rules` + updated facts | active divorce → Supreme Court divorce workflow |
| Lifecycle milestone | User-confirmed event + rules | `filed` → `served` |

### Optional transitions

Branches in `internal.edges[]` are optional paths (e.g. compliance conference → settlement **or** trial). The engine exposes available transitions; the user (or future Case Intelligence) sees options — AI does not choose the branch.

### Completion criteria

| Level | Criteria |
|-------|----------|
| Intake complete | All `required_fields` satisfied; workflow resolved |
| Stage complete | Progression rule satisfied (event, package generated, user confirmation) |
| Workflow complete | Terminal node reached; `workflow_outcomes` achieved |

### Validation

- `validate-workflows.php` enforces schema, node/stage alignment, and routing_rules references.
- `Consistency_Checker` detects contradictory facts before routing.
- Package Builder validates form availability for stage.

### Prerequisites

Stage entry may require:

- Prior stage completion
- Required facts (`required_fields`)
- Generated package for prior stage
- Lifecycle event (e.g. `served` before `awaiting_answer`)

Defined in `internal.progression[]` and Plan 21 lifecycle rules.

### Rollback behavior

**MVP / current:** Stage rollback is not user-facing. Correcting facts may re-resolve workflow (routing) or invalidate downstream packages — surfaced as warnings, not silent mutation.

**Future:** Explicit `rollback_to_stage` with audit event; requires RFC. Events remain append-only; rollback adds compensating events rather than deleting history.

See [ADR-002](../adr/ADR-002-workflow-state-machine.md) and `prose-core/docs/workflows/README.md`.

---

## 5. Event Model

Procedural **events** are discrete, recorded milestones. The Timeline is a **projection** of events + workflow + deadline rules — not the primary store.

### Important procedural events

| Event | Typical source | Effects |
|-------|----------------|---------|
| `case_created` | System | Initialize case aggregate |
| `facts_updated` | Intake / user | Re-run routing, missing fields, completion |
| `workflow_selected` | Rules Engine | Set workflow, initial timeline |
| `forms_generated` | Package Builder | Stage package available |
| `package_generated` | Package Builder | Download ready |
| `case_filed` | User confirmation | Lifecycle → `filed` |
| `service_completed` | User + date | Lifecycle → `served`; answer deadline computed |
| `answer_received` | User confirmation | Branch evaluation |
| `conference_scheduled` | User / future doc intelligence | Deadline added |
| `settlement_reached` | User confirmation | Branch → settlement path |
| `judgment_entered` | User confirmation | Lifecycle → `post_judgment` |

### Event structure (conceptual)

```json
{
  "event": "service_completed",
  "timestamp": "2026-06-01T14:00:00Z",
  "source": "user",
  "payload": { "service_date": "2026-05-28" },
  "workflow": "contested_divorce_nyc",
  "stage": "service"
}
```

### Future direction

- **Not event sourcing today** — events are stored on `case_profile.lifecycle_events[]` and drive derived state.
- Full event log + projections is documented for long-term maintainability; implementation requires RFC.
- Document intelligence (Plan 11) may emit events with `source: document` after classification rules approve.

See [ADR-006](../adr/ADR-006-event-model.md).

---

## 6. Case Intelligence Engine (future)

A future domain service — **not AI** — responsible for:

| Capability | Input | Output |
|------------|-------|--------|
| Evaluate rules | Facts + workflow + events | Routing, stage, branch |
| Detect missing facts | `required_fields` vs case memory | Missing field list |
| Detect missing documents | Stage + supporting_documents | Document checklist |
| Compute progress | Stages + events + completion | Progress %, lifecycle position |
| Generate recommendations | Rules output + knowledge | Next steps, warnings (structured) |
| Procedural warnings | Deadline catalog + dates | Approaching deadlines, branch hints |

The **AI Assistant consumes** recommendations (procedural navigator, roadmap, reference knowledge, future recommendation objects). It does not create procedural decisions.

Today, `Routing_Engine`, `Workflow_Progression_Service`, `Timeline_Service`, and `Procedural_Roadmap_Presenter` collectively provide subsets of this surface. Consolidation under a Case Intelligence facade is future scope.

---

## 7. Knowledge Layer

Procedural knowledge is **separate from workflow logic**. Workflows reference knowledge; knowledge does not determine workflow.

### Knowledge includes

- Filing instructions
- FAQs and educational guidance
- County notes and court notes
- Exceptions and procedural explanations
- Examples and references
- Knowledge Center articles (`docs/knowledge-center/`)

### Knowledge does not include

- Court routing decisions
- Workflow selection
- Required form determination
- Stage transition rules

### Current implementation

| Asset | Location |
|-------|----------|
| Guidance seeds | `modules/guidance/` |
| County rules | `docs/county-rules/` |
| Knowledge articles | `docs/knowledge-center/` |
| Search index | `modules/search/` (Plan 15) |
| AI reference injection | `reference_knowledge` in converse payload |

See [ADR-005](../adr/ADR-005-knowledge-layer.md).

---

## 8. Legal Knowledge Graph (future)

A conceptual graph relating procedural entities. It **complements** RAG and search; it does not replace workflow JSON, forms catalog, or county rules repositories.

```
Workflow
  ↓ requires
Rules (routing_rules, required_fields)
  ↓ determines
Forms (required_forms per stage)
  ↓ explained by
Instructions (filing guidance, package notes)
  ↓ subject to
Deadlines (deadline catalog)
  ↓ may have
Exceptions (county rules, court notes)
  ↓ answered by
FAQs / Knowledge articles
  ↓ illustrated by
Videos / Samples
  ↓ related to
Related Forms
```

**Future use:** Graph traversal for contextual help, admin impact analysis ("which workflows use form X?"), and richer search — not for routing decisions.

---

## 9. Rules as reusable objects

Rules are documented as reusable configuration objects. See [reference/rules.md](../reference/rules.md) for the full specification.

Summary:

| Rule type | Location | Determines |
|-----------|----------|------------|
| Routing rules | Workflow JSON `routing_rules` | Workflow override when condition met |
| Required fields | Workflow JSON `required_fields` | Intake questions, completion |
| Priority rules | `routing_priority`, `intake_priority` | Evaluation order |
| County rules | `docs/county-rules/` | County-specific instructions |
| Deadline rules | `class-deadline-catalog.php` | Computed deadlines |
| Lifecycle rules | Plan 21 | Post-filing stage transitions |
| Classification rules | Plan 11 | Document type (engine-owned) |

Each rule describes: inputs, outputs, required facts, prerequisites, exceptions, priority, and workflow effects.

See [ADR-004](../adr/ADR-004-rules-engine.md).

---

## 10. Case Memory

Persistent procedural memory for collected facts. Conversation history is **supplementary** — not the primary store of truth.

### Per-fact metadata (conceptual)

| Field | Purpose |
|-------|---------|
| `value` | Typed fact value |
| `confidence` | 0–1; AI extraction vs user confirmed |
| `source` | `user`, `ai_extraction`, `document`, `admin` |
| `verification_status` | `unverified`, `confirmed`, `contradicted` |
| `last_updated` | Timestamp |
| `collection_method` | `chat`, `form`, `upload`, `api` |
| `workflow_dependency` | Which `required_fields` keys this satisfies |

### Current implementation

- `intake_state` / `case_profile.facts` in session and DB
- `Fact_Extractor` confidence rules; confirmed facts not overwritten by low-confidence AI
- `Consistency_Checker` for contradictions
- `Conversation_Memory` for rolling summary only

Full Case Memory metadata on every fact is a future enhancement; the schema direction is documented for Plan 16+ evolution.

---

## 11. Metadata standards

Common metadata across platform objects improves discoverability, versioning, and cross-linking. No database migration required — apply to JSON and content incrementally.

| Field | Workflow | Rules | Forms | Knowledge | Timeline | Documents | Facts |
|-------|----------|-------|-------|-----------|----------|-----------|-------|
| `version` | ✓ | ✓ | ✓ | ✓ | — | ✓ | — |
| `source` | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| `tags` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `workflow` | — | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `stage` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `county` | ✓ | ✓ | — | ✓ | ✓ | — | ✓ |
| `court` | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| `dependencies` | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| `effective_date` | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| `related_objects` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |

See [reference/metadata.md](../reference/metadata.md).

---

## 12. AI boundaries

### AI may

- Explain procedures, forms, and deadlines
- Summarize documents (when engine classifies)
- Educate using knowledge and navigator content
- Collect information via natural conversation
- Generate natural language replies

### AI may not

- Determine legal procedure (court, workflow, stage)
- Override workflow or rules output
- Select legal strategy or recommend outcomes
- Modify rules or validation
- Bypass validation or invent requirements
- Render procedural roadmap inside chat (frontend owns roadmap card)

Enforced by: pre-resolve / post-resolve pipeline, `ROLE_GUIDANCE`, system prompt, red-team tests.

See [ADR-003](../adr/ADR-003-ai-boundary.md), `prose-core/docs/ai/system-prompt.md`, and `prose-core/docs/architecture/conversational-ai-intake.md`.

---

## 13. Future workflow expansion

The architecture supports additional matter types through **configuration and documentation** — not redesign:

| Workflow family | Status |
|-----------------|--------|
| Divorce (4 NYC) | ✅ Implemented |
| Custody, Visitation, Child Support | ✅ Implemented |
| Order of Protection, Family Offense | ✅ Implemented |
| Paternity, Guardianship, Adoption | ✅ Implemented |
| Statewide counties | Plan 20 |
| Post-judgment modification/enforcement | Plan 21 Phase C+ |

Adding a workflow requires: workflow JSON, forms mapping, routing integration, knowledge articles, deadline seeds, and plan/RFC approval — not new architectural layers.

---

## 14. Module map (current → domain)

| Domain concept | Module path |
|----------------|-------------|
| Rules Engine | `modules/routing/` |
| Workflow Engine | `modules/forms/engine/` (progression) |
| Forms Library | `modules/forms/` + `docs/forms/` |
| Package Builder | `modules/packagebuilder/`, `modules/assembly/` |
| Timeline Engine | `modules/forms/engine/class-case-timeline.php` |
| Procedural Navigator | `modules/procedural/` |
| Guidance / Knowledge | `modules/guidance/` |
| AI Assistant | `modules/ai-intake/` |
| Search | `modules/search/` |
| Security / Audit | `modules/security/` |

---

## 15. Documentation map

| Need | Document |
|------|----------|
| Why a decision was made | [ADRs](../adr/README.md) |
| Propose a change | [RFCs](../rfc/README.md) |
| What to build next | [Plans](../plans/README.md) |
| Workflow JSON spec | `prose-core/docs/workflows/` |
| Forms spec | `prose-core/docs/forms/` |
| Rules spec | [reference/rules.md](../reference/rules.md) |

---

*This document evolves with the platform. Changes that alter domain boundaries, storage, or AI capabilities require an RFC before implementation.*
