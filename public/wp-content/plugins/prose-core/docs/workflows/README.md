# CourtFlow AI Workflow Repository

The Workflow Repository is the procedural source of truth for CourtFlow AI. It defines what workflows exist, which court handles them, what stages they contain, which forms are required, and what information must be collected from users.

## Procedural chain

```
Issue Type → Court → Workflow → Stage → Forms → Deadlines → Next Step
```

## Consumers

This repository is the foundation for:

- **Workflow Engine** — workflow resolution and progression
- **Court Routing Engine** — court assignment and overlap rules
- **Intake Chat Agent** — trigger matching and required-field collection
- **Forms Repository** — workflow-to-form relationships (forms are not stored here)
- **Package Builder** — stage-based document packages
- **Timeline Engine** — current stage, next step, deadline tracking
- **Procedural Navigator** — guided procedural navigation

## Structure

```
docs/workflows/
  README.md
  schema/workflow.schema.json
  divorce/                    # 4 Supreme Court entry workflows
  family_court/               # 8 Family Court entry workflows
  inventory.json
  inventory.md
  outcomes.md
```

This repository is the **single procedural source of truth**. Future systems (Workflow Resolver, Court Routing Engine, Intake Agent, Package Builder, Timeline Engine, Procedural Navigator) read all workflow metadata from these definitions and must not require additional workflow configuration files.

## Workflow inventory (12 total)

### Supreme Court (4)

- `uncontested_divorce_no_children_nyc`
- `uncontested_divorce_children_nyc`
- `contested_divorce_nyc`
- `default_divorce_nyc`

### Family Court (8)

- `custody_nyc`
- `visitation_nyc`
- `child_support_nyc`
- `family_offense_nyc`
- `order_of_protection_nyc`
- `paternity_nyc`
- `guardianship_nyc`
- `adoption_nyc`

## Design principles

1. **Workflow is the primary entity.** Forms attach to workflow stages; they are not the organizing principle.
2. **Only true entry points are workflows.** Procedural phases such as discovery, settlement, trial, judgment, maintenance, property division, modification, and enforcement are modeled as **stages** inside workflows—not as separate workflow files.
3. **Court routing is explicit.** Divorce matters route to Supreme Court. Standalone custody, visitation, and child support route to Family Court. When custody or support arises within an active divorce, it stays in the Supreme Court divorce workflow (`includes` + `routing_rules`).

## Court routing rules

| Scenario | Court | Workflow |
|----------|-------|----------|
| Divorce only | Supreme Court | Divorce workflow |
| Custody/support only | Family Court | Family Court workflow |
| Divorce + children | Supreme Court | `uncontested_divorce_children_nyc` or `contested_divorce_nyc` |
| Active divorce + custody intent | Supreme Court | Routed via `routing_rules` on Family Court workflows |

Family Court workflows that overlap with divorce (`custody_nyc`, `visitation_nyc`, `child_support_nyc`) include `routing_rules` such as:

```json
{
  "condition": "active_divorce=true",
  "workflow": "uncontested_divorce_children_nyc"
}
```

## Form code convention

- **`code`** — official NYC OCA form code (source of truth), validated against nycourts.gov.
- **`internal_code`** — compatibility mapping to the existing engine `Vocabulary` in `modules/forms/classification/class-vocabulary.php`.

Examples:

```json
{ "code": "UD-1", "internal_code": "UD-1" }
{ "code": "GF-17", "internal_code": "FC-1" }
{ "code": "4-3", "internal_code": "FC-2" }
{ "code": "8-2", "internal_code": "FC-7" }
```

### Known engine divergence

The existing engine `Vocabulary` package catalog references `UD-7` in the judgment package context. Officially, `UD-7` is the **Affirmation of Defendant** and `UD-11` is the **Judgment of Divorce**. This repository uses official codes; reconcile the engine in a future phase.

## Schema

Each workflow file conforms to `schema/workflow.schema.json`. Key fields:

| Field | Purpose |
|-------|---------|
| `workflow_category` | High-level grouping (`divorce` / `family_court`) for dashboard filtering, reporting, browsing, analytics |
| `triggers` | User-intent phrases for Intake Agent matching |
| `entry_questions` | Workflow-level classification questions for the Workflow Resolver and intent classification |
| `routing_rules` | Condition → workflow overrides for overlap resolution |
| `routing_priority` | Court Routing Engine evaluation order (higher wins) |
| `intake_priority` | Intake Agent matching priority; safety-sensitive workflows rank highest |
| `required_fields` | Structured data requirements `{ key, type, required, question }` — the Intake Agent generates questions directly from these |
| `stages` | Ordered procedural stages |
| `internal.node_sequence` | PRD node IDs (`NODE_1001`–`NODE_1010`, etc.) aligned 1:1 with `stages[]` |
| `internal.progression` | Entry conditions for each node (`event` or `package` triggers) |
| `internal.edges` | Optional branching transitions (e.g. settlement vs trial) |
| `workflow_outcomes` | Expected procedural outcomes for the Procedural Navigator / Timeline Engine |
| `required_forms` | Stage → official form mappings |
| `supporting_documents` | Non-form documents commonly required |

### Intake priority

`intake_priority` lets the Intake Agent prioritize safety-sensitive workflows. Highest first:

| Workflow | routing_priority | intake_priority |
|----------|------------------|-----------------|
| order_of_protection_nyc | 85 | 100 |
| family_offense_nyc | 80 | 95 |
| divorce workflows | 85–100 | 90 |
| custody_nyc / child_support_nyc | 50 | 50 |
| visitation_nyc | 45 | 45 |
| paternity_nyc | 40 | 40 |
| guardianship_nyc | 35 | 35 |
| adoption_nyc | 30 | 30 |

### Required fields generate intake questions

Every `required_fields` entry carries a `question`, so the Intake Agent builds its prompts directly from workflow definitions. No separate question-mapping files exist.

```json
{ "key": "child_count", "type": "integer", "required": true, "question": "How many children are involved?" }
```

## Validation sources

- [NYC CourtHelp — Filing for an Uncontested Divorce](https://www.nycourts.gov/courthelp/family/divorceStarting.shtml)
- [Uniform Uncontested Divorce Packet (UD forms)](https://www.nycourts.gov/LegacyPDFS/divorce/COMPOSITE-UNCONTESTED-DIVORCE-FORMS.pdf)
- [NYS Family Court Forms Index](https://www.nycourts.gov/family-forms)
- [Support Modification DIY Program](https://www.nycourts.gov/courthelp/diy/supportModification.shtml)
- [Orders of Protection FAQ](https://www.nycourts.gov/faq/orderofprotection.shtml)
- [Adoption — CourtHelp](https://www.nycourts.gov/courthelp/Family/adoption.shtml)

## Counties supported

All workflows support the five NYC counties:

- New York (Manhattan)
- Kings (Brooklyn)
- Queens
- Bronx
- Richmond (Staten Island)

## Stage model

Each workflow defines an ordered procedural graph in JSON:

1. **`stages[]`** — human-facing stage slugs (`commencement`, `discovery`, `judgment`, …)
2. **`internal.node_sequence[]`** — engine node IDs; the first N entries align 1:1 with the first N `stages[]` entry-path stages
3. **`internal.progression[]`** — linear advance rules into each node after the entry node
4. **`internal.edges[]`** — optional branch rules (e.g. compliance conference → settlement **or** trial)

The **`Workflow_Progression_Service`** (`modules/forms/engine/class-workflow-progression-service.php`) is the runtime reader for this graph. It powers:

- `Case_Catalog` node advancement (case engine)
- `Guidance_Resolver` / Procedural Navigator stage lists
- Package Builder stage form lookup via `get_stage_forms()`

Court and workflow **selection** remains in `modules/routing/`. Case **progression** reads only from workflow JSON.

## State machine (documented model)

Workflow stages implement a deterministic state machine. The JSON graph is authoritative; engines must not hardcode transitions.

| Concept | Source | Runtime |
|---------|--------|---------|
| States | `stages[]` + Plan 21 lifecycle milestones | `Workflow_Progression_Service`, lifecycle catalog |
| Transitions | `internal.progression[]`, `internal.edges[]` | Progression service + event triggers |
| Completion | Required facts + progression triggers | `Completion_Calculator`, package events |
| Validation | `validate-workflows.php` | CI + activate hooks |
| Prerequisites | Per progression entry | Fact + event checks |
| Rollback | Not user-facing (MVP) | Future: compensating events |

Full specification: [Workflow State Machine ADR](../../../docs/adr/ADR-002-workflow-state-machine.md) · [Platform Architecture](../../../docs/architecture/platform-architecture.md) §4

## Event model (future direction)

Lifecycle milestones (filed, served, answer received) are recorded as **procedural events** on the case. The Timeline Engine projects stages and deadlines from events + this workflow graph — conversation history is not the source of truth.

See [Event Model ADR](../../../docs/adr/ADR-006-event-model.md) and Plan 21 lifecycle phases.

## Architecture references

| Topic | Document |
|-------|----------|
| Platform architecture | [docs/architecture/platform-architecture.md](../../../docs/architecture/platform-architecture.md) |
| Rules specification | [docs/reference/rules.md](../../../docs/reference/rules.md) |
| Guiding principles | [docs/architecture/guiding-principles.md](../../../docs/architecture/guiding-principles.md) |

Run the validation script:

```bash
php app/public/wp-content/plugins/prose-core/bin/validate-workflows.php
```

This checks JSON syntax, required schema fields, stage/form consistency, and regenerates `inventory.json`. It fails if any required metadata is missing. Specifically it verifies:

- `workflow_category`, `workflow_outcomes`, `entry_questions`, and `intake_priority` exist
- every required field defines a `question`
- `internal.workflow_enum` is present and unique across all workflows
- `internal.node_sequence` length must be ≤ `stages[]` length (first N stages align with nodes)
- `internal.progression` is present and starts at the first node
- node IDs match `NODE_<digits>_<NAME>` format
- `routing_rules` reference valid workflow names
- no procedural phase (discovery, settlement, trial, judgment, motion_practice, maintenance, property_division) is modeled as a standalone workflow

## Phase status

**Phase 1 — Workflow Repository: complete.** 12 workflows (4 divorce, 8 Family Court), full schema, validated metadata, inventory, and outcomes.

**Phase 2 — Workflow progression engine: complete.** JSON-driven `Workflow_Progression_Service`, case engine delegation, procedural navigator stage parity. Next: Phase 3 — Timeline deadlines (Plan 09).
