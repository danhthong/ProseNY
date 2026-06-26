# Rules Reference

Rules are reusable configuration objects evaluated by the Rules Engine and related domain services. AI does not create or modify rules.

For routing implementation, see `modules/routing/`. For workflow rule fields, see `prose-core/docs/workflows/README.md`.

---

## Rule object schema (conceptual)

Each rule type shares a common documentation shape:

| Property | Description |
|----------|-------------|
| `id` / `key` | Stable identifier |
| `type` | Rule category (see below) |
| `inputs` | Facts, events, or context required |
| `outputs` | Court, workflow, fields, deadlines, messages |
| `required_facts` | Minimum fact keys before evaluation |
| `prerequisites` | Prior stages, events, or rule outcomes |
| `exceptions` | Conditions that skip or override |
| `priority` | Evaluation order when multiple rules match |
| `workflow_effects` | Stage, forms, branch, or lifecycle changes |
| `metadata` | version, source, effective_date, tags — see [metadata.md](./metadata.md) |

---

## Rule types

### 1. Routing rules

**Location:** Workflow JSON `routing_rules[]`

**Purpose:** Override workflow selection when a condition is met (e.g. active divorce).

```json
{
  "condition": "active_divorce=true",
  "workflow": "uncontested_divorce_children_nyc"
}
```

| Property | Value |
|----------|-------|
| Inputs | Current facts, candidate workflow |
| Outputs | Target workflow key |
| Priority | Parent workflow `routing_priority` |
| Workflow effects | Changes resolved workflow; re-runs forms and timeline |

**Engine:** `Workflow_Resolver`, `Routing_Engine` (Plan 03)

---

### 2. Required field rules

**Location:** Workflow JSON `required_fields[]`

**Purpose:** Define intake data requirements and generated questions.

```json
{
  "key": "child_count",
  "type": "integer",
  "required": true,
  "question": "How many children are involved?"
}
```

| Property | Value |
|----------|-------|
| Inputs | Fact store |
| Outputs | `missing_fields[]`, completion % |
| Prerequisites | Workflow resolved |
| Workflow effects | Blocks intake completion until satisfied |

**Engine:** `Required_Fields_Provider`, `Completion_Calculator`

---

### 3. Priority rules

**Location:** Workflow JSON `routing_priority`, `intake_priority`

**Purpose:** Order evaluation when multiple workflows match.

| Field | Engine |
|-------|--------|
| `routing_priority` | Court Routing Engine |
| `intake_priority` | Intake Agent (safety-sensitive workflows highest) |

**Workflow effects:** Determines which workflow wins on ambiguous intent.

---

### 4. County rules

**Location:** `docs/county-rules/` (JSON seeds)

**Purpose:** County-specific filing instructions — instructional, not routing (MVP).

| Property | Value |
|----------|-------|
| Inputs | `county`, `court`, `workflow`, `stage` |
| Outputs | Instruction text, `source_url`, `effective_date` |
| Exceptions | Documented per workflow `counties_supported` |
| Workflow effects | None on routing; injected into guidance and package notes |

**Engine:** `County_Guidance_Resolver` (Plan 13)

---

### 5. Deadline rules

**Location:** `class-deadline-catalog.php` seeds

**Purpose:** Compute deadlines from events and static procedural rules.

| Property | Value |
|----------|-------|
| Inputs | Workflow, stage, lifecycle events (e.g. service_date) |
| Outputs | Deadline entries on timeline |
| Prerequisites | Trigger event or stage |
| Workflow effects | Timeline projection only |

**Engine:** `Deadline_Catalog`, `Timeline_Service` (Plan 09)

---

### 6. Lifecycle rules

**Location:** Plan 21 lifecycle catalog (code + configuration)

**Purpose:** Post-filing stage transitions from user-confirmed milestones.

| Property | Value |
|----------|-------|
| Inputs | `lifecycle_events[]`, workflow key, facts |
| Outputs | `lifecycle_stage`, `branch` (default/contested) |
| Prerequisites | Prior lifecycle stage |
| Workflow effects | Dashboard checklist, roadmap mode, branch hints |

**Engine:** Case lifecycle service (Plan 21 Phase A)

**Note:** Branch suggestions are informational — not legal advice.

---

### 7. Progression rules

**Location:** Workflow JSON `internal.progression[]`, `internal.edges[]`

**Purpose:** In-workflow stage transitions and branches.

| Property | Value |
|----------|-------|
| Inputs | Current node, facts, package events |
| Outputs | Next node, available branches |
| Prerequisites | Defined per progression entry |
| Workflow effects | Stage advance, form set for stage |

**Engine:** `Workflow_Progression_Service` (Plan 04)

---

### 8. Classification rules

**Location:** Plan 11 document intelligence configuration

**Purpose:** Map uploaded documents to types — engine-owned, not AI-routed.

| Property | Value |
|----------|-------|
| Inputs | Document metadata, extracted text patterns |
| Outputs | Document type, optional timeline event stub |
| AI role | Summary only; classification is deterministic |

**Engine:** Document intelligence module (Plan 11)

---

### 9. Overlap rules

**Location:** `Routing_Engine` + `Court_Overlap_Resolver`

**Purpose:** Detect multi-court scenarios and produce explainer metadata.

| Property | Value |
|----------|-------|
| Inputs | Resolved courts per issue |
| Outputs | `overlap: bool`, `courts[]`, `overlap_reason` |
| Workflow effects | UI display; does not create second case automatically |

**Engine:** Plan 03

---

## Evaluation order (summary)

```
1. Normalize facts (county, booleans)
2. Intent / issue classification
3. Apply routing_priority + routing_rules
4. Resolve workflow + required_fields
5. On lifecycle PATCH: evaluate lifecycle rules
6. On stage advance: evaluate progression + edges
7. Project timeline from stages + deadline rules + events
```

## Adding new rules

1. Identify rule type above
2. Add configuration to correct repository (JSON, seed, catalog)
3. RFC if evaluation semantics change
4. PHPUnit coverage for inputs → outputs
5. Do not add procedural logic to AI prompts

## Related

- [ADR-004: Rules Engine](../adr/ADR-004-rules-engine.md)
- [Platform Architecture](../architecture/platform-architecture.md)
- [Plan 03: Court routing](../plans/03-court-routing-and-overlap-ux.md)
- [Plan 21: Lifecycle](../plans/21-divorce-ecosystem-lifecycle.md)
