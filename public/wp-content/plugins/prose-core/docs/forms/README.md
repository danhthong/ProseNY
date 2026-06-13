# CourtFlow AI Forms Repository

The Forms Repository is the document source of truth for CourtFlow AI. Given a workflow, it answers:

- Which forms are required?
- Which files exist?
- Which file version should be used?
- Which source format should be preferred?
- Where are the files stored?

## Procedural chain

```
Workflow → Forms Repository → Canonical Form Assets → Package Builder (future)
```

The Workflow Repository (`docs/workflows/`) remains the procedural source of truth. Forms attach to workflows via `workflow_references` on each canonical form record.

## Consumers

This repository is the foundation for:

- **Package Builder** — stage-based document packages
- **Form Filling Engine** — `fillable_strategy`, `generation_ready`
- **Procedural Navigator** — workflow-to-form relationships
- **Admin QA Tools** — `field_mapping_status`, coverage reports
- **Court Routing Engine** — reads workflow form codes; repository validates they exist

## Structure

```
docs/forms/
  README.md
  schema/form.schema.json
  supreme_court/          # divorce / matrimonial forms (UD-*, DRL-*, etc.)
  family_court/           # custody, visitation, child support, paternity, etc.
  manifest.json           # generated
  workflow_coverage.md    # generated
```

## Source file priority

Official court files may include multiple formats for the same form. The repository prefers:

1. **DOCX** — preferred editable source
2. **WPD converted to DOCX** — `converted_docx` slot
3. **Fillable PDF** — AcroForm fields
4. **Static PDF** — overlay rendering fallback

These drive `preferred_source`, `editable_source`, and `fillable_strategy`.

## Key schema fields

| Field | Purpose |
|-------|---------|
| `form_code` | Official OCA form code (canonical key) |
| `source_files` | All downloaded assets by slot (`pdf`, `fillable_pdf`, `docx`, `wpd`, etc.) |
| `preferred_source` | Highest-priority available slot |
| `editable_source` | Source type for editing/generation |
| `wpd_conversion` | `original_wpd` + `converted_docx` (original WPD never deleted) |
| `workflow_references` | Reverse index: which workflows/stages require this form |
| `fillable_strategy` | `docx_template`, `pdf_acroform`, `pdf_overlay`, or `none` |
| `field_mapping_status` | QA state: `unmapped`, `partial`, `mapped`, `not_required` |
| `generation_ready` | `true` when a usable source asset exists on disk |

## Build the repository

Generate canonical records from `forms_enriched.csv`, existing `prose_form` posts (when WordPress is available), and workflow-referenced form stubs:

```bash
# Standalone (no WP-CLI required)
php app/public/wp-content/plugins/prose-core/bin/build-forms-repository.php

# WP-CLI (enriches from prose_form posts when database is available)
wp prose forms build-repository
wp prose forms build-repository --dry-run
wp prose forms build-repository --convert-wpd
wp prose forms build-repository --csv=/path/to/forms_enriched.csv
```

The generator:

- Reads the full catalog from CSV
- Seeds stub records for workflow-referenced forms missing from CSV (e.g. UD-* divorce forms)
- Enriches records from WordPress `prose_form` post meta when running under WP-CLI
- Auto-builds `workflow_references` from the Workflow Repository
- Computes `preferred_source`, `editable_source`, `fillable_strategy`, `generation_ready`
- Preserves `field_mapping_status` on regeneration (manual QA progress is not clobbered)

## Validate

```bash
php app/public/wp-content/plugins/prose-core/bin/validate-forms.php
```

Validation checks:

1. Every workflow-required form exists in the repository
2. Every form has required metadata
3. `preferred_source` points to a valid `source_files` slot
4. Declared `source_files` paths exist on disk
5. Manifest counts are accurate

Outputs:

- `manifest.json` — aggregate counts
- `workflow_coverage.md` — per-workflow form and asset status

Validation **fails** if any workflow references a missing form.

## Runtime API

```php
$catalog = new \ProSe\Core\Forms\Forms_Catalog();

// All forms
$all = $catalog->all();

// Single form
$form = $catalog->by_code( 'UD-1' );

// Forms required by a workflow (Package Builder prep)
$codes = $catalog->get_forms_for_workflow( 'custody_nyc' );

// Full records for a workflow
$records = $catalog->get_form_records_for_workflow( 'custody_nyc' );

// Coverage gaps
$missing = $catalog->validate_workflow_coverage();
```

## Phase status

**Forms Repository: in progress.** Schema, generator, validator, manifest, and workflow coverage are implemented. Package Builder, PDF filling, and PDF merging are out of scope for this phase.
