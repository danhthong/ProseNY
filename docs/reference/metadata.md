# Metadata Standards

Common metadata fields across CourtFlow platform objects. Apply incrementally to JSON, seeds, and content — **no database migration required** by this document alone.

## Purpose

- Versioning and auditability
- Cross-linking workflows, forms, knowledge, and rules
- Search indexing (Plan 15)
- Future Legal Knowledge Graph edges

## Standard fields

| Field | Type | Description |
|-------|------|-------------|
| `version` | string | Semantic or date version of the object |
| `source` | string | Origin: `nycourts.gov`, `oca`, `manual`, `seed`, `admin` |
| `source_url` | string | Official reference URL when applicable |
| `tags` | string[] | Discovery tags (e.g. `divorce`, `custody`, `safety`) |
| `workflow` | string | Workflow key when object is workflow-scoped |
| `stage` | string | Stage slug when object is stage-scoped |
| `county` | string | County slug (`kings`, `new_york`, …) or `all` |
| `court` | string | `supreme`, `family`, or specific part |
| `dependencies` | string[] | Keys of required facts, forms, or prior stages |
| `effective_date` | date | When this content/rule became effective |
| `expires_date` | date | Optional sunset |
| `related_objects` | object[] | `{ type, key }` links to forms, articles, workflows |

## Application by object type

### Workflow (`prose-core/docs/workflows/*.json`)

Present today: `workflow_category`, `counties_supported`, `internal.workflow_enum`

Recommended additions (incremental): `version`, `source`, `tags`, `effective_date` in workflow root or `metadata` object when schema extended via RFC.

### Rules

County rules JSON: `{ county, court, topic, instruction, source_url, effective_date }` — see Plan 13.

Routing rules inherit parent workflow metadata.

### Forms (`prose-core/docs/forms/`)

Catalog entries should include: official `code`, `internal_code`, court, tags, `source_url`.

### Knowledge (`docs/knowledge-center/*.md`)

Front matter recommended:

```yaml
---
title: Uncontested Divorce in NYC
workflow: uncontested_divorce_no_children_nyc
tags: [divorce, supreme, nyc]
county: all
source: nycourts.gov
effective_date: 2026-01-01
---
```

### Timeline entries (runtime)

Generated objects include: `stage`, `workflow`, `status`, `source` (`workflow` | `event` | `deadline_catalog`).

### Documents (Plan 16)

`prose_case_documents`: type, upload source, classification confidence, related stage.

### Facts (Case Memory — future)

Per-fact: `value`, `confidence`, `source`, `verification_status`, `last_updated`, `collection_method`, `workflow_dependency`.

## Naming conventions

| Concept | Convention | Example |
|---------|------------|---------|
| Workflow key | `snake_case` + `_nyc` suffix | `custody_nyc` |
| Stage slug | `snake_case` | `commencement` |
| County | Official NY county name slug | `kings` (not `brooklyn`) |
| Form code | Official OCA code | `UD-1` |
| Event type | `snake_case` past tense | `service_completed` |

## Related

- [Platform Architecture](../architecture/platform-architecture.md) §11
- [Rules Reference](./rules.md)
- [Workflow schema](../../public/wp-content/plugins/prose-core/docs/workflows/schema/workflow.schema.json)
