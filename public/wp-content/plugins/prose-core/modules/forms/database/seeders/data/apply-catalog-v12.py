#!/usr/bin/env python3
"""Apply catalog-remediation.md changes: nyc-package-catalog.json v1.1.0 -> v1.2.0."""

from __future__ import annotations

import copy
import json
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent
CATALOG_PATH = DATA_DIR / "nyc-package-catalog.json"

COMMON_FAMILY_COURT_FORMS = [
    {"form_code": "GF-2a", "title": "Affirmation", "function": "Generic affirmation"},
    {"form_code": "GF-4", "title": "Subpoena Duces Tecum", "function": "Discovery / evidence"},
    {"form_code": "GF-15", "title": "Order on Motion", "function": "Motion disposition"},
    {"form_code": "GF-16", "title": "Order - Dismissal", "function": "Case disposition"},
    {"form_code": "GF-24", "title": "Order to Sheriff to Return Respondent", "function": "Enforcement / appearance"},
    {"form_code": "GF-28", "title": "Order - Transfer of Proceedings or Probation Supervision", "function": "Venue/transfer"},
    {"form_code": "GF-29", "title": "Notice of Appearance", "function": "Appearance"},
    {"form_code": "GF-33", "title": "Order Authorizing Services Other Than Counsel", "function": "Assigned services"},
    {"form_code": "GF-50", "title": "Application for Assignment of Counsel", "function": "Counsel assignment"},
    {"form_code": "GF-51", "title": "Application for Reconsideration of Denial of Assignment of Counsel", "function": "Counsel assignment"},
    {"form_code": "GF-13", "title": "Order Directing Medical Examination (Outpatient)", "function": "Medical evaluation", "context": "custody_op"},
    {"form_code": "GF-13a", "title": "Order Directing Medical Examination (Inpatient)", "function": "Medical evaluation", "context": "custody_op"},
    {"form_code": "GF-13b", "title": "Order Directing Emergency Evaluation", "function": "Medical evaluation", "context": "custody_op"},
]

GENERATED_FORMS = {
    "CERTIFICATE_OF_READINESS",
    "TRIAL_WITNESS_LIST",
    "EXHIBIT_LIST",
}

PACKAGE_ENRICHMENTS: dict[str, dict[str, list[str]]] = {
    "PKG_CHILD_SUPPORT_PETITION": {
        "optional_forms": ["4-3c"],
        "supporting_documents": [
            "4-7b", "4-7c", "4-7d", "4-1b", "UIFSA-5", "UIFSA-7", "UIFSA-8",
            "LDSS-CS-SVC", "4-SM-2", "UIFSA-5a", "UIFSA-14", "UIFSA-15",
        ],
        "shared_form_sets": ["COMMON_FAMILY_COURT_FORMS"],
    },
    "PKG_ENFORCEMENT": {
        "optional_forms": ["4-13"],
        "supporting_documents": [
            "4-13a", "4-21a", "4-22", "UIFSA-9", "UIFSA-11", "4-15a",
            "4-5a", "UIFSA-12", "UIFSA-13",
        ],
        "shared_form_sets": ["COMMON_FAMILY_COURT_FORMS"],
    },
    "PKG_JUDGMENT": {
        "supporting_documents": ["4-23"],
    },
    "PKG_ORDER_OF_PROTECTION": {
        "supporting_documents": ["8-3"],
        "shared_form_sets": ["COMMON_FAMILY_COURT_FORMS"],
    },
    "PKG_CUSTODY_PETITION": {
        "supporting_documents": ["ICPC-100A"],
        "shared_form_sets": ["COMMON_FAMILY_COURT_FORMS"],
    },
    "PKG_MODIFICATION": {
        "shared_form_sets": ["COMMON_FAMILY_COURT_FORMS"],
    },
    "PKG_UNCONTESTED_NO_CHILDREN": {
        "supporting_documents": ["UD-COMPOSITE"],
    },
    "PKG_UNCONTESTED_WITH_CHILDREN": {
        "supporting_documents": ["DRL-NOTICE-MAINT"],
    },
}


def extend_unique(existing: list, additions: list[str]) -> list:
    seen = set(existing)
    out = list(existing)
    for item in additions:
        if item not in seen:
            out.append(item)
            seen.add(item)
    return out


def apply() -> dict:
    with open(CATALOG_PATH, encoding="utf-8") as f:
        catalog = json.load(f)

    catalog = copy.deepcopy(catalog)
    catalog["catalog_version"] = "1.2.0"
    catalog["effective_from"] = "2026-06-11"

    catalog["common_form_sets"] = {
        "COMMON_FAMILY_COURT_FORMS": COMMON_FAMILY_COURT_FORMS,
    }

    if "validation" not in catalog:
        catalog["validation"] = {}
    catalog["validation"]["form_class_note"] = (
        "form_class: generated excludes forms from import-backed coverage math."
    )

    by_key = {p["package_key"]: p for p in catalog["packages"]}

    for pkg_key, changes in PACKAGE_ENRICHMENTS.items():
        pkg = by_key.get(pkg_key)
        if not pkg:
            continue
        if "optional_forms" in changes:
            pkg["optional_forms"] = extend_unique(
                pkg.get("optional_forms", []), changes["optional_forms"]
            )
        if "supporting_documents" in changes:
            pkg["supporting_documents"] = extend_unique(
                pkg.get("supporting_documents", []), changes["supporting_documents"]
            )
        if "shared_form_sets" in changes:
            pkg["shared_form_sets"] = changes["shared_form_sets"]

    trial = by_key.get("PKG_TRIAL")
    if trial:
        trial["form_metadata"] = trial.get("form_metadata", {})
        for code in GENERATED_FORMS:
            trial["form_metadata"][code] = {"form_class": "generated"}

    with open(CATALOG_PATH, "w", encoding="utf-8") as f:
        json.dump(catalog, f, indent=2, ensure_ascii=False)
        f.write("\n")

    return catalog


if __name__ == "__main__":
    result = apply()
    print(f"Updated {CATALOG_PATH} to catalog_version {result['catalog_version']}")
    print(f"Packages: {len(result['packages'])}")
