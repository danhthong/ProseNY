# CourtFlow Catalog Audit Report

Generated: 2026-06-11 16:45:12 UTC
Source: live database `local` (read-only)
Final Result: **PASS**

## Summary

| Domain | Records | Status |
|--------|--------:|--------|
| Workflows | 12 | OK |
| Nodes | 22 | OK |
| Edges | 17 | OK |
| Packages | 39 | OK |
| Package Relations | 50 | OK |
| Package Forms | 380 | OK |
| Node-Package Links | 39 | OK |
| Alias Registry | 12 aliases / 9 canonical | OK |

- Hard failures: **0**
- Soft warnings: **27**

## Workflow Counts

- Total workflows: 12
- Active workflows: 12
- Empty workflow_key: 0
- Duplicate workflow keys: 0
- Empty workflows (no nodes): 3
  - DEFAULT_DIVORCE
  - EMERGENCY_RELIEF
  - VISITATION

## Node Counts

- Total nodes: 22
- Active nodes: 22
- Total edges: 17
- Duplicate node keys: 0
- Orphan nodes (missing workflow): 0
- Orphan edges (missing node): 0

## Package Counts

- Total package CPT posts: 39
- Packages with package_key: 39
- Active packages: 39
- Duplicate package keys: 0
- Empty packages (no forms): 0
- Package relations: 50
- Invalid package relations: 0
- Duplicate package relations: 0
- Self-referential relations: 0
- Node-package links: 39

## Form Mapping Counts

- Total package_form rows: 380
- Required: 88
- Optional: 292
- Supporting: 0
- Resolved to prose_form (form_id set): 311
- Unresolved (form_id NULL): 69
- Duplicate package_form keys: 0

## Orphan Records

- Orphan nodes: 0
- Orphan edges: 0
- Orphan package_forms: 0
- Orphan node_packages: 0
- Invalid package relations: 0
- Dangling aliases (missing canonical): 0

## Duplicate Records

- Duplicate workflow keys: 0
- Duplicate node keys: 0
- Duplicate package keys: 0
- Duplicate package relations: 0
- Duplicate package_form keys: 0

## Coverage Metrics

Required import-backed form coverage per package (form_id resolved / required).

| Package | Required | Resolved | Coverage % | Threshold | Status |
|---------|---------:|---------:|-----------:|----------:|--------|
| PKG_CHILD_SUPPORT_HEARING | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_CHILD_SUPPORT_ORDER | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_CHILD_SUPPORT_PETITION (critical) | 3 | 1 | 33.3% | 90% | FAIL |
| PKG_CONTESTED_COMMENCEMENT (critical) | 3 | 2 | 66.7% | 90% | FAIL |
| PKG_CONTESTED_DISCOVERY | 2 | 0 | 0.0% | 80% | FAIL |
| PKG_CONTESTED_JUDGMENT | 3 | 2 | 66.7% | 80% | FAIL |
| PKG_CONTESTED_MOTION | 2 | 0 | 0.0% | 80% | FAIL |
| PKG_CONTESTED_NOTE_OF_ISSUE | 2 | 0 | 0.0% | 80% | FAIL |
| PKG_CONTESTED_RESPONSE | 1 | 1 | 100.0% | 80% | PASS |
| PKG_CONTESTED_RJI | 2 | 0 | 0.0% | 80% | FAIL |
| PKG_CUSTODY_HEARING | 0 | 0 | 100.0% | 80% | PASS |
| PKG_CUSTODY_ORDER | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_CUSTODY_PETITION (critical) | 3 | 1 | 33.3% | 90% | FAIL |
| PKG_CUSTODY_SERVICE | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_DEFAULT_DIVORCE | 5 | 3 | 60.0% | 80% | FAIL |
| PKG_DISCOVERY | 3 | 2 | 66.7% | 80% | FAIL |
| PKG_ENFORCEMENT | 1 | 1 | 100.0% | 80% | PASS |
| PKG_ENFORCEMENT_HEARING | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_ENFORCEMENT_ORDER | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_ENFORCEMENT_PETITION | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_JUDGMENT | 3 | 3 | 100.0% | 80% | PASS |
| PKG_MODIFICATION | 1 | 1 | 100.0% | 80% | PASS |
| PKG_MODIFICATION_HEARING | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_MODIFICATION_ORDER | 2 | 1 | 50.0% | 80% | FAIL |
| PKG_MODIFICATION_PETITION | 1 | 1 | 100.0% | 80% | PASS |
| PKG_MOTION | 14 | 12 | 85.7% | 80% | PASS |
| PKG_OP_FINAL_ORDER | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_OP_HEARING | 1 | 0 | 0.0% | 80% | FAIL |
| PKG_OP_PETITION | 2 | 0 | 0.0% | 80% | FAIL |
| PKG_ORDER_OF_PROTECTION (critical) | 1 | 1 | 100.0% | 90% | PASS |
| PKG_RESPONSE | 1 | 1 | 100.0% | 80% | PASS |
| PKG_SERVICE | 1 | 1 | 100.0% | 80% | PASS |
| PKG_SETTLEMENT | 1 | 1 | 100.0% | 80% | PASS |
| PKG_TRIAL | 1 | 1 | 100.0% | 80% | PASS |
| PKG_UNCONTESTED_COMMENCEMENT | 4 | 2 | 50.0% | 80% | FAIL |
| PKG_UNCONTESTED_JUDGMENT | 5 | 2 | 40.0% | 80% | FAIL |
| PKG_UNCONTESTED_NO_CHILDREN (critical) | 2 | 2 | 100.0% | 90% | PASS |
| PKG_UNCONTESTED_SERVICE | 2 | 1 | 50.0% | 80% | FAIL |
| PKG_UNCONTESTED_WITH_CHILDREN (critical) | 7 | 7 | 100.0% | 90% | PASS |

- Packages at/above threshold: 14/39
- Critical packages at/above threshold: 3/6

## Readiness Score

| Dimension | Score |
|-----------|------:|
| Structural integrity | 100% |
| Package threshold pass rate | 35.9% |
| Critical package pass rate | 50.0% |
| Form resolution rate | 81.8% |

### Soft Warnings

- 3 empty workflow(s): DEFAULT_DIVORCE, EMERGENCY_RELIEF, VISITATION
- 69 package_form row(s) with unresolved form_id (no prose_form record)
- Coverage PKG_CHILD_SUPPORT_HEARING: 0.0% (threshold 80%)
- Coverage PKG_CHILD_SUPPORT_ORDER: 0.0% (threshold 80%)
- Coverage PKG_CHILD_SUPPORT_PETITION: 33.3% (threshold 90%)
- Coverage PKG_CONTESTED_COMMENCEMENT: 66.7% (threshold 90%)
- Coverage PKG_CONTESTED_DISCOVERY: 0.0% (threshold 80%)
- Coverage PKG_CONTESTED_JUDGMENT: 66.7% (threshold 80%)
- Coverage PKG_CONTESTED_MOTION: 0.0% (threshold 80%)
- Coverage PKG_CONTESTED_NOTE_OF_ISSUE: 0.0% (threshold 80%)
- Coverage PKG_CONTESTED_RJI: 0.0% (threshold 80%)
- Coverage PKG_CUSTODY_ORDER: 0.0% (threshold 80%)
- Coverage PKG_CUSTODY_PETITION: 33.3% (threshold 90%)
- Coverage PKG_CUSTODY_SERVICE: 0.0% (threshold 80%)
- Coverage PKG_DEFAULT_DIVORCE: 60.0% (threshold 80%)
- Coverage PKG_DISCOVERY: 66.7% (threshold 80%)
- Coverage PKG_ENFORCEMENT_HEARING: 0.0% (threshold 80%)
- Coverage PKG_ENFORCEMENT_ORDER: 0.0% (threshold 80%)
- Coverage PKG_ENFORCEMENT_PETITION: 0.0% (threshold 80%)
- Coverage PKG_MODIFICATION_HEARING: 0.0% (threshold 80%)
- Coverage PKG_MODIFICATION_ORDER: 50.0% (threshold 80%)
- Coverage PKG_OP_FINAL_ORDER: 0.0% (threshold 80%)
- Coverage PKG_OP_HEARING: 0.0% (threshold 80%)
- Coverage PKG_OP_PETITION: 0.0% (threshold 80%)
- Coverage PKG_UNCONTESTED_COMMENCEMENT: 50.0% (threshold 80%)
- Coverage PKG_UNCONTESTED_JUDGMENT: 40.0% (threshold 80%)
- Coverage PKG_UNCONTESTED_SERVICE: 50.0% (threshold 80%)

---

## Final Result: PASS

No structural (hard) failures detected. Catalog is referentially consistent.
Soft warnings are non-blocking (unresolved optional/assembled forms or coverage notes).
