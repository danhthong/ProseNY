# Plan 13 — County Rules Layer

**Status:** Complete  
**Priority:** P2  
**Depends on:** Plan 05  
**Estimated effort:** Medium (4–6 days)

---

## Goal

Apply **NYC county-specific** filing instructions, local rules, and procedural differences across all 5 boroughs.

## Requirements reference

- PRD Ch. 22 — County Rules Layer
- Divorce Ecosystem — Kings, New York County, Queens differences
- MVP: Manhattan, Brooklyn, Queens, Bronx, Staten Island

## Current state

- Workflows list `counties_supported` for all 5
- `County_Classifier`, `County_Guidance_Resolver` in forms/guidance modules
- Borough → county mapping in fact extractor (Brooklyn → Kings)
- County-specific content largely unseeded

## Scope

### In scope

- County rules data model: `{ county, court, topic, instruction, source_url, effective_date }`
- Seed MVP rules for matrimonial parts (e-filing notes, conference procedures)
- Inject county guidance into package instructions + procedural navigator
- Intake collects county early (already required field)
- Validation: every workflow supports all 5 counties or documents exceptions

### Out of scope

- Non-NYC counties (Plan 20)
- Automated scraping of local rules (Plan 17)

## Deliverables

1. County rules JSON or DB table seeded for 5 counties × 2 courts (minimum viable set)
2. API: guidance for `{ workflow, county, stage }`
3. UI: county-specific “Before you file” notes on package page
4. Documentation of official sources per county

## Acceptance criteria

- [ ] Kings County divorce filing shows Kings-specific note where different from Queens
- [ ] County name normalized (Brooklyn → Kings) everywhere
- [ ] Source URL linked for each county rule entry
- [ ] Missing county rule does not block package download

## Implementation tasks

1. Research and enter top 3 differences per county from Divorce Ecosystem doc
2. Implement resolver integration in guidance + package builder
3. Admin preview of county rules (simple list before Plan 14)
4. Tests: each county resolves guidance without error

## Files likely touched

- `modules/guidance/class-county-guidance-resolver.php`
- `modules/forms/classification/class-county-classifier.php`
- New `docs/county-rules/` or DB seeder

## Review questions

1. Who maintains county rules content ongoing — ops team via admin (Plan 14)?
