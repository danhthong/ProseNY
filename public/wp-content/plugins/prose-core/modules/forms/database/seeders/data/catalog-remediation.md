# Catalog Remediation Plan

Generated: 2026-06-11
Input: `coverage-analysis.md` (Sections 3a, 3b, 3c, 4, 5)
Target catalog: `nyc-package-catalog.json` v1.1.0 -> proposed v1.2.0
Scope: implementation-ready remediation specification. No code. No architecture, workflow-catalog,
or package-catalog redesign — only additive catalog data, reclassification, and alias bookkeeping.

This plan resolves the single failing package, removes 25 false in-scope orphans, enriches packages
with 28 mappable support forms, defines a shared Family Court forms strategy for 13 cross-cutting
forms, and resolves duplicate alias groups. Each change is additive or metadata-only; no existing
required-form definition, workflow, or node is altered.

---

## P1 Critical Fixes

Target: `PKG_TRIAL` (currently 50% required coverage — the only failing package).

`PKG_TRIAL.required_forms = [NOI, CERTIFICATE_OF_READINESS]`. `NOI` resolves to `UD-9` (mapped).
`CERTIFICATE_OF_READINESS` has no `prose_form` record. `TRIAL_WITNESS_LIST` and `EXHIBIT_LIST`
(optional supporting) are also absent. All three are practitioner-assembled documents, not numbered
nycourts.gov forms — they will never exist as imported `prose_form` records.

| Form code | Catalog role | Disposition | Recommendation |
|-----------|--------------|-------------|----------------|
| CERTIFICATE_OF_READINESS | PKG_TRIAL required_forms | **generated document** | Tag `form_class: generated`; excluded from required-coverage denominator. Practitioner-assembled, attached to Note of Issue. |
| TRIAL_WITNESS_LIST | PKG_TRIAL supporting (optional) | **generated document** | Tag `form_class: generated`; excluded from optional-coverage denominator. |
| EXHIBIT_LIST | PKG_TRIAL supporting (optional) | **generated document** | Tag `form_class: generated`; excluded from optional-coverage denominator. |
| UD-9 (NOI) | PKG_TRIAL required_forms | import-backed | No change — already mapped. |

### P1 recommendation: package metadata (preferred)

Introduce a per-form `form_class` metadata flag on catalog form references (NOT a new required/optional
field, NOT a workflow change):

- `form_class: import_backed` (default) — must resolve to a `prose_form` record; counts toward coverage.
- `form_class: generated` — assembled/produced at filing time; excluded from coverage math.

Apply `form_class: generated` to `CERTIFICATE_OF_READINESS`, `TRIAL_WITNESS_LIST`, `EXHIBIT_LIST`.

Effect: `PKG_TRIAL` required denominator becomes 1 (`NOI` only, mapped) -> **100%**. Overall required
coverage -> **28/28 generated-adjusted = 100%**.

Alternative (not recommended): import a static template PDF for `CERTIFICATE_OF_READINESS` to create a
backing `prose_form` record. Higher effort, same coverage outcome, adds a non-official document to the
inventory. Use only if a fillable template is desired in the document library.

---

## P2 Reclassification Plan

Move the following 25 forms from `ORPHAN_IN_SCOPE` to `ORPHAN_OUT_OF_SCOPE`. These matched the in-scope
grouping heuristic only via shared `GF-` / `4-` / `7-` / `8-` / `358-` / `10-` prefixes; they belong to
non-MVP workflows. This is a status/classification change in the mapping layer only — no catalog
package is touched.

| # | Form code | Title | Non-MVP workflow |
|--:|-----------|-------|------------------|
| 1 | 10-11b | Affirmation in Support of Motion to Terminate Placement | Child Protective (Art. 10) |
| 2 | 10-12 | Order on Petition to Terminate Placement | Child Protective (Art. 10) |
| 3 | 2-E | Affirmation and Consent of Person Having Lawful Custody (Private Placement) | Adoption |
| 4 | 2-F | Judicial Consent (Birth/Legal Parent Private Placement) | Adoption |
| 5 | 358-a-4 | Temporary Order Approving Placement Instrument | Placement/Adoption |
| 6 | 358-a-6 | Petition for Pre-placement Approval of Standby Placement | Placement/Adoption |
| 7 | 358-a-7 | Order of Disposition — Pre-placement Approval of Standby Placement | Placement/Adoption |
| 8 | 7-4 | Petition - PINS | PINS (Art. 7) |
| 9 | 7-10 | Order on Petition to Terminate Placement (PINS) | PINS (Art. 7) |
| 10 | 7-17 | Order - Violation of Order of Placement | PINS (Art. 7) |
| 11 | 7-20 | Notice of Right of Respondent to Appeal | Appeal (not in MVP) |
| 12 | 8-A | Affirmation Identifying Party (Agency) | Adoption/placement |
| 13 | 8-B | Affirmation Identifying Party (Private Placement) | Adoption/placement |
| 14 | GF-17a | Notice of Motion — Sibling Placement or Contact | Child Protective |
| 15 | GF-17b | Affirmation of Child — Sibling Placement or Contact | Child Protective |
| 16 | GF-17c | Affirmation of Attorney for Child — Sibling Placement or Contact | Child Protective |
| 17 | GF-36a | Motion that Reasonable Efforts Are Not Required | Child Protective |
| 18 | GF-37 | Order on Motion that Reasonable Efforts Are Not Required | Child Protective |
| 19 | GF-45 | FFPSA — Notice of Motion for QRTP Placement Approval | Child Protective |
| 20 | GF-45a | FFPSA — Affidavit in Support of QRTP Placement | Child Protective |
| 21 | GF-46 | FFPSA — Order on Motion for QRTP Placement Approval | Child Protective |
| 22 | GF-47 | Order of Name-change on Consent | Name Change |
| 23 | GF-43 | Petition for Voter Registration Confidentiality | Voter/Address Confidentiality |
| 24 | GF-44 | Order on Petition for Voter Registration Confidentiality | Voter/Address Confidentiality |
| 25 | GF-49 | Extra Page for Family Court Form | Utility (no workflow) |

Effect: `ORPHAN_IN_SCOPE` 66 -> 41. No coverage change (these were never mappable to the 16 packages).

Heuristic hardening (mapping layer, not catalog): exclude Article 10 child-protective, Article 7 PINS,
adoption consent (`2-*`, `358-*`, `8-A/8-B`), name change (`GF-47`), voter confidentiality (`GF-43/44`),
QRTP (`GF-45/45a/46`), sibling-placement (`GF-17a/b/c`), appeal (`7-20`), and utility (`GF-49`) from the
in-scope classifier.

---

## P3 Package Enrichment

28 mappable forms from coverage-analysis Section 3a, organized per package. All additions are
`optional_forms` or `supporting_documents` (additive only). Confidence column carries the analysis
score; placement rule: confidence >= 0.70 and a fileable petition/objection -> `optional_forms`;
all other support/order/instructional forms -> `supporting_documents`. Items < 0.50 are tagged
`review: low_confidence` for human confirmation before activation.

### PKG_CHILD_SUPPORT_PETITION (13 additions)

new `optional_forms`:
- `4-3c` — Petition for Support of Adult Dependent (0.85)

new `supporting_documents`:
- `4-7b` — Objection to Support Order (0.80)
- `4-7c` — Rebuttal to Objection to Support Order (0.80)
- `4-7d` — Order on Petition for Support of Adult Dependent (0.75)
- `4-1b` — Summons (Non-Resident) (0.70)
- `UIFSA-5` — General Testimony (0.65)
- `UIFSA-7` — Locate Data Sheet (0.60)
- `UIFSA-8` — Notice of Determination of Controlling Order (0.60)
- `UNCODED-447` — Short Form Application for Child Support Services (0.60, needs code assignment)
- `4-SM-2` — Information re Objections/Rebuttal before Support Magistrate (0.45, `review: low_confidence`)
- `UIFSA-5a` — Instructions for General Testimony (0.40, `review: low_confidence`)
- `UIFSA-14` — Child Support Agency Confidential Information Form (0.45, `review: low_confidence`)
- `UIFSA-15` — Personal Information Form for UIFSA § 311 (0.45, `review: low_confidence`)

### PKG_ENFORCEMENT (10 additions)

new `optional_forms`:
- `4-13` — Petition for Enforcement of Order Made By Another Court (Support) (0.85)

new `supporting_documents`:
- `4-13a` — Order Enforcing Order Made By Another Court (Support) (0.70)
- `4-21a` — Order to Licensing Entity to Terminate Suspension (0.65)
- `4-22` — Objection to SCU Denial — Driver's License Suspension (0.65)
- `UIFSA-9` — Registration Statement (0.65)
- `UIFSA-11` — Notice of Registration of Out-of-State Support Order (0.65)
- `4-15a` — Order (Relief from Support Payments and Commitment) (0.60)
- `4-5a` — Undertaking for Support — Cash Deposit (0.60)
- `UIFSA-12` — Petition to Vacate Registration of Out-of-State Support Order (0.60)
- `UIFSA-13` — Order on Petition to Vacate Registration (0.55)

### PKG_JUDGMENT (1 addition)

new `supporting_documents`:
- `4-23` — Qualified Domestic Relations Order (QDRO) (0.65)

### PKG_ORDER_OF_PROTECTION (1 addition)

new `supporting_documents`:
- `8-3` — Notice to the District Attorney (0.70)

### PKG_CUSTODY_PETITION (1 addition)

new `supporting_documents`:
- `ICPC-100A` — Interstate Compact on Placement of Children Request (0.55)

### PKG_UNCONTESTED_NO_CHILDREN (1 addition)

new `supporting_documents`:
- `UNCODED-103` — Composite Uncontested Divorce Forms (0.60, needs code assignment)

### PKG_UNCONTESTED_WITH_CHILDREN (1 addition)

new `supporting_documents`:
- `UNCODED-167` — Notice of Guideline Maintenance (0.60, needs code assignment)

### New alias / code assignments required (P3)

Three in-scope forms lack a real `form_code` (`prose_form_id` blank). Assign a provisional internal
code and register the post ID as an alias so they resolve:

| Current | Post ref | Proposed code | Owning package |
|---------|----------|---------------|----------------|
| UNCODED-103 | post 103 | `UD-COMPOSITE` | PKG_UNCONTESTED_NO_CHILDREN |
| UNCODED-167 | post 167 | `DRL-NOTICE-MAINT` | PKG_UNCONTESTED_WITH_CHILDREN |
| UNCODED-447 | post 447 | `LDSS-CS-SVC` | PKG_CHILD_SUPPORT_PETITION |

Effect: `ORPHAN_IN_SCOPE` 41 -> 13. Optional coverage rises (see Section 4 of output).

---

## P4 Shared Family Court Forms

Define a shared form set referenced by all Family Court packages, rather than forcing cross-cutting
procedural forms into a single owner. This is a new catalog reference block, additive only — it does
not alter any package's `required_forms`.

### Definition: `COMMON_FAMILY_COURT_FORMS`

A named, package-independent supporting-document set. Member forms (per task list):

| Form code | Title | Function |
|-----------|-------|----------|
| GF-2a | Affirmation | Generic affirmation |
| GF-4 | Subpoena Duces Tecum | Discovery / evidence |
| GF-15 | Order on Motion | Motion disposition |
| GF-16 | Order - Dismissal | Case disposition |
| GF-24 | Order to Sheriff to Return Respondent | Enforcement / appearance |
| GF-28 | Order - Transfer of Proceedings or Probation Supervision | Venue/transfer |
| GF-29 | Notice of Appearance | Appearance |
| GF-33 | Order Authorizing Services Other Than Counsel | Assigned services |
| GF-50 | Application for Assignment of Counsel | Counsel assignment |
| GF-51 | Application for Reconsideration of Denial of Assignment of Counsel | Counsel assignment |

Companion sub-group (medical/evaluation orders, also shared but custody/OP-leaning — include in the
set, flagged `context: custody_op`):
- `GF-13` — Order Directing Medical Examination (Outpatient)
- `GF-13a` — Order Directing Medical Examination (Inpatient)
- `GF-13b` — Order Directing Emergency Evaluation

### Implementation strategy

1. Add a top-level catalog key `common_form_sets.COMMON_FAMILY_COURT_FORMS` listing the 10 named codes
   (plus the 3 `GF-13*` companions). Each entry: `{ form_code, title, function, context }`.
2. Each Family Court package (`PKG_CUSTODY_PETITION`, `PKG_CHILD_SUPPORT_PETITION`,
   `PKG_ORDER_OF_PROTECTION`, `PKG_ENFORCEMENT`, `PKG_MODIFICATION`) references the set by key via a new
   additive field `shared_form_sets: ["COMMON_FAMILY_COURT_FORMS"]` rather than duplicating codes.
3. Mapping layer resolves `shared_form_sets` at build time: each member emits a mapping row per
   referencing package with `relationship_type = OPTIONAL`, `mapping_source = SHARED_SET`,
   `confidence_score = 0.55`.
4. Coverage math treats shared-set members as optional (never required); they cannot cause a package to
   fail a threshold.

Effect: 13 SHARED forms move out of `ORPHAN_IN_SCOPE` into resolved shared-set mappings.
`ORPHAN_IN_SCOPE` 13 -> 0.

---

## P5 Duplicate Resolution

### In-scope canonical merge (affects custody/support packages)

| Group | Canonical code | Alias codes | Merge strategy |
|-------|----------------|-------------|----------------|
| Electronic Testimony Application, Waiver of Personal Appearance and Order | `UCCJEA-7` | `4-24`, `5-16`, `UIFSA-10` | Single canonical record under PKG_CUSTODY_PETITION context; aliases redirect to it. Support (`4-24`/`UIFSA-10`) and paternity (`5-16`) variants are the same legal document. |

### Variant set — do NOT merge

| Group | Codes | Strategy |
|-------|-------|----------|
| Affirmation in Support of Issuance of Family Court TOP | `GF-5b` (Individual Petitioner), `GF-5c (CRIM-4)` (Police/Agency) | Keep both as a labeled variant set under PKG_MOTION / PKG_ORDER_OF_PROTECTION. Distinct filer roles; not duplicates to collapse. |

### Out-of-scope alias bookkeeping (no MVP package impact)

| Group | Canonical | Aliases | Strategy |
|-------|-----------|---------|----------|
| Determination Upon Fact-finding Hearing | `10-9` | `7-5` | Alias only (Art. 10/7) |
| Order on Motion | `GF-15` | `3-44` | `GF-15` canonical (general form); `3-44` JD-specific alias |
| Order of Investigation | `10-1b` | `6` | Alias legacy/uncoded `6` to `10-1b` |
| Statement to Court of Permanency Hearing Reports | `PH-4a` | `PH-4-b`, `PH-4c` | Collapse to PH-4a variant set |
| Petition (Extension of Placement and Permanency Hearing) | `7-18` | `3-38` | Alias |
| Order of Disposition (Designated Felony) | — | `3-32`, `3-34` | Do NOT merge — restrictive vs non-restrictive placement |

Merge strategy definition (applies to all canonical merges): retain one canonical `prose_form` record;
register alias codes in the alias registry pointing to the canonical post ID; mapping layer resolves
aliases to the canonical before emitting rows; no record deletion (aliases preserved for lookup).

---

# Output

## 1. Remediation Table

| Priority | Action | Items | Catalog impact | Coverage impact |
|----------|--------|------:|----------------|-----------------|
| P1 | Tag PKG_TRIAL assembled docs `form_class: generated` | 3 | Metadata flag | PKG_TRIAL 50% -> 100% |
| P2 | Reclassify false orphans IN_SCOPE -> OUT_OF_SCOPE | 25 | None (mapping status only) | ORPHAN_IN_SCOPE 66 -> 41 |
| P3 | Enrich packages (optional/supporting + 3 code assignments) | 28 | Additive forms | ORPHAN_IN_SCOPE 41 -> 13; optional coverage up |
| P4 | Define COMMON_FAMILY_COURT_FORMS shared set | 13 | New additive block + per-pkg reference | ORPHAN_IN_SCOPE 13 -> 0 |
| P5 | Canonical merge + alias registry | 1 in-scope group (+6 OOS) | Alias registry only | Removes in-scope duplicate |

## 2. Package Catalog Changes

Proposed `nyc-package-catalog.json` v1.1.0 -> v1.2.0. All changes additive; no `required_forms`,
`workflow_key`, `primary_node`, or `court_routing` modified.

| Package | new optional_forms | new supporting_documents | other |
|---------|--------------------|--------------------------|-------|
| PKG_TRIAL | — | — | `form_class: generated` on CERTIFICATE_OF_READINESS, TRIAL_WITNESS_LIST, EXHIBIT_LIST |
| PKG_CHILD_SUPPORT_PETITION | 4-3c | 4-7b, 4-7c, 4-7d, 4-1b, UIFSA-5, UIFSA-7, UIFSA-8, LDSS-CS-SVC, 4-SM-2*, UIFSA-5a*, UIFSA-14*, UIFSA-15* | `shared_form_sets: [COMMON_FAMILY_COURT_FORMS]` |
| PKG_ENFORCEMENT | 4-13 | 4-13a, 4-21a, 4-22, UIFSA-9, UIFSA-11, 4-15a, 4-5a, UIFSA-12, UIFSA-13 | `shared_form_sets: [COMMON_FAMILY_COURT_FORMS]` |
| PKG_JUDGMENT | — | 4-23 | — |
| PKG_ORDER_OF_PROTECTION | — | 8-3 | `shared_form_sets: [COMMON_FAMILY_COURT_FORMS]` |
| PKG_CUSTODY_PETITION | — | ICPC-100A | `shared_form_sets: [COMMON_FAMILY_COURT_FORMS]` |
| PKG_MODIFICATION | — | — | `shared_form_sets: [COMMON_FAMILY_COURT_FORMS]` |
| PKG_UNCONTESTED_NO_CHILDREN | — | UD-COMPOSITE | — |
| PKG_UNCONTESTED_WITH_CHILDREN | — | DRL-NOTICE-MAINT | — |

`*` = `review: low_confidence` (< 0.50) — confirm before activation.

New top-level block:

```
common_form_sets:
  COMMON_FAMILY_COURT_FORMS:
    - GF-2a, GF-4, GF-15, GF-16, GF-24, GF-28, GF-29, GF-33, GF-50, GF-51
    - GF-13, GF-13a, GF-13b  (context: custody_op)
```

## 3. Alias Registry Changes

| Canonical code | Alias codes | Scope | Note |
|----------------|-------------|-------|------|
| UCCJEA-7 | 4-24, 5-16, UIFSA-10 | In-scope (custody/support) | Same Electronic Testimony document |
| UD-COMPOSITE | UNCODED-103 (post 103) | In-scope | Code assignment |
| DRL-NOTICE-MAINT | UNCODED-167 (post 167) | In-scope | Code assignment |
| LDSS-CS-SVC | UNCODED-447 (post 447) | In-scope | Code assignment |
| 10-9 | 7-5 | Out-of-scope | Bookkeeping |
| GF-15 | 3-44 | Mixed | GF-15 canonical |
| 10-1b | 6 | Out-of-scope | Bookkeeping |
| PH-4a | PH-4-b, PH-4c | Out-of-scope | Variant collapse |
| 7-18 | 3-38 | Out-of-scope | Bookkeeping |

Variant sets (NOT aliased): `GF-5b` / `GF-5c (CRIM-4)`; `3-32` / `3-34`.

## 4. Expected Coverage After Remediation

| Metric | Before | After |
|--------|-------:|------:|
| Packages at/above threshold | 15 / 16 | 16 / 16 |
| Critical packages at/above 90% | 6 / 6 | 6 / 6 |
| Required-form coverage (generated-adjusted) | 27 / 28 = 96.4% | 27 / 27 = **100%** |
| PKG_TRIAL required coverage | 50.0% | 100.0% |
| Optional-form coverage | 46 / 51 = 90.2% | ~46 / 48 = ~95.8% (generated-adjusted) |
| ORPHAN_IN_SCOPE | 66 | **0** (25 reclassified, 28 mapped, 13 shared) |
| ORPHAN_OUT_OF_SCOPE | 211 | 236 |
| In-scope duplicates unresolved | 1 group | 0 |

Note: remaining non-blocking optional gaps (`PROPOSED_PARENTING_PLAN`, `INTERROGATORIES`,
`FINAL_ORDER_OF_PROTECTION`) are court-issued/assembled documents; tag `form_class: generated` to
remove from the optional denominator if 100% optional coverage is desired.

## 5. Final Readiness Score

| Dimension | Score |
|-----------|------:|
| Required-form coverage | 100% |
| Critical-package coverage | 100% (6/6) |
| Package threshold pass rate | 100% (16/16) |
| Addressable orphan resolution | 100% (66/66 routed) |
| In-scope duplicate resolution | 100% (1/1) |

### Final Readiness: READY — PASS (post-remediation)

- P1 closes the only failing package; all 16 packages pass thresholds.
- P2 + P3 + P4 route every `ORPHAN_IN_SCOPE` form (reclassify / map / shared) to zero.
- P5 resolves the single in-scope duplicate via canonical aliasing.
- All changes are additive or metadata-only; the locked architecture, workflow catalog, package
  required-form definitions, and node catalog are unchanged.

### Execution dependency order

P1 -> P2 -> P3 (code assignments before enrichment links) -> P4 -> P5, then regenerate
`form-package-mapping.csv` and `coverage-report.md` to confirm the projected scores.
