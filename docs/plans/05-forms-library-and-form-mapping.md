# Plan 05 — Forms Library & Form Mapping

**Status:** Complete  
**Priority:** P1  
**Depends on:** Plan 04  
**Estimated effort:** Large (1–2 weeks)

---

## Goal

Deliver a searchable **Forms Library** where every form is mapped to court, county, workflow stage, dependencies, and official download source.

## Requirements reference

- PRD Ch. 17–18 — Forms Library, Form Mapping
- Divorce Ecosystem doc — form lists by phase (uncontested/contested)
- Workflow README — official `code` vs `internal_code`

## Current state

- Hundreds of form JSON files: `prose-core/docs/forms/{supreme_court,family_court}/`
- `Forms_Catalog`, classification engine, import tools exist
- Known divergence: UD-7 vs UD-11 judgment mapping
- Workflow `required_forms` partially populated per workflow

## Scope

### In scope

- Complete `required_forms` for all 12 MVP workflows (minimum: entry-stage forms)
- Form mapping: workflow stage → form code → prerequisites → next action
- Dependency engine: required-before / required-after (PRD Ch. 30)
- Public forms browse/search REST endpoint or shortcode
- Reconcile `internal_code` with `Vocabulary` / package catalog
- County dimension: all 5 NYC counties on every MVP form record

### Out of scope

- PDF field fill / assembly (Plan 06)
- Official link auto-scraper (Plan 17)
- Non-NYC counties (Plan 20)

## Deliverables

1. Form mapping matrix per workflow (audit spreadsheet or generated JSON)
2. Updated workflow JSON `required_forms` blocks
3. Forms search API: by code, court, issue, stage, county
4. Fix UD-7 / UD-11 and other known catalog divergences
5. Validation: every `required_forms.code` exists in forms catalog

## Acceptance criteria

- [ ] Uncontested divorce no children → correct UD packet forms listed
- [ ] Contested divorce → commencement forms + OSC motion forms mapped to stages
- [ ] OP workflow → Family Offense Petition (8-2) mapped
- [ ] User can search “UD-1” and see court, stage, workflow link
- [ ] Missing form codes fail CI validation

## Implementation tasks

1. Audit `inventory.json` required_forms vs Divorce Ecosystem doc
2. Fill gaps in workflow JSON
3. Implement or expose `Dependency_Engine` for form prerequisites
4. Build forms search REST route
5. Add catalog audit to `validate-workflows.php` or separate script
6. Document form code conventions

## Files likely touched

- `docs/workflows/**/*.json`
- `docs/forms/**/*.json`
- `modules/forms/class-forms-catalog.php`
- `modules/forms/engine/class-package-engine.php`
- `modules/forms/classification/class-vocabulary.php`

## Review questions

1. MVP: **blank PDFs only** or also **field mapping** for auto-fill?
2. Priority order for completing forms: divorce first, then family court?
