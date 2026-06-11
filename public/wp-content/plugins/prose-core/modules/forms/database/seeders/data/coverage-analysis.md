# Form Mapping Layer — Coverage Analysis & Gap Resolution

Generated: 2026-06-11
Input: `form-package-mapping.csv` (744 rows, 490 distinct `prose_form` records)
Catalog: `nyc-package-catalog.json` v1.1.0 (16 production packages)
Scope: analysis only. No architecture changes, no catalog edits, no code.

This document analyzes coverage against the locked CourtFlow AI package catalog, resolves
in-scope orphans, deduplicates collisions, and recommends catalog improvements. It does not
modify the Workflow Catalog, Package Catalog, or Node Catalog.

---

## 1. Package Coverage Table

`coverage_percentage` = `mapped_required_forms / required_forms_count`.
`optional_forms_count` combines catalog `optional_forms` + `supporting_documents`.

| Package | required_forms_count | mapped_required_forms | missing_required_forms | optional_forms_count | mapped_optional_forms | coverage_percentage | Status |
|---------|---------------------:|----------------------:|------------------------|---------------------:|----------------------:|--------------------:|--------|
| PKG_UNCONTESTED_NO_CHILDREN | 2 | 2 | — | 2 | 2 | 100.0% | OK (critical) |
| PKG_UNCONTESTED_WITH_CHILDREN | 3 | 3 | — | 4 | 4 | 100.0% | OK (critical) |
| PKG_CONTESTED_COMMENCEMENT | 2 | 2 | — | 3 | 3 | 100.0% | OK (critical) |
| PKG_CUSTODY_PETITION | 1 | 1 | — | 5 | 4 | 100.0% | OK (critical) |
| PKG_CHILD_SUPPORT_PETITION | 1 | 1 | — | 4 | 4 | 100.0% | OK (critical) |
| PKG_ORDER_OF_PROTECTION | 1 | 1 | — | 4 | 3 | 100.0% | OK (critical) |
| PKG_SERVICE | 1 | 1 | — | 3 | 3 | 100.0% | OK |
| PKG_RESPONSE | 1 | 1 | — | 0 | 0 | 100.0% | OK |
| PKG_DEFAULT_DIVORCE | 3 | 3 | — | 3 | 3 | 100.0% | OK |
| PKG_DISCOVERY | 3 | 3 | — | 3 | 2 | 100.0% | OK |
| PKG_MOTION | 2 | 2 | — | 1 | 1 | 100.0% | OK |
| PKG_SETTLEMENT | 1 | 1 | — | 3 | 3 | 100.0% | OK |
| PKG_TRIAL | 2 | 1 | CERTIFICATE_OF_READINESS | 2 | 0 | 50.0% | **FAIL** |
| PKG_JUDGMENT | 3 | 3 | — | 6 | 6 | 100.0% | OK |
| PKG_ENFORCEMENT | 1 | 1 | — | 4 | 4 | 100.0% | OK |
| PKG_MODIFICATION | 1 | 1 | — | 4 | 4 | 100.0% | OK |
| **TOTAL** | **28** | **27** | **1** | **51** | **46** | **96.4%** | 1 package failing |

### Coverage validation

- Threshold: every package `>= 80%`; critical packages `>= 90%`.
- All 6 critical packages pass at 100%.
- `PKG_TRIAL` fails at 50% — single required-form gap (`CERTIFICATE_OF_READINESS`).

---

## 2. Missing Catalog Form References (inventory gaps)

Catalog-declared codes with **no matching `prose_form` record** in the 490-form NYC import.
These are inventory gaps, not mapping-logic failures — the catalog references a form the
import does not contain.

| Package | Field | Missing code | Impact |
|---------|-------|--------------|--------|
| PKG_TRIAL | required_forms | CERTIFICATE_OF_READINESS | Drops PKG_TRIAL to 50% — only blocking gap |
| PKG_TRIAL | optional (supporting) | TRIAL_WITNESS_LIST | Non-blocking |
| PKG_TRIAL | optional (supporting) | EXHIBIT_LIST | Non-blocking |
| PKG_CUSTODY_PETITION | optional_forms | PROPOSED_PARENTING_PLAN | Non-blocking |
| PKG_DISCOVERY | optional_forms | INTERROGATORIES | Non-blocking |
| PKG_ORDER_OF_PROTECTION | optional (supporting) | FINAL_ORDER_OF_PROTECTION | Non-blocking (court-issued outcome doc) |

Note: `CERTIFICATE_OF_READINESS`, `TRIAL_WITNESS_LIST`, and `EXHIBIT_LIST` are practitioner-assembled
documents, not numbered nycourts.gov forms; they will not exist as `prose_form` records. The Note of
Issue (`UD-9`) — the other PKG_TRIAL required item — is present and mapped.

---

## 3. Orphan In Scope Report

66 forms classified `ORPHAN_IN_SCOPE` (relevant to a package but unmapped). Analysis splits
them into three remediation tracks:

- **MAP** (28) — genuine in-scope support/petition forms that should be linked to a package.
- **RECLASSIFY** (25) — child-protective / placement / PINS / adoption / name-change / voter-confidentiality / appeal forms that the prefix-based grouping captured as in-scope but belong to non-MVP workflows; should become `ORPHAN_OUT_OF_SCOPE`.
- **SHARED** (13) — cross-cutting General-Family (`GF-`) procedural forms used across many packages; no single owning package.

Confidence = confidence that the form belongs to the suggested package.

### 3a. MAP — link to a package (catalog enrichment)

| Form code | Title | Likely package | Confidence | Remediation |
|-----------|-------|----------------|-----------:|-------------|
| 4-3c | Petition for Support of Adult Dependent | PKG_CHILD_SUPPORT_PETITION | 0.85 | Add to `optional_forms` (petition variant) |
| 4-7b | Objection to Support Order | PKG_CHILD_SUPPORT_PETITION | 0.80 | Add as supporting document |
| 4-7c | Rebuttal to Objection to Support Order | PKG_CHILD_SUPPORT_PETITION | 0.80 | Add as supporting document |
| 4-7d | Order on Petition for Support of Adult Dependent | PKG_CHILD_SUPPORT_PETITION | 0.75 | Add as supporting (order) |
| 4-1b | Summons (Non-Resident) | PKG_CHILD_SUPPORT_PETITION | 0.70 | Add as supporting (service variant) |
| 4-SM-2 | Information re Objections/Rebuttal before Support Magistrate | PKG_CHILD_SUPPORT_PETITION | 0.45 | Informational — link low priority |
| 4-13 | Petition for Enforcement of Order Made By Another Court (Support) | PKG_ENFORCEMENT | 0.85 | Add to `optional_forms` |
| 4-13a | Order Enforcing Order Made By Another Court (Support) | PKG_ENFORCEMENT | 0.70 | Add as supporting (order) |
| 4-15a | Order (Relief from Support Payments and Commitment) | PKG_ENFORCEMENT | 0.60 | Add as supporting (order) |
| 4-21a | Order to Licensing Entity to Terminate Suspension | PKG_ENFORCEMENT | 0.65 | Add as supporting (order) |
| 4-22 | Objection to SCU Denial — Driver's License Suspension | PKG_ENFORCEMENT | 0.65 | Add as supporting |
| 4-5a | Undertaking for Support — Cash Deposit | PKG_ENFORCEMENT | 0.60 | Add as supporting |
| 4-23 | Qualified Domestic Relations Order (QDRO) | PKG_JUDGMENT | 0.65 | Add as supporting (retirement division at judgment) |
| UIFSA-5 | General Testimony | PKG_CHILD_SUPPORT_PETITION | 0.65 | Add as supporting (interstate support) |
| UIFSA-5a | Instructions for General Testimony | PKG_CHILD_SUPPORT_PETITION | 0.40 | Instructional — link low priority |
| UIFSA-7 | Locate Data Sheet | PKG_CHILD_SUPPORT_PETITION | 0.60 | Add as supporting |
| UIFSA-8 | Notice of Determination of Controlling Order | PKG_CHILD_SUPPORT_PETITION | 0.60 | Add as supporting |
| UIFSA-14 | Child Support Agency Confidential Information Form | PKG_CHILD_SUPPORT_PETITION | 0.45 | Add as supporting (low) |
| UIFSA-15 | Personal Information Form for UIFSA § 311 | PKG_CHILD_SUPPORT_PETITION | 0.45 | Add as supporting (low) |
| UIFSA-9 | Registration Statement | PKG_ENFORCEMENT | 0.65 | Add as supporting (registration for enforcement) |
| UIFSA-11 | Notice of Registration of Out-of-State Support Order | PKG_ENFORCEMENT | 0.65 | Add as supporting |
| UIFSA-12 | Petition to Vacate Registration of Out-of-State Support Order | PKG_ENFORCEMENT | 0.60 | Add as supporting |
| UIFSA-13 | Order on Petition to Vacate Registration | PKG_ENFORCEMENT | 0.55 | Add as supporting (order) |
| 8-3 | Notice to the District Attorney | PKG_ORDER_OF_PROTECTION | 0.70 | Add as supporting |
| ICPC-100A | Interstate Compact on Placement of Children Request | PKG_CUSTODY_PETITION | 0.55 | Add as supporting (interstate custody) |
| UNCODED-103 | Composite Uncontested Divorce Forms | PKG_UNCONTESTED_NO_CHILDREN | 0.60 | Assign code; link composite packet |
| UNCODED-167 | Notice of Guideline Maintenance | PKG_UNCONTESTED_WITH_CHILDREN | 0.60 | Assign code; link (maintenance notice) |
| UNCODED-447 | Short Form Application for Child Support Services | PKG_CHILD_SUPPORT_PETITION | 0.60 | Assign code; link |

### 3b. RECLASSIFY — move to `ORPHAN_OUT_OF_SCOPE`

These matched the in-scope heuristic via shared `GF-` / `4-` / `7-` / `8-` / `358-` prefixes but
belong to non-MVP workflows (child-protective Article 10, placement/standby, PINS Article 7,
adoption consents, name change, voter confidentiality, appeal).

| Form code | Title | Actual workflow | Recommendation |
|-----------|-------|-----------------|----------------|
| 10-11b | Affirmation in Support of Motion to Terminate Placement | Child Protective (Art. 10) | Reclassify OUT_OF_SCOPE |
| 10-12 | Order on Petition to Terminate Placement | Child Protective (Art. 10) | Reclassify OUT_OF_SCOPE |
| 2-E | Affirmation and Consent of Person Having Lawful Custody (Private Placement) | Adoption | Reclassify OUT_OF_SCOPE |
| 2-F | Judicial Consent (Birth/Legal Parent Private Placement) | Adoption | Reclassify OUT_OF_SCOPE |
| 358-a-4 | Temporary Order Approving Placement Instrument | Placement/Adoption | Reclassify OUT_OF_SCOPE |
| 358-a-6 | Petition for Pre-placement Approval of Standby Placement | Placement/Adoption | Reclassify OUT_OF_SCOPE |
| 358-a-7 | Order of Disposition — Pre-placement Approval of Standby Placement | Placement/Adoption | Reclassify OUT_OF_SCOPE |
| 7-4 | Petition - PINS | PINS (Art. 7) | Reclassify OUT_OF_SCOPE |
| 7-10 | Order on Petition to Terminate Placement (PINS) | PINS (Art. 7) | Reclassify OUT_OF_SCOPE |
| 7-17 | Order - Violation of Order of Placement | PINS (Art. 7) | Reclassify OUT_OF_SCOPE |
| 7-20 | Notice of Right of Respondent to Appeal | Appeal (not in MVP) | Reclassify OUT_OF_SCOPE |
| 8-A | Affirmation Identifying Party (Agency) | Adoption/placement | Reclassify OUT_OF_SCOPE |
| 8-B | Affirmation Identifying Party (Private Placement) | Adoption/placement | Reclassify OUT_OF_SCOPE |
| GF-17a | Notice of Motion — Sibling Placement or Contact | Child Protective | Reclassify OUT_OF_SCOPE |
| GF-17b | Affirmation of Child — Sibling Placement or Contact | Child Protective | Reclassify OUT_OF_SCOPE |
| GF-17c | Affirmation of Attorney for Child — Sibling Placement or Contact | Child Protective | Reclassify OUT_OF_SCOPE |
| GF-36a | Motion that Reasonable Efforts Are Not Required | Child Protective | Reclassify OUT_OF_SCOPE |
| GF-37 | Order on Motion that Reasonable Efforts Are Not Required | Child Protective | Reclassify OUT_OF_SCOPE |
| GF-45 | FFPSA — Notice of Motion for QRTP Placement Approval | Child Protective | Reclassify OUT_OF_SCOPE |
| GF-45a | FFPSA — Affidavit in Support of QRTP Placement | Child Protective | Reclassify OUT_OF_SCOPE |
| GF-46 | FFPSA — Order on Motion for QRTP Placement Approval | Child Protective | Reclassify OUT_OF_SCOPE |
| GF-47 | Order of Name-change on Consent | Name Change | Reclassify OUT_OF_SCOPE |
| GF-43 | Petition for Voter Registration Confidentiality | Voter/Address Confidentiality | Reclassify OUT_OF_SCOPE |
| GF-44 | Order on Petition for Voter Registration Confidentiality | Voter/Address Confidentiality | Reclassify OUT_OF_SCOPE |
| GF-49 | Extra Page for Family Court Form | Utility (no workflow) | Reclassify OUT_OF_SCOPE |

### 3c. SHARED — cross-cutting General Family Court procedural forms

These `GF-` forms are used across multiple Family Court packages and have no single owning
package. They are not true coverage gaps; they need a shared reference (see Remediation 4d).

| Form code | Title | Used across | Confidence (single pkg) |
|-----------|-------|-------------|------------------------:|
| GF-2a | Affirmation | All FC packages | 0.30 |
| GF-4 | Subpoena Duces Tecum | DISCOVERY / MOTION / OP | 0.40 |
| GF-13 | Order Directing Medical Examination (Outpatient) | CUSTODY / OP | 0.35 |
| GF-13a | Order Directing Medical Examination (Inpatient) | CUSTODY / OP | 0.35 |
| GF-13b | Order Directing Emergency Evaluation | CUSTODY / OP | 0.35 |
| GF-15 | Order on Motion | MOTION (all FC) | 0.50 |
| GF-16 | Order - Dismissal | All FC packages | 0.35 |
| GF-24 | Order to Sheriff to Return Respondent | ENFORCEMENT / OP | 0.45 |
| GF-28 | Order - Transfer of Proceedings or Probation Supervision | All FC packages | 0.30 |
| GF-29 | Notice of Appearance | All FC packages | 0.35 |
| GF-33 | Order Authorizing Services Other Than Counsel | All FC packages | 0.30 |
| GF-50 | Application for Assignment of Counsel | All FC packages | 0.35 |
| GF-51 | Application for Reconsideration of Denial of Assignment of Counsel | All FC packages | 0.30 |

---

## 4. Duplicate Report

The mapping flagged 3 forms `DUPLICATE` (normalized-title collisions among in-scope/mapped rows).
Title-collision scan across the full 490-form inventory surfaces the related groups below.

### 4a. In-scope duplicates (flagged in CSV)

| Duplicate form codes | Title | Canonical | Merge recommendation |
|----------------------|-------|-----------|----------------------|
| GF-5b, GF-5c (CRIM-4) | Affirmation in Support of Issuance of Family Court TOP | GF-5b | Keep both — distinct variants (Individual Petitioner vs Police/Agency). Do NOT merge; relabel as variant set under PKG_MOTION / PKG_ORDER_OF_PROTECTION. |
| UCCJEA-7 | Electronic Testimony Application, Waiver of Personal Appearance and Order | UCCJEA-7 | Canonical for custody (UCCJEA) context. See cross-article group 4b. |

### 4b. Cross-article title collisions (same document, different article numbering)

These are the **same legal document** issued under different article prefixes. Recommend a single
canonical code with the others as aliases.

| Collision group | Title | Canonical (recommended) | Merge recommendation |
|-----------------|-------|-------------------------|----------------------|
| 4-24, 5-16, UIFSA-10, UCCJEA-7 | Electronic Testimony Application, Waiver of Personal Appearance and Order | UCCJEA-7 | Register 4-24 / 5-16 / UIFSA-10 as aliases of UCCJEA-7 (support/paternity/custody variants of one form). |
| 10-9, 7-5 | Determination Upon Fact-finding Hearing | 10-9 | Out-of-scope (Art. 10/7) — alias only; no MVP package. |
| 3-44, GF-15 | Order on Motion | GF-15 | GF-15 is the general-form canonical; 3-44 is JD-specific (out-of-scope). |
| 10-1b, 6 | Order of Investigation | 10-1b | Out-of-scope; alias `6` (uncoded/legacy) to 10-1b. |
| PH-4a, PH-4-b, PH-4c | Statement to Court of Permanency Hearing Reports | PH-4a | Out-of-scope (permanency); collapse to PH-4a variant set. |
| 3-32, 3-34 | Order of Disposition (Designated Felony) | distinct | Do NOT merge — restrictive vs non-restrictive placement (out-of-scope). |
| 3-38, 7-18 | Petition (Extension of Placement and Permanency Hearing) | 7-18 | Out-of-scope; alias. |

Only the **Electronic Testimony** group (4b row 1) affects in-scope packages (custody/support).
All other collisions are within non-MVP workflows and need only alias bookkeeping.

---

## 5. Recommended Remediation Actions

Priority order. All are catalog/data actions; none touch architecture.

### 5a. P1 — Close the only failing package (PKG_TRIAL)
- `CERTIFICATE_OF_READINESS`, `TRIAL_WITNESS_LIST`, `EXHIBIT_LIST` are practitioner-assembled, not
  numbered NY forms. Recommend marking them in the catalog as `generated`/`assembled` (not
  import-backed) so coverage math excludes them, OR import a template. Either lifts PKG_TRIAL to
  100% required coverage. `UD-9` (Note of Issue) is already mapped.

### 5b. P2 — Reclassify 25 false in-scope orphans (Section 3b)
- Tighten the grouping heuristic so Article 10 (child protective), Article 7 (PINS), adoption
  consents (`2-E/2-F/358-a-*`), name change (`GF-47`), voter confidentiality (`GF-43/44`), QRTP
  (`GF-45/45a/46`), sibling-placement (`GF-17a/b/c`), and appeal (`7-20`) are `ORPHAN_OUT_OF_SCOPE`.
- Effect: ORPHAN_IN_SCOPE drops 66 -> 41; removes noise from coverage gap review.

### 5c. P3 — Enrich catalog with 28 mappable support forms (Section 3a)
- Add Article 4 post-order and UIFSA support forms to `PKG_CHILD_SUPPORT_PETITION` /
  `PKG_ENFORCEMENT` `optional_forms`/`supporting_documents`.
- Add `4-23` (QDRO) to `PKG_JUDGMENT`; `8-3` to `PKG_ORDER_OF_PROTECTION`; `ICPC-100A` to
  `PKG_CUSTODY_PETITION`.
- Assign real codes to the 3 `UNCODED-*` in-scope forms and link them.

### 5d. P4 — Introduce a shared "Common Family Court Forms" reference (Section 3c)
- 13 cross-cutting `GF-` procedural forms (subpoena, notice of appearance, assignment of counsel,
  affirmation, dismissal, order on motion) belong to no single package. Recommend a shared
  supporting-document set referenced by all Family Court packages rather than forcing a 1:1 map.

### 5e. P5 — Resolve duplicates via aliases (Section 4)
- Register `4-24`, `5-16`, `UIFSA-10` as aliases of `UCCJEA-7` (only in-scope duplicate).
- Record remaining collisions as alias bookkeeping within their (out-of-scope) workflows.

---

## 6. Final Coverage Score

| Metric | Value |
|--------|------:|
| Packages at/above threshold | 15 / 16 (93.8%) |
| Critical packages at/above 90% | 6 / 6 (100%) |
| Required-form coverage (weighted) | 27 / 28 = **96.4%** |
| Optional-form coverage (weighted) | 46 / 51 = 90.2% |
| Mapped forms | 213 / 490 (43.5%) |
| Orphan In Scope | 66 (28 mappable, 25 reclassify, 13 shared) |
| Orphan Out Of Scope | 211 |
| Duplicates | 3 flagged (1 in-scope group) |

### Final Coverage Score: 96.4% (required) — CONDITIONAL PASS

- All 6 critical packages: 100%.
- Single blocking gap: `PKG_TRIAL` `CERTIFICATE_OF_READINESS` (assembled document, not an import gap).
- Projected required-form coverage after P1 remediation: **100%**.
- Projected ORPHAN_IN_SCOPE after P2 reclassification: **41** (true addressable gaps), of which
  P3 enrichment maps **28**, leaving 13 shared-procedural forms for P4.
