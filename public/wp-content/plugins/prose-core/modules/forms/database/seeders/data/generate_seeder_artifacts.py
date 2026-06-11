#!/usr/bin/env python3
"""Emit CourtFlow JSON seeder artifacts from nyc-package-catalog.json v1.2.0."""

from __future__ import annotations

import csv
import json
import re
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent
CATALOG_PATH = DATA_DIR / "nyc-package-catalog.json"
CSV_PATH = DATA_DIR / "form-package-mapping.csv"

WORKFLOW_SEEDER_OUT = DATA_DIR / "workflow-seeder.json"
NODE_SEEDER_OUT = DATA_DIR / "node-seeder.json"
PACKAGE_SEEDER_OUT = DATA_DIR / "package-seeder.json"
FORM_PACKAGE_SEEDER_OUT = DATA_DIR / "form-package-seeder.json"
ALIAS_REGISTRY_OUT = DATA_DIR / "alias-registry.json"

WORKFLOWS = [
    {"workflow_key": "UNCONTESTED_DIVORCE", "workflow_name": "Uncontested Divorce", "court_routing": "SUPREME_COURT", "sort_order": 10},
    {"workflow_key": "CONTESTED_DIVORCE", "workflow_name": "Contested Divorce", "court_routing": "SUPREME_COURT", "sort_order": 20},
    {"workflow_key": "DEFAULT_DIVORCE", "workflow_name": "Default Divorce", "court_routing": "SUPREME_COURT", "sort_order": 30},
    {"workflow_key": "DISCOVERY", "workflow_name": "Discovery", "court_routing": "SUPREME_COURT", "sort_order": 100},
    {"workflow_key": "MOTION_PRACTICE", "workflow_name": "Motion Practice", "court_routing": "SUPREME_COURT", "sort_order": 110},
    {"workflow_key": "EMERGENCY_RELIEF", "workflow_name": "Emergency Relief", "court_routing": "SUPREME_AND_FAMILY_OVERLAP", "sort_order": 120},
    {"workflow_key": "CUSTODY", "workflow_name": "Custody", "court_routing": "FAMILY_COURT", "sort_order": 40},
    {"workflow_key": "VISITATION", "workflow_name": "Visitation", "court_routing": "FAMILY_COURT", "sort_order": 50},
    {"workflow_key": "CHILD_SUPPORT", "workflow_name": "Child Support", "court_routing": "FAMILY_COURT", "sort_order": 60},
    {"workflow_key": "ORDER_OF_PROTECTION", "workflow_name": "Order of Protection", "court_routing": "FAMILY_COURT", "sort_order": 70},
    {"workflow_key": "ENFORCEMENT", "workflow_name": "Enforcement", "court_routing": "FAMILY_COURT", "sort_order": 80},
    {"workflow_key": "MODIFICATION", "workflow_name": "Modification", "court_routing": "FAMILY_COURT", "sort_order": 90},
]

NODES = [
    {"node_key": "NODE_1001_DIVORCE_FILED", "workflow_key": "UNCONTESTED_DIVORCE", "stage": "COMMENCEMENT", "court_routing": "SUPREME_COURT", "node_type": "filing", "label": "Divorce Commenced", "is_entry": True, "trigger_events": ["ACTION_COMMENCED"]},
    {"node_key": "NODE_1002_SERVICE_COMPLETE", "workflow_key": "UNCONTESTED_DIVORCE", "stage": "SERVICE", "court_routing": "SUPREME_COURT", "node_type": "service", "label": "Service Complete", "completion_events": ["SERVICE_COMPLETE"]},
    {"node_key": "NODE_1003_ANSWER_FILED", "workflow_key": "CONTESTED_DIVORCE", "stage": "RESPONSE", "court_routing": "SUPREME_COURT", "node_type": "response", "label": "Answer Filed", "completion_events": ["ANSWER_FILED"]},
    {"node_key": "NODE_1004_OSC_FILED", "workflow_key": "MOTION_PRACTICE", "stage": "TEMPORARY_RELIEF", "court_routing": "SUPREME_COURT", "node_type": "motion", "label": "Order to Show Cause Filed"},
    {"node_key": "NODE_1005_PRELIMINARY_CONFERENCE", "workflow_key": "CONTESTED_DIVORCE", "stage": "PRELIMINARY_CONFERENCE", "court_routing": "SUPREME_COURT", "node_type": "conference", "label": "Preliminary Conference", "completion_events": ["PRELIM_CONFERENCE_HELD"]},
    {"node_key": "NODE_1006_DISCOVERY", "workflow_key": "DISCOVERY", "stage": "DISCOVERY", "court_routing": "SUPREME_COURT", "node_type": "discovery", "label": "Discovery", "completion_events": ["DISCOVERY_COMPLETE"]},
    {"node_key": "NODE_1007_COMPLIANCE_CONFERENCE", "workflow_key": "DISCOVERY", "stage": "COMPLIANCE_CONFERENCE", "court_routing": "SUPREME_COURT", "node_type": "conference", "label": "Compliance Conference"},
    {"node_key": "NODE_1008_SETTLEMENT", "workflow_key": "CONTESTED_DIVORCE", "stage": "SETTLEMENT", "court_routing": "SUPREME_COURT", "node_type": "settlement", "label": "Settlement", "completion_events": ["SETTLEMENT_REACHED"]},
    {"node_key": "NODE_1009_TRIAL", "workflow_key": "CONTESTED_DIVORCE", "stage": "TRIAL", "court_routing": "SUPREME_COURT", "node_type": "trial", "label": "Trial"},
    {"node_key": "NODE_1010_JUDGMENT", "workflow_key": "UNCONTESTED_DIVORCE", "stage": "JUDGMENT", "court_routing": "SUPREME_COURT", "node_type": "judgment", "label": "Judgment Entered", "is_terminal": True, "completion_events": ["JUDGMENT_ENTERED"]},
    {"node_key": "NODE_2001_CUSTODY_PETITION", "workflow_key": "CUSTODY", "stage": "PETITION", "court_routing": "FAMILY_COURT", "node_type": "petition", "label": "Custody Petition Filed", "is_entry": True, "trigger_events": ["PETITION_FILED"]},
    {"node_key": "NODE_2002_CUSTODY_HEARING", "workflow_key": "CUSTODY", "stage": "HEARING", "court_routing": "FAMILY_COURT", "node_type": "hearing", "label": "Custody Hearing", "completion_events": ["HEARING_HELD"]},
    {"node_key": "NODE_2003_CUSTODY_ORDER", "workflow_key": "CUSTODY", "stage": "ORDER", "court_routing": "FAMILY_COURT", "node_type": "order", "label": "Custody Order", "is_terminal": True, "completion_events": ["ORDER_ENTERED"]},
    {"node_key": "NODE_3001_SUPPORT_PETITION", "workflow_key": "CHILD_SUPPORT", "stage": "PETITION", "court_routing": "FAMILY_COURT", "node_type": "petition", "label": "Support Petition Filed", "is_entry": True, "trigger_events": ["PETITION_FILED"]},
    {"node_key": "NODE_3002_SUPPORT_ORDER", "workflow_key": "CHILD_SUPPORT", "stage": "ORDER", "court_routing": "FAMILY_COURT", "node_type": "order", "label": "Support Order", "is_terminal": True, "completion_events": ["ORDER_ENTERED"]},
    {"node_key": "NODE_4001_FAMILY_OFFENSE", "workflow_key": "ORDER_OF_PROTECTION", "stage": "PETITION", "court_routing": "FAMILY_COURT", "node_type": "petition", "label": "Family Offense Petition", "is_entry": True},
    {"node_key": "NODE_4002_TEMP_OP", "workflow_key": "ORDER_OF_PROTECTION", "stage": "TEMPORARY_ORDER", "court_routing": "FAMILY_COURT", "node_type": "order", "label": "Temporary Order of Protection", "completion_events": ["TEMP_ORDER_ISSUED"]},
    {"node_key": "NODE_4003_FINAL_OP", "workflow_key": "ORDER_OF_PROTECTION", "stage": "FINAL_ORDER", "court_routing": "FAMILY_COURT", "node_type": "order", "label": "Final Order of Protection", "is_terminal": True, "completion_events": ["ORDER_ENTERED"]},
    {"node_key": "NODE_5001_ENFORCEMENT_FILED", "workflow_key": "ENFORCEMENT", "stage": "VIOLATION", "court_routing": "FAMILY_COURT", "node_type": "petition", "label": "Violation Petition Filed", "is_entry": True, "trigger_events": ["VIOLATION_FILED"]},
    {"node_key": "NODE_5002_ENFORCEMENT_ORDER", "workflow_key": "ENFORCEMENT", "stage": "ENFORCEMENT", "court_routing": "FAMILY_COURT", "node_type": "order", "label": "Enforcement Order", "is_terminal": True, "completion_events": ["ORDER_ENTERED"]},
    {"node_key": "NODE_6001_MODIFICATION_FILED", "workflow_key": "MODIFICATION", "stage": "MODIFICATION", "court_routing": "FAMILY_COURT", "node_type": "petition", "label": "Modification Petition Filed", "is_entry": True, "trigger_events": ["PETITION_FILED"]},
    {"node_key": "NODE_6002_MODIFICATION_ORDER", "workflow_key": "MODIFICATION", "stage": "ORDER", "court_routing": "FAMILY_COURT", "node_type": "order", "label": "Modified Order", "is_terminal": True, "completion_events": ["ORDER_ENTERED"]},
]

NODE_NEXT = {
    "NODE_1001_DIVORCE_FILED": ["NODE_1002_SERVICE_COMPLETE"],
    "NODE_1002_SERVICE_COMPLETE": ["NODE_1003_ANSWER_FILED"],
    "NODE_1003_ANSWER_FILED": ["NODE_1005_PRELIMINARY_CONFERENCE"],
    "NODE_1004_OSC_FILED": ["NODE_1005_PRELIMINARY_CONFERENCE"],
    "NODE_1005_PRELIMINARY_CONFERENCE": ["NODE_1006_DISCOVERY"],
    "NODE_1006_DISCOVERY": ["NODE_1007_COMPLIANCE_CONFERENCE"],
    "NODE_1007_COMPLIANCE_CONFERENCE": ["NODE_1008_SETTLEMENT", "NODE_1009_TRIAL"],
    "NODE_1008_SETTLEMENT": ["NODE_1010_JUDGMENT"],
    "NODE_1009_TRIAL": ["NODE_1010_JUDGMENT"],
    "NODE_2001_CUSTODY_PETITION": ["NODE_2002_CUSTODY_HEARING"],
    "NODE_2002_CUSTODY_HEARING": ["NODE_2003_CUSTODY_ORDER"],
    "NODE_3001_SUPPORT_PETITION": ["NODE_3002_SUPPORT_ORDER"],
    "NODE_4001_FAMILY_OFFENSE": ["NODE_4002_TEMP_OP"],
    "NODE_4002_TEMP_OP": ["NODE_4003_FINAL_OP"],
    "NODE_5001_ENFORCEMENT_FILED": ["NODE_5002_ENFORCEMENT_ORDER"],
    "NODE_6001_MODIFICATION_FILED": ["NODE_6002_MODIFICATION_ORDER"],
}

ALIAS_REGISTRY = {
    "registry_version": "1.2.0",
    "aliases": [
        {"canonical_code": "UCCJEA-7", "alias_codes": ["4-24", "5-16", "UIFSA-10"], "scope": "in_scope", "note": "Electronic Testimony Application"},
        {"canonical_code": "UD-COMPOSITE", "alias_codes": ["UNCODED-103"], "scope": "in_scope", "note": "Composite Uncontested Divorce Forms"},
        {"canonical_code": "DRL-NOTICE-MAINT", "alias_codes": ["UNCODED-167"], "scope": "in_scope", "note": "Notice of Guideline Maintenance"},
        {"canonical_code": "LDSS-CS-SVC", "alias_codes": ["UNCODED-447"], "scope": "in_scope", "note": "Short Form Application for Child Support Services"},
        {"canonical_code": "10-9", "alias_codes": ["7-5"], "scope": "out_of_scope", "note": "Determination Upon Fact-finding Hearing"},
        {"canonical_code": "GF-15", "alias_codes": ["3-44"], "scope": "mixed", "note": "Order on Motion"},
        {"canonical_code": "10-1b", "alias_codes": ["6"], "scope": "out_of_scope", "note": "Order of Investigation"},
        {"canonical_code": "PH-4a", "alias_codes": ["PH-4-b", "PH-4c"], "scope": "out_of_scope", "note": "Statement to Court of Permanency Hearing Reports"},
        {"canonical_code": "7-18", "alias_codes": ["3-38"], "scope": "out_of_scope", "note": "Petition Extension of Placement"},
    ],
    "deprecated": ["FC-1", "FC-2", "FC-3", "FC-7", "UD-4a"],
    "variant_sets": [
        {"codes": ["GF-5b", "GF-5c (CRIM-4)"], "reason": "Distinct TOP affirmation variants — do not merge"},
        {"codes": ["3-32", "3-34"], "reason": "Distinct felony disposition variants — do not merge"},
    ],
}


def build_edges() -> list[dict]:
    edges = []
    seq = 0
    wf_by_node = {n["node_key"]: n["workflow_key"] for n in NODES}
    for from_node, to_nodes in NODE_NEXT.items():
        for to_node in to_nodes:
            seq += 1
            edges.append({
                "from_node": from_node,
                "to_node": to_node,
                "workflow_key": wf_by_node.get(from_node, ""),
                "edge_type": "next",
                "condition_key": "",
                "condition_data": None,
                "label": "",
                "sequence": seq,
                "weight": 0,
            })
    return edges


def attach_primary_packages(catalog: dict) -> list[dict]:
    node_map = catalog.get("node_map", {})
    pkg_by_node = {v: k for k, v in node_map.items()}
    nodes = []
    for i, node in enumerate(NODES, start=1):
        n = dict(node)
        n["sequence"] = i
        n["responsible_party"] = ""
        n["primary_package_key"] = pkg_by_node.get(node["node_key"], "")
        nodes.append(n)
    return nodes


def build_relations(catalog: dict) -> list[dict]:
    relations = []
    seq = 0
    for pkg in catalog.get("packages", []):
        from_key = pkg["package_key"]
        for to_key in pkg.get("next_packages", []):
            seq += 1
            relations.append({
                "from_package_key": from_key,
                "to_package_key": to_key,
                "relation_type": "next",
                "condition_key": "",
                "sequence": seq,
            })
        for to_key in pkg.get("prerequisite_packages", []):
            seq += 1
            relations.append({
                "from_package_key": to_key,
                "to_package_key": from_key,
                "relation_type": "prerequisite",
                "condition_key": "",
                "sequence": seq,
            })
    return relations


def form_class_for(pkg: dict, code: str) -> str:
    meta = pkg.get("form_metadata", {})
    if code in meta and meta[code].get("form_class") == "generated":
        return "generated"
    return "import_backed"


def mappings_from_csv(catalog: dict) -> list[dict]:
    if not CSV_PATH.exists():
        return mappings_from_catalog(catalog)

    pkg_keys = {p["package_key"] for p in catalog.get("packages", [])}
    pkg_by_key = {p["package_key"]: p for p in catalog.get("packages", [])}
    mappings = []
    seq_by_pkg: dict[str, int] = {}

    with open(CSV_PATH, newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f):
            pkg = row.get("package_key", "").strip()
            if not pkg or pkg not in pkg_keys:
                continue
            if row.get("status", "").startswith("ORPHAN"):
                continue
            rel = row.get("relationship_type", "OPTIONAL").upper()
            requirement = "required" if rel == "REQUIRED" else "optional"
            if rel == "SUPPORTING":
                requirement = "supporting"
            seq_by_pkg[pkg] = seq_by_pkg.get(pkg, 0) + 1
            code = row["form_code"].strip()
            pkg_def = pkg_by_key.get(pkg, {})
            mappings.append({
                "package_key": pkg,
                "form_code": code,
                "requirement": requirement,
                "condition_key": "",
                "sequence": seq_by_pkg[pkg],
                "mapping_source": row.get("mapping_source") or "CATALOG",
                "confidence_score": float(row.get("confidence_score") or 1.0),
                "form_class": form_class_for(pkg_def, code),
            })
    return mappings


def mappings_from_catalog(catalog: dict) -> list[dict]:
    mappings = []
    for pkg in catalog.get("packages", []):
        pkg_key = pkg["package_key"]
        seq = 0
        for field, requirement in (
            ("required_forms", "required"),
            ("optional_forms", "optional"),
            ("supporting_documents", "supporting"),
        ):
            for code in pkg.get(field, []):
                seq += 1
                mappings.append({
                    "package_key": pkg_key,
                    "form_code": code,
                    "requirement": requirement,
                    "condition_key": "",
                    "sequence": seq,
                    "mapping_source": "CATALOG",
                    "confidence_score": 1.0,
                    "form_class": form_class_for(pkg, code),
                })
        for set_key in pkg.get("shared_form_sets", []):
            for entry in catalog.get("common_form_sets", {}).get(set_key, []):
                code = entry["form_code"]
                seq += 1
                mappings.append({
                    "package_key": pkg_key,
                    "form_code": code,
                    "requirement": "optional",
                    "condition_key": "",
                    "sequence": seq,
                    "mapping_source": "SHARED_SET",
                    "confidence_score": 0.55,
                    "form_class": "import_backed",
                })
    return mappings


def emit_all(catalog: dict | None = None) -> dict[str, Path]:
    if catalog is None:
        with open(CATALOG_PATH, encoding="utf-8") as f:
            catalog = json.load(f)

    version = catalog.get("catalog_version", "1.2.0")

    workflow_artifact = {
        "catalog_version": version,
        "jurisdiction": catalog.get("jurisdiction", "NYC"),
        "workflows": [
            {
                **wf,
                "description": f"NYC {wf['workflow_name']} workflow.",
                "is_active": True,
            }
            for wf in WORKFLOWS
        ],
    }

    node_artifact = {
        "catalog_version": version,
        "nodes": attach_primary_packages(catalog),
        "edges": build_edges(),
    }

    package_artifact = {
        "catalog_version": version,
        "packages": [
            {
                "package_key": p["package_key"],
                "package_name": p["package_name"],
                "workflow_key": p["workflow_key"],
                "primary_node": p["primary_node"],
                "court_routing": p["court_routing"],
                "package_order": p.get("package_order", 0),
                "package_version": p.get("package_version", 1),
                "effective_from": p.get("effective_from", ""),
                "effective_to": p.get("effective_to", ""),
                "is_active": p.get("is_active", True),
                "trigger_conditions": p.get("trigger_conditions", {}),
                "completion_conditions": p.get("completion_conditions", {}),
                "service_required": p.get("service_required", True),
                "filing_required": p.get("filing_required", True),
                "deadline_rules": p.get("deadline_rules", []),
                "shared_form_sets": p.get("shared_form_sets", []),
                "required_forms": p.get("required_forms", []),
                "optional_forms": p.get("optional_forms", []),
                "supporting_documents": p.get("supporting_documents", []),
                "form_metadata": p.get("form_metadata", {}),
                "ai_summary": p.get("ai_summary", ""),
            }
            for p in catalog.get("packages", [])
        ],
        "relations": build_relations(catalog),
    }

    form_artifact = {
        "catalog_version": version,
        "mappings": mappings_from_csv(catalog),
    }

    alias_artifact = dict(ALIAS_REGISTRY)
    alias_artifact["registry_version"] = version

    outputs = {
        "workflow": WORKFLOW_SEEDER_OUT,
        "node": NODE_SEEDER_OUT,
        "package": PACKAGE_SEEDER_OUT,
        "form_package": FORM_PACKAGE_SEEDER_OUT,
        "alias": ALIAS_REGISTRY_OUT,
    }

    artifacts = {
        WORKFLOW_SEEDER_OUT: workflow_artifact,
        NODE_SEEDER_OUT: node_artifact,
        PACKAGE_SEEDER_OUT: package_artifact,
        FORM_PACKAGE_SEEDER_OUT: form_artifact,
        ALIAS_REGISTRY_OUT: alias_artifact,
    }

    for path, data in artifacts.items():
        with open(path, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
            f.write("\n")

    return outputs


if __name__ == "__main__":
    paths = emit_all()
    for name, path in paths.items():
        print(f"Wrote {path}")
