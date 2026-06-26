# CourtFlow AI — Reference Specifications

Authoritative specifications for procedural data. Implementation code reads from these sources; it does not duplicate them in PHP/JS.

## Index

| Domain | Location | Description |
|--------|----------|-------------|
| **Workflows** | [`prose-core/docs/workflows/`](../../public/wp-content/plugins/prose-core/docs/workflows/README.md) | 12 NYC workflows, schema, validation |
| **Forms** | [`prose-core/docs/forms/`](../../public/wp-content/plugins/prose-core/docs/forms/README.md) | OCA form catalog, mappings |
| **Rules** | [rules.md](./rules.md) | Routing, fields, county, deadline, lifecycle rules |
| **Knowledge** | [`docs/knowledge-center/`](../knowledge-center/) | Procedural articles and FAQs |
| **County rules** | [`docs/county-rules/`](../county-rules/) | Borough-specific instructions |
| **Metadata** | [metadata.md](./metadata.md) | Cross-object metadata standards |
| **AI policy** | [`prose-core/docs/ai/system-prompt.md`](../../public/wp-content/plugins/prose-core/docs/ai/system-prompt.md) | Versioned assistant boundaries |
| **Workflow outcomes** | [`prose-core/docs/workflows/outcomes.md`](../../public/wp-content/plugins/prose-core/docs/workflows/outcomes.md) | Terminal procedural outcomes |

## Validation

| Asset | Command / tool |
|-------|----------------|
| Workflows | `php prose-core/bin/validate-workflows.php` |
| Forms | Forms module seeders + catalog tests |
| PHPUnit | `prose-core/tests/` |

## Architecture context

These specifications are consumed by the Domain Layer engines described in [Platform Architecture](../architecture/platform-architecture.md). They are **not** modified by AI at runtime.

## Adding new reference material

1. RFC if architectural impact — see [RFC process](../rfc/README.md)
2. Update the appropriate repository (workflow JSON, forms JSON, knowledge article)
3. Run validation scripts
4. Link from the relevant implementation plan
