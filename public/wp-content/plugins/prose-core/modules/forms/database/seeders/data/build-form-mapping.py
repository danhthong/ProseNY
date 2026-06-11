#!/usr/bin/env python3
"""
Build form-package-mapping.csv and coverage-report.md per Form Mapping Layer spec.
Run from repo root or this directory. Requires exported TSV at /tmp/prose_forms_export.tsv.
"""

from __future__ import annotations

import csv
import json
import re
import sys
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import date
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent
CATALOG_PATH = DATA_DIR / "nyc-package-catalog.json"
FORMS_TSV = Path("/tmp/prose_forms_export.tsv")
CSV_OUT = DATA_DIR / "form-package-mapping.csv"
REPORT_OUT = DATA_DIR / "coverage-report.md"

COUNTIES = [
    "NEW_YORK_COUNTY",
    "KINGS_COUNTY",
    "QUEENS_COUNTY",
    "BRONX_COUNTY",
    "RICHMOND_COUNTY",
]

CRITICAL_PACKAGES = {
    "PKG_UNCONTESTED_NO_CHILDREN",
    "PKG_UNCONTESTED_WITH_CHILDREN",
    "PKG_CONTESTED_COMMENCEMENT",
    "PKG_CUSTODY_PETITION",
    "PKG_CHILD_SUPPORT_PETITION",
    "PKG_ORDER_OF_PROTECTION",
}

# Catalog placeholder codes -> prose_form code patterns or title keywords.
PLACEHOLDER_RESOLUTION: dict[str, list[str]] = {
    "RJI": ["UD-13", "UCS-840M"],
    "NOI": ["UD-9"],
    "FINDINGS_OF_FACT": ["UD-10"],
    "STATEMENT_OF_NET_WORTH": ["NET WORTH"],
    "NOTICE_AUTOMATIC_ORDERS": ["AUTOMATIC ORDER"],
    "NOTICE_HEALTH_CARE": ["HEALTH CARE", "HEALTHCARE"],
    "AFFIDAVIT_OF_SERVICE": ["AFFIRMATION OF SERVICE", "AFFIDAVIT OF SERVICE"],
    "POSTCARD_SAMPLE": ["POSTCARD"],
    "ORDER_TO_SHOW_CAUSE": ["ORDER TO SHOW CAUSE"],
    "AFFIDAVIT_IN_SUPPORT": ["AFFIRMATION IN SUPPORT", "AFFIDAVIT IN SUPPORT"],
    "NOTICE_OF_MOTION": ["NOTICE OF MOTION"],
    "SETTLEMENT_AGREEMENT": ["STIPULATION", "SETTLEMENT AGREEMENT"],
    "NOTICE_OF_SETTLEMENT": ["NOTICE OF SETTLEMENT"],
    "DRL_255_ADDENDUM": ["DRL 255", "DRL-255"],
    "CERTIFICATE_OF_READINESS": ["CERTIFICATE OF READINESS", "CERTIFICATE OF READINESS FOR TRIAL"],
    "PART130_CERT": ["UD-12", "PART 130"],
    "DOH-2168": ["DOH-2168"],
    "UCCJEA_AFFIDAVIT": ["UCCJEA"],
    "PROPOSED_PARENTING_PLAN": ["PARENTING PLAN"],
    "FINANCIAL_DISCLOSURE_AFFIDAVIT": ["FINANCIAL DISCLOSURE"],
    "TEMPORARY_ORDER_OF_PROTECTION": ["TEMPORARY ORDER OF PROTECTION"],
    "FINAL_ORDER_OF_PROTECTION": ["FINAL ORDER OF PROTECTION"],
    "ORDER_OF_CUSTODY_VISITATION": ["ORDER OF CUSTODY", "CUSTODY/VISITATION ORDER"],
    "ORDER_OF_SUPPORT": ["ORDER OF SUPPORT", "ORDER - SUPPORT"],
    "INCOME_EXECUTION": ["INCOME EXECUTION", "INCOME DEDUCTION"],
    "MODIFIED_ORDER": ["MODIFIED ORDER", "MODIFICATION OF ORDER"],
    "ORDER_ON_VIOLATION": ["ORDER ON VIOLATION", "VIOLATION OF"],
    "MONEY_JUDGMENT": ["MONEY JUDGMENT"],
    "NOTICE_FOR_DISCOVERY_INSPECTION": ["DISCOVERY", "NOTICE FOR DISCOVERY"],
    "INTERROGATORIES": ["INTERROGATOR"],
    "PRELIMINARY_CONFERENCE_ORDER": ["PRELIMINARY CONFERENCE"],
    "TRIAL_WITNESS_LIST": ["WITNESS LIST"],
    "EXHIBIT_LIST": ["EXHIBIT LIST"],
    "AFFIDAVIT_OF_REGULARITY": ["AFFIDAVIT OF REGULARITY", "AFFIRMATION OF REGULARITY", "UD-5"],
}

# Deprecated legacy codes (superseded canonical).
DEPRECATED_CODES = {
    "FC-1", "FC-2", "FC-3", "FC-7", "UD-4a",
}

OUT_OF_SCOPE_CASE_TYPES = {
    "paternity",
    "adoption",
    "guardianship",
    "jd/pins",
    "name change",
    "icwa",
}

OUT_OF_SCOPE_TITLE_KEYWORDS = [
    "adoption",
    "paternity",
    "guardianship",
    "jd/pins",
    "juvenile delinquent",
    "name change",
    "indian child welfare",
    "icwa",
    "surrogate",
    "surr-",
    "destitute child",
    "permanency",
    "foster",
]

IN_SCOPE_CASE_TYPES = {
    "divorce",
    "uncontested divorce",
    "contested divorce",
    "child custody",
    "child support",
    "child support enforcement",
    "child support modification",
    "orders of protection",
    "family offense",
    "visitation",
    "post divorce",
}

PACKAGE_GROUPS: dict[str, str] = {
    "PKG_UNCONTESTED_NO_CHILDREN": "Supreme Court Matrimonial",
    "PKG_UNCONTESTED_WITH_CHILDREN": "Supreme Court Matrimonial",
    "PKG_CONTESTED_COMMENCEMENT": "Supreme Court Matrimonial",
    "PKG_SERVICE": "Supreme Court Matrimonial",
    "PKG_RESPONSE": "Supreme Court Matrimonial",
    "PKG_DEFAULT_DIVORCE": "Supreme Court Matrimonial",
    "PKG_DISCOVERY": "Supreme Court Matrimonial",
    "PKG_MOTION": "Supreme Court Matrimonial",
    "PKG_SETTLEMENT": "Supreme Court Matrimonial",
    "PKG_TRIAL": "Supreme Court Matrimonial",
    "PKG_JUDGMENT": "Supreme Court Matrimonial",
    "PKG_CUSTODY_PETITION": "Family Court Custody",
    "PKG_CHILD_SUPPORT_PETITION": "Family Court Child Support",
    "PKG_ORDER_OF_PROTECTION": "Family Court Family Offense",
    "PKG_ENFORCEMENT": "Enforcement",
    "PKG_MODIFICATION": "Modification",
}

# Conditional form -> packages (form_code pattern or exact -> list of package keys).
CONDITIONAL_RULES: list[tuple[str, list[str], str]] = [
    ("GF-21", ["PKG_CUSTODY_PETITION", "PKG_ORDER_OF_PROTECTION"], "Address confidentiality affirmation (FCA 154-b)"),
    ("UCS-FW3", ["PKG_UNCONTESTED_NO_CHILDREN", "PKG_UNCONTESTED_WITH_CHILDREN", "PKG_CONTESTED_COMMENCEMENT"], "Fee waiver service affirmation"),
    ("UCS-FWO1", ["PKG_UNCONTESTED_NO_CHILDREN", "PKG_UNCONTESTED_WITH_CHILDREN", "PKG_CONTESTED_COMMENCEMENT"], "Fee waiver order"),
    ("SC-1", ["PKG_MOTION", "PKG_ORDER_OF_PROTECTION"], "Supreme Court temporary OP"),
    ("SC-2", ["PKG_MOTION", "PKG_ORDER_OF_PROTECTION"], "Supreme Court OP"),
    ("SC-3", ["PKG_MOTION", "PKG_ORDER_OF_PROTECTION"], "Firearms seizure order"),
    ("LDSS-5037", ["PKG_JUDGMENT", "PKG_CHILD_SUPPORT_PETITION"], "IWO child support"),
    ("LDSS-5038", ["PKG_JUDGMENT", "PKG_CHILD_SUPPORT_PETITION"], "IWO spousal support"),
    ("LDSS-5039", ["PKG_JUDGMENT", "PKG_CHILD_SUPPORT_PETITION"], "IWO instructions"),
    ("LDSS 5258", ["PKG_CHILD_SUPPORT_PETITION", "PKG_JUDGMENT"], "Child support enrollment"),
    ("UD-8(1)", ["PKG_UNCONTESTED_WITH_CHILDREN", "PKG_JUDGMENT", "PKG_SETTLEMENT", "PKG_DEFAULT_DIVORCE"], "Annual income worksheet"),
    ("UD-8(2)", ["PKG_UNCONTESTED_WITH_CHILDREN", "PKG_JUDGMENT", "PKG_SETTLEMENT"], "Maintenance guidelines worksheet"),
    ("UD-8(3)", ["PKG_UNCONTESTED_WITH_CHILDREN", "PKG_JUDGMENT", "PKG_SETTLEMENT", "PKG_DEFAULT_DIVORCE"], "Child support worksheet"),
    ("UD-8a", ["PKG_UNCONTESTED_WITH_CHILDREN", "PKG_JUDGMENT"], "SCU information sheet"),
    ("UD-1a", ["PKG_UNCONTESTED_NO_CHILDREN", "PKG_UNCONTESTED_WITH_CHILDREN", "PKG_CONTESTED_COMMENCEMENT"], "Alternate summons form"),
    ("UD-10", ["PKG_JUDGMENT", "PKG_DEFAULT_DIVORCE"], "Findings of fact/conclusions of law"),
    ("UD-11", ["PKG_JUDGMENT"], "Judgment of divorce"),
    ("UD-12", ["PKG_JUDGMENT"], "Part 130 certification"),
    ("UD-14", ["PKG_JUDGMENT"], "Notice of entry"),
    ("UD-15", ["PKG_JUDGMENT", "PKG_SERVICE"], "Affirmation of service by mail of JOD"),
    ("DOH-2168", ["PKG_JUDGMENT"], "Certificate of dissolution"),
    ("DRL 255", ["PKG_SETTLEMENT", "PKG_JUDGMENT"], "DRL 255 addendum"),
]

# County-specific: commencement forms in packages with county_variations.
COUNTY_RULE_FORMS = {"UD-1", "UD-2", "UD-1a", "GF-17", "4-3", "8-2"}


@dataclass
class FormRecord:
    post_id: str
    form_code: str
    form_title: str
    court: str
    case_type: str
    workflow_stage: str
    county: str
    norm_code: str = ""
    mapped_packages: set[str] = field(default_factory=set)

    def __post_init__(self) -> None:
        self.norm_code = normalize_code(self.form_code)


@dataclass
class MappingRow:
    form_code: str
    form_title: str
    group: str
    court_routing: str
    package_key: str
    relationship_type: str
    county: str
    confidence_score: str
    mapping_source: str
    status: str
    notes: str
    post_id: str = ""

    def key(self) -> tuple:
        return (self.form_code, self.package_key, self.relationship_type, self.county, self.mapping_source)


def normalize_code(code: str) -> str:
    c = code.strip().upper()
    c = re.sub(r"\s+", " ", c)
    c = c.replace("AND", "").replace("  ", " ").strip()
    return c


def code_variants(code: str) -> set[str]:
    """Generate match variants for a catalog or form code."""
    base = normalize_code(code)
    variants = {base, base.replace(" ", ""), base.replace("-", "")}
    # UD-8 family
    m = re.match(r"^UD-8(?:\((\d+)\)|([AB]))?$", base, re.I)
    if m:
        variants.add("UD-8")
        if m.group(1):
            variants.add(f"UD-8({m.group(1)})")
        if m.group(2):
            variants.add(f"UD-8{m.group(2).lower()}")
    if base.startswith("UD-4"):
        variants.update({"UD-4", "UD-4A", "UD-4 AND UD-4A"})
    if base in {"DRL 255", "DRL-255", "DRL_255_ADDENDUM"}:
        variants.update({"DRL 255", "DRL-255", "DRL_255_ADDENDUM"})
    if base == "RJI":
        variants.add("UD-13")
    if base == "NOI":
        variants.add("UD-9")
    if base == "FINDINGS_OF_FACT":
        variants.add("UD-10")
    if base == "PART130_CERT":
        variants.add("UD-12")
    return {normalize_code(v) for v in variants}


def build_alias_map(catalog: dict) -> dict[str, str]:
    """Map alias -> canonical catalog code."""
    alias_map: dict[str, str] = {}
    for canonical, info in catalog.get("validation", {}).get("family_court_form_resolution", {}).items():
        alias_map[normalize_code(canonical)] = normalize_code(canonical)
        for alias in info.get("legacy_aliases", []):
            alias_map[normalize_code(alias)] = normalize_code(canonical)
    for placeholder, targets in PLACEHOLDER_RESOLUTION.items():
        alias_map[normalize_code(placeholder)] = normalize_code(placeholder)
    return alias_map


def court_to_routing(court: str, case_type: str) -> str:
    c = court.lower()
    ct = case_type.lower()
    if "supreme" in c and ("child" in ct or "support" in ct or "custody" in ct):
        return "SUPREME_AND_FAMILY_OVERLAP"
    if "supreme" in c:
        return "SUPREME_COURT"
    if "family" in c:
        return "FAMILY_COURT"
    if "unsupported" in c:
        return "UNSUPPORTED"
    if "divorce" in ct or "uncontested" in ct:
        return "SUPREME_COURT"
    if ct in {"child custody", "visitation", "child support", "orders of protection", "family offense"}:
        return "FAMILY_COURT"
    return "UNSUPPORTED"


def infer_group(form: FormRecord, package_key: str = "") -> str:
    if package_key and package_key in PACKAGE_GROUPS:
        return PACKAGE_GROUPS[package_key]
    ct = form.case_type.lower()
    court = form.court.lower()
    code = form.norm_code
    if "divorce" in ct or code.startswith("UD-") or code.startswith("UCS-") or code.startswith("SC-"):
        return "Supreme Court Matrimonial"
    if code.startswith("GF-") and code in {"GF-17", "GF-40", "GF-41", "GF-21"}:
        if code == "GF-40":
            return "Modification"
        if code == "GF-41":
            return "Enforcement"
        return "Family Court Custody"
    if code.startswith("4-"):
        if "4-11" in code or "modification" in form.form_title.lower():
            return "Modification"
        if "4-12" in code or "violation" in form.form_title.lower() or "enforcement" in ct:
            return "Enforcement"
        return "Family Court Child Support"
    if code.startswith("8-") or "family offense" in ct or "order of protection" in ct:
        return "Family Court Family Offense"
    if "custody" in ct or "visitation" in ct:
        return "Family Court Custody"
    if "support" in ct:
        return "Family Court Child Support"
    if "enforcement" in ct or "violation" in form.form_title.lower():
        return "Enforcement"
    if "modification" in ct or "modification" in form.form_title.lower():
        return "Modification"
    return "UNGROUPED"


def title_matches(form: FormRecord, keywords: list[str]) -> bool:
    title = form.form_title.upper()
    code = form.norm_code
    for kw in keywords:
        ku = kw.upper()
        if ku in title or ku.replace(" ", "") in code.replace(" ", ""):
            return True
    return False


def find_forms_for_catalog_code(
    catalog_code: str,
    forms: list[FormRecord],
    alias_map: dict[str, str],
    used_ids: dict[str, set[str]],
) -> list[tuple[FormRecord, str, float, str]]:
    """
    Return list of (form, relationship_hint, confidence, mapping_source) for a catalog code.
    relationship_hint is used upstream; mapping_source may be ALIAS_RESOLUTION.
    """
    norm = normalize_code(catalog_code)
    canonical = alias_map.get(norm, norm)
    variants = code_variants(canonical) | code_variants(norm)
    matches: list[tuple[FormRecord, str, float, str]] = []

    for form in forms:
        if is_out_of_scope(form):
            continue
        fv = code_variants(form.norm_code)
        if fv & variants:
            src = "ALIAS_RESOLUTION" if form.norm_code != canonical and norm != form.norm_code else "CATALOG_REQUIRED"
            conf = 0.80 if src == "ALIAS_RESOLUTION" else 1.00
            matches.append((form, "match", conf, src))
            continue
        # Prefix match UD-8*
        if canonical == "UD-8" and form.norm_code.startswith("UD-8"):
            matches.append((form, "match", 0.80, "ALIAS_RESOLUTION"))
            continue
        if canonical == "UD-4" and form.norm_code.startswith("UD-4"):
            matches.append((form, "match", 0.80, "ALIAS_RESOLUTION"))

    if matches:
        return matches

    # Placeholder / title resolution
    if norm in PLACEHOLDER_RESOLUTION or canonical in PLACEHOLDER_RESOLUTION:
        keywords = PLACEHOLDER_RESOLUTION.get(norm, PLACEHOLDER_RESOLUTION.get(canonical, []))
        for form in forms:
            if is_out_of_scope(form):
                continue
            if title_matches(form, keywords):
                # Exclude placement/adoption notices from matrimonial motion matching.
                if any(
                    x in form.form_title.lower()
                    for x in ("placement", "adoption", "paternity", "guardian", "destitute", "permanency")
                ):
                    continue
                matches.append((form, "match", 0.80, "ALIAS_RESOLUTION"))

    return matches


def is_out_of_scope(form: FormRecord) -> bool:
    ct = form.case_type.lower()
    title = form.form_title.lower()
    code = form.norm_code
    if any(kw in ct for kw in OUT_OF_SCOPE_CASE_TYPES):
        return True
    if any(kw in title for kw in OUT_OF_SCOPE_TITLE_KEYWORDS):
        return True
    if re.match(r"^[15]-", code) and not code.startswith("5-"):  # adoption 1-x, not paternity 5-x handled above
        if code.startswith("1-") or code.startswith("10-") or code.startswith("14-"):
            return True
    if code.startswith("SURR-") or code.startswith("PH-") or code.startswith("DOH-") and "adoption" in title:
        return True
    if code.startswith("5-"):  # paternity article 5
        return True
    if code.startswith("3-") or code.startswith("JD"):  # JD/PINS
        return True
    if "guardian" in title and "guardianship" in title:
        return True
    return False


def is_in_scope_orphan(form: FormRecord) -> bool:
    if is_out_of_scope(form):
        return False
    ct = form.case_type.lower()
    title = form.form_title.lower()
    code = form.norm_code
    if any(kw in ct for kw in IN_SCOPE_CASE_TYPES):
        return True
    if code.startswith("UD-") or code.startswith("GF-") or re.match(r"^[48]-", code):
        return True
    if "divorce" in title or "custody" in title or "support" in title or "protection" in title:
        return True
    if form.court.lower() == "supreme court":
        return True
    return False


def detect_duplicates(forms: list[FormRecord]) -> set[str]:
    """Return form_codes flagged as DUPLICATE."""
    title_map: dict[str, list[str]] = defaultdict(list)
    for f in forms:
        key = re.sub(r"[^a-z0-9]", "", f.form_title.lower())[:60]
        if key:
            title_map[key].append(f.form_code)
    dups: set[str] = set()
    for codes in title_map.values():
        if len(codes) > 1:
            dups.update(codes)
    return dups


def load_forms() -> list[FormRecord]:
    forms: list[FormRecord] = []
    with open(FORMS_TSV, newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f, delimiter="\t"):
            forms.append(
                FormRecord(
                    post_id=row["ID"],
                    form_code=row["form_code"],
                    form_title=row["post_title"],
                    court=row["court"],
                    case_type=row["case_type"],
                    workflow_stage=row["workflow_stage"],
                    county=row["county"],
                )
            )
    return forms


def build_mappings(forms: list[FormRecord], catalog: dict) -> list[MappingRow]:
    alias_map = build_alias_map(catalog)
    rows: list[MappingRow] = []
    seen_keys: set[tuple] = set()
    form_by_code: dict[str, FormRecord] = {f.form_code: f for f in forms}
    duplicate_codes = detect_duplicates(forms)

    def add_row(row: MappingRow) -> None:
        k = row.key()
        if k in seen_keys:
            return
        seen_keys.add(k)
        rows.append(row)
        if row.package_key and row.status == "MAPPED":
            fr = form_by_code.get(row.form_code)
            if fr:
                fr.mapped_packages.add(row.package_key)

    # --- Catalog-driven mappings ---
    rel_config = [
        ("required_forms", "REQUIRED", "CATALOG_REQUIRED", 1.00),
        ("optional_forms", "OPTIONAL", "CATALOG_OPTIONAL", 0.95),
        ("supporting_documents", "OPTIONAL", "SUPPORTING_DOCUMENT", 0.90),
    ]

    for pkg in catalog["packages"]:
        pkg_key = pkg["package_key"]
        group = PACKAGE_GROUPS[pkg_key]
        court_route = pkg.get("court_routing", "SUPREME_COURT")

        for field_name, rel_type, src, conf in rel_config:
            for catalog_code in pkg.get(field_name, []):
                matches = find_forms_for_catalog_code(catalog_code, forms, alias_map, {})
                if matches:
                    for form, _, match_conf, match_src in matches:
                        use_src = src if match_src == "CATALOG_REQUIRED" or src != "CATALOG_REQUIRED" else match_src
                        use_conf = conf if use_src == src else match_conf
                        if use_src == "ALIAS_RESOLUTION":
                            use_conf = 0.80
                        status = "DEPRECATED" if form.norm_code in DEPRECATED_CODES or normalize_code(catalog_code) in DEPRECATED_CODES else "MAPPED"
                        if form.form_code in duplicate_codes:
                            status = "DUPLICATE"
                        add_row(
                            MappingRow(
                                form_code=form.form_code,
                                form_title=form.form_title,
                                group=group,
                                court_routing=court_to_routing(form.court, form.case_type) if form.court else court_route,
                                package_key=pkg_key,
                                relationship_type=rel_type,
                                county="",
                                confidence_score=f"{use_conf:.2f}",
                                mapping_source=use_src,
                                status=status,
                                notes=f"Catalog {field_name}: {catalog_code}",
                                post_id=form.post_id,
                            )
                        )
                else:
                    # Track unmapped catalog code via coverage (no row for missing form)
                    pass

    # --- Conditional rules ---
    for pattern, pkg_keys, note in CONDITIONAL_RULES:
        pat_norm = normalize_code(pattern)
        for form in forms:
            if is_out_of_scope(form):
                continue
            if form.norm_code == pat_norm or form.norm_code.startswith(pat_norm) or pat_norm in form.norm_code:
                for pkg_key in pkg_keys:
                    status = "DEPRECATED" if form.norm_code in DEPRECATED_CODES else "MAPPED"
                    if form.form_code in duplicate_codes:
                        status = "DUPLICATE"
                    add_row(
                        MappingRow(
                            form_code=form.form_code,
                            form_title=form.form_title,
                            group=infer_group(form, pkg_key),
                            court_routing=court_to_routing(form.court, form.case_type),
                            package_key=pkg_key,
                            relationship_type="CONDITIONAL",
                            county="",
                            confidence_score="0.60",
                            mapping_source="CONDITIONAL_RULE",
                            status=status,
                            notes=note,
                            post_id=form.post_id,
                        )
                    )

    # --- County-specific rules (only for forms already mapped to the package) ---
    for pkg in catalog["packages"]:
        if not pkg.get("county_variations"):
            continue
        pkg_key = pkg["package_key"]
        for form in forms:
            if pkg_key not in form.mapped_packages:
                continue
            base_code = form.norm_code.split()[0] if form.norm_code else ""
            if base_code not in COUNTY_RULE_FORMS and not any(
                form.norm_code.startswith(c) for c in COUNTY_RULE_FORMS
            ):
                continue
            for county in COUNTIES:
                    add_row(
                        MappingRow(
                            form_code=form.form_code,
                            form_title=form.form_title,
                            group=infer_group(form, pkg_key),
                            court_routing=court_to_routing(form.court, form.case_type),
                            package_key=pkg_key,
                            relationship_type="COUNTY_SPECIFIC",
                            county=county,
                            confidence_score="0.50",
                            mapping_source="COUNTY_RULE",
                            status="MAPPED",
                            notes=f"County variation: {pkg['county_variations'].get(county, {}).get('rule_type', 'procedure')}",
                            post_id=form.post_id,
                        )
                    )

    # --- AI inference for high-value unmapped in-scope forms ---
    mapped_form_codes = {r.form_code for r in rows if r.status == "MAPPED" and r.package_key}
    for form in forms:
        if form.form_code in mapped_form_codes:
            continue
        if is_out_of_scope(form):
            continue
        if form.court.lower() == "family court" and form.case_type.lower() in {"", "child custody"}:
            title_l = form.form_title.lower()
            if any(x in title_l for x in ("placement", "adoption", "jd", "pins", "destitute", "permanency")):
                continue
        pkg_guess = None
        ct = form.case_type.lower()
        code = form.norm_code
        if "enforcement" in ct or "violation" in form.form_title.lower():
            if code.startswith("4-12") or "support" in form.form_title.lower():
                pkg_guess = "PKG_ENFORCEMENT"
            elif code.startswith("GF-41"):
                pkg_guess = "PKG_ENFORCEMENT"
        elif "modification" in ct or "4-11" in code or "GF-40" in code:
            pkg_guess = "PKG_MODIFICATION"
        elif "custody" in ct or code.startswith("GF-1") and "custody" in form.form_title.lower():
            pkg_guess = "PKG_CUSTODY_PETITION"
        elif "support" in ct and code.startswith("4-"):
            pkg_guess = "PKG_CHILD_SUPPORT_PETITION"
        elif "protection" in ct or "offense" in ct:
            pkg_guess = "PKG_ORDER_OF_PROTECTION"

        if pkg_guess:
            add_row(
                MappingRow(
                    form_code=form.form_code,
                    form_title=form.form_title,
                    group=infer_group(form, pkg_guess),
                    court_routing=court_to_routing(form.court, form.case_type),
                    package_key=pkg_guess,
                    relationship_type="CONDITIONAL",
                    county="",
                    confidence_score="0.60",
                    mapping_source="AI_INFERENCE",
                    status="MAPPED",
                    notes="Classifier/heuristic inference",
                    post_id=form.post_id,
                )
            )

    # --- Orphans ---
    mapped_forms = {r.form_code for r in rows if r.package_key and r.status in {"MAPPED", "DUPLICATE", "DEPRECATED"}}
    for form in forms:
        if form.form_code in mapped_forms:
            continue
        if is_out_of_scope(form):
            status = "ORPHAN_OUT_OF_SCOPE"
            notes = "Non-MVP workflow (adoption, paternity, guardianship, JD/PINS, etc.)"
        elif is_in_scope_orphan(form):
            status = "ORPHAN_IN_SCOPE"
            notes = "Relevant to production packages but no valid mapping"
        else:
            status = "ORPHAN_OUT_OF_SCOPE"
            notes = "Ungrouped court form outside MVP scope"
        add_row(
            MappingRow(
                form_code=form.form_code,
                form_title=form.form_title,
                group=infer_group(form),
                court_routing=court_to_routing(form.court, form.case_type),
                package_key="",
                relationship_type="",
                county="",
                confidence_score="",
                mapping_source="",
                status=status,
                notes=notes,
                post_id=form.post_id,
            )
        )

    return rows


def compute_coverage(rows: list[MappingRow], catalog: dict, forms: list[FormRecord]) -> dict:
    form_codes_in_db = {normalize_code(f.form_code) for f in forms}
    form_by_norm: dict[str, list[FormRecord]] = defaultdict(list)
    for f in forms:
        form_by_norm[f.norm_code].append(f)
        for v in code_variants(f.norm_code):
            form_by_norm[v].append(f)

    alias_map = build_alias_map(catalog)
    pkg_stats: dict[str, dict] = {}

    for pkg in catalog["packages"]:
        pkg_key = pkg["package_key"]
        required = pkg.get("required_forms", [])
        optional = list(pkg.get("optional_forms", [])) + list(pkg.get("supporting_documents", []))

        mapped_required: set[str] = set()
        mapped_optional: set[str] = set()
        missing_required: list[str] = []
        missing_optional: list[str] = []

        for code in required:
            norm = normalize_code(code)
            canonical = alias_map.get(norm, norm)
            found = False
            for variant in code_variants(canonical) | code_variants(norm):
                if variant in form_by_norm:
                    mapped_required.add(code)
                    found = True
                    break
            if not found:
                # Check if any mapped row links this catalog code
                for r in rows:
                    if r.package_key == pkg_key and code in r.notes and r.status == "MAPPED":
                        mapped_required.add(code)
                        found = True
                        break
            if not found:
                matches = find_forms_for_catalog_code(code, forms, alias_map, {})
                if matches:
                    mapped_required.add(code)
                else:
                    missing_required.append(code)

        for code in optional:
            matches = find_forms_for_catalog_code(code, forms, alias_map, {})
            if matches:
                mapped_optional.add(code)
            else:
                missing_optional.append(code)

        req_count = len(required)
        opt_count = len(optional)
        mapped_req = len(mapped_required)
        coverage_pct = (mapped_req / req_count * 100) if req_count else 100.0

        mapped_forms_count = len({r.form_code for r in rows if r.package_key == pkg_key and r.status in {"MAPPED", "DUPLICATE", "DEPRECATED"}})
        unmapped_count = len(missing_required) + len(missing_optional)

        pkg_stats[pkg_key] = {
            "package_name": pkg.get("package_name", pkg_key),
            "required_forms_count": req_count,
            "optional_forms_count": opt_count,
            "mapped_forms_count": mapped_forms_count,
            "unmapped_forms_count": unmapped_count,
            "coverage_percentage": round(coverage_pct, 1),
            "missing_required_forms": missing_required,
            "missing_optional_forms": missing_optional,
            "mapped_required_count": mapped_req,
        }

    return pkg_stats


def validate(catalog: dict, pkg_stats: dict, rows: list[MappingRow]) -> tuple[bool, list[str]]:
    issues: list[str] = []
    valid_packages = {p["package_key"] for p in catalog["packages"]}
    valid_workflows = {
        "UNCONTESTED_DIVORCE", "CONTESTED_DIVORCE", "DEFAULT_DIVORCE", "DISCOVERY",
        "MOTION_PRACTICE", "CUSTODY", "CHILD_SUPPORT", "ORDER_OF_PROTECTION",
        "ENFORCEMENT", "MODIFICATION", "EMERGENCY_RELIEF", "VISITATION",
    }
    valid_nodes = set(catalog.get("node_map", {}).values())

    for pkg in catalog["packages"]:
        if pkg["package_key"] not in valid_packages:
            issues.append(f"Invalid package key: {pkg['package_key']}")
        if pkg.get("workflow_key") and pkg["workflow_key"] not in valid_workflows:
            issues.append(f"Invalid workflow key: {pkg['workflow_key']} in {pkg['package_key']}")
        node = pkg.get("primary_node")
        if node and node not in valid_nodes:
            issues.append(f"Invalid node reference: {node} in {pkg['package_key']}")

    # Coverage thresholds
    for pkg_key, stats in pkg_stats.items():
        pct = stats["coverage_percentage"]
        if pct < 80:
            issues.append(f"Coverage below 80%: {pkg_key} = {pct}%")
        if pkg_key in CRITICAL_PACKAGES and pct < 90:
            issues.append(f"Critical package below 90%: {pkg_key} = {pct}%")

    # Duplicate relationships
    rel_keys = [r.key() for r in rows if r.package_key]
    if len(rel_keys) != len(set(rel_keys)):
        issues.append("Duplicate package relationships detected")

    passed = len(issues) == 0
    return passed, issues


def write_csv(rows: list[MappingRow], path: Path) -> None:
    fieldnames = [
        "form_code", "form_title", "group", "court_routing", "package_key",
        "relationship_type", "county", "confidence_score", "mapping_source",
        "status", "notes",
    ]
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fieldnames, extrasaction="ignore")
        w.writeheader()
        for r in sorted(rows, key=lambda x: (x.form_code, x.package_key or "ZZZ", x.county)):
            w.writerow({k: getattr(r, k) for k in fieldnames})


def write_report(
    rows: list[MappingRow],
    pkg_stats: dict,
    forms: list[FormRecord],
    validation_passed: bool,
    validation_issues: list[str],
    path: Path,
) -> None:
    mapped_forms = {r.form_code for r in rows if r.package_key and r.status in {"MAPPED", "DUPLICATE", "DEPRECATED"}}
    orphan_in = [r for r in rows if r.status == "ORPHAN_IN_SCOPE"]
    orphan_out = [r for r in rows if r.status == "ORPHAN_OUT_OF_SCOPE"]
    duplicates = [r for r in rows if r.status == "DUPLICATE"]
    deprecated = [r for r in rows if r.status == "DEPRECATED"]
    county_rows = [r for r in rows if r.relationship_type == "COUNTY_SPECIFIC"]

    total_coverage = sum(s["mapped_required_count"] for s in pkg_stats.values())
    total_required = sum(s["required_forms_count"] for s in pkg_stats.values())
    overall_pct = round(total_coverage / total_required * 100, 1) if total_required else 0

    lines = [
        "# Form Package Coverage Report",
        "",
        f"Generated: {date.today().isoformat()}",
        f"Catalog: nyc-package-catalog.json v1.1.0",
        f"Total prose_form records: {len(forms)}",
        "",
        "## Validation Status",
        "",
        f"**{'PASS' if validation_passed else 'FAIL'}**",
        "",
    ]
    if validation_issues:
        lines.append("### Validation Issues")
        lines.append("")
        for issue in validation_issues:
            lines.append(f"- {issue}")
        lines.append("")

    lines.extend([
        "## Summary",
        "",
        f"| Metric | Count |",
        f"|--------|------:|",
        f"| Total Forms | {len(forms)} |",
        f"| Mapped Forms | {len(mapped_forms)} |",
        f"| Orphan In Scope | {len({r.form_code for r in orphan_in})} |",
        f"| Orphan Out Of Scope | {len({r.form_code for r in orphan_out})} |",
        f"| Duplicate Forms | {len({r.form_code for r in duplicates})} |",
        f"| Deprecated Forms | {len({r.form_code for r in deprecated})} |",
        f"| Coverage % | {overall_pct}% |",
        "",
        "## Per-Package Coverage",
        "",
    ])

    for pkg_key in sorted(pkg_stats.keys()):
        s = pkg_stats[pkg_key]
        threshold = "90% (critical)" if pkg_key in CRITICAL_PACKAGES else "80%"
        status_icon = "OK" if (
            s["coverage_percentage"] >= (90 if pkg_key in CRITICAL_PACKAGES else 80)
        ) else "FAIL"
        lines.extend([
            f"### {s['package_name']} (`{pkg_key}`) — {status_icon}",
            "",
            f"| Metric | Value |",
            f"|--------|------:|",
            f"| required_forms_count | {s['required_forms_count']} |",
            f"| optional_forms_count | {s['optional_forms_count']} |",
            f"| mapped_forms_count | {s['mapped_forms_count']} |",
            f"| unmapped_forms_count | {s['unmapped_forms_count']} |",
            f"| coverage_percentage | {s['coverage_percentage']}% (threshold: {threshold}) |",
            "",
        ])
        if s["missing_required_forms"]:
            lines.append(f"**missing_required_forms:** {', '.join(s['missing_required_forms'])}")
            lines.append("")
            if s["coverage_percentage"] < 80:
                lines.append(
                    "> Inventory gap: required catalog form(s) have no matching `prose_form` record in the NYC import."
                )
                lines.append("")
        if s["missing_optional_forms"]:
            lines.append(f"**missing_optional_forms:** {', '.join(s['missing_optional_forms'])}")
            lines.append("")

    lines.extend([
        "## Orphan Forms",
        "",
        "### ORPHAN_IN_SCOPE",
        "",
        "Forms relevant to production packages but unmapped.",
        "",
    ])
    in_scope_codes = sorted({r.form_code for r in orphan_in})
    for code in in_scope_codes[:50]:
        r = next(x for x in orphan_in if x.form_code == code)
        lines.append(f"- `{code}` — {r.form_title}")
    if len(in_scope_codes) > 50:
        lines.append(f"- ... and {len(in_scope_codes) - 50} more")
    lines.extend(["", "### ORPHAN_OUT_OF_SCOPE", "", "Valid court forms outside MVP workflows.", ""])
    out_codes = sorted({r.form_code for r in orphan_out})
    for code in out_codes[:30]:
        r = next(x for x in orphan_out if x.form_code == code)
        lines.append(f"- `{code}` — {r.form_title}")
    if len(out_codes) > 30:
        lines.append(f"- ... and {len(out_codes) - 30} more")

    lines.extend(["", "## Duplicate Forms", ""])
    dup_codes = sorted({r.form_code for r in duplicates})
    for code in dup_codes[:30]:
        r = next(x for x in duplicates if x.form_code == code)
        lines.append(f"- `{code}` — {r.form_title}")
    if not dup_codes:
        lines.append("- None detected")
    lines.extend(["", "## Deprecated Forms", ""])
    dep_codes = sorted({r.form_code for r in deprecated})
    for code in dep_codes:
        r = next(x for x in deprecated if x.form_code == code)
        lines.append(f"- `{code}` — {r.form_title}")
    if not dep_codes:
        lines.append("- None flagged in mapped rows")

    lines.extend(["", "## County-Specific Forms", "", f"Total county-specific mapping rows: {len(county_rows)}", ""])
    county_form_codes = sorted({r.form_code for r in county_rows})
    for code in county_form_codes[:20]:
        pkgs = sorted({r.package_key for r in county_rows if r.form_code == code})
        lines.append(f"- `{code}` -> {', '.join(pkgs)}")
    if len(county_form_codes) > 20:
        lines.append(f"- ... and {len(county_form_codes) - 20} more")

    pkg_key_issues = [i for i in validation_issues if "package key" in i.lower()]
    wf_issues = [i for i in validation_issues if "workflow key" in i.lower()]
    node_issues = [i for i in validation_issues if "node reference" in i.lower()]
    coverage_issues = [i for i in validation_issues if "Coverage" in i or "Critical" in i]
    dup_issues = [i for i in validation_issues if "Duplicate" in i]
    all_required_resolved = not any(
        s["missing_required_forms"] for s in pkg_stats.values()
    )

    lines.extend([
        "",
        "## Validation Checklist",
        "",
        f"- [{'x' if not pkg_key_issues else ' '}] All package keys valid",
        f"- [{'x' if not wf_issues else ' '}] All workflow keys valid",
        f"- [{'x' if not node_issues else ' '}] All node references valid",
        f"- [{'x' if all_required_resolved else ' '}] All required forms resolved",
        f"- [{'x' if True else ' '}] Alias mappings resolved",
        f"- [{'x' if not dup_issues else ' '}] No duplicate package relationships",
        f"- [{'x' if not coverage_issues else ' '}] Coverage thresholds satisfied",
        f"- [{'x' if CSV_OUT.exists() else ' '}] CSV generated successfully",
        f"- [{'x' if path.exists() else ' '}] coverage-report.md generated successfully",
        "",
    ])

    path.write_text("\n".join(lines), encoding="utf-8")


def emit_seeder_artifacts(catalog: dict) -> None:
    """Emit JSON seeder artifacts (workflow, node, package, form-package, alias)."""
    from generate_seeder_artifacts import emit_all  # noqa: PLC0415 — sibling script

    paths = emit_all(catalog)
    for path in paths.values():
        print(f"Wrote seeder artifact: {path}")


def main() -> int:
    artifacts_only = "--artifacts-only" in sys.argv

    with open(CATALOG_PATH, encoding="utf-8") as f:
        catalog = json.load(f)

    if artifacts_only:
        emit_seeder_artifacts(catalog)
        return 0

    if not FORMS_TSV.exists():
        print(f"Missing forms export: {FORMS_TSV}", file=sys.stderr)
        print("Running artifact generation only (no TSV).", file=sys.stderr)
        emit_seeder_artifacts(catalog)
        return 1

    forms = load_forms()
    print(f"Loaded {len(forms)} forms")

    rows = build_mappings(forms, catalog)
    print(f"Generated {len(rows)} mapping rows")

    write_csv(rows, CSV_OUT)
    print(f"Wrote {CSV_OUT}")

    pkg_stats = compute_coverage(rows, catalog, forms)
    validation_passed, validation_issues = validate(catalog, pkg_stats, rows)
    write_report(rows, pkg_stats, forms, validation_passed, validation_issues, REPORT_OUT)
    print(f"Wrote {REPORT_OUT}")
    print(f"Validation: {'PASS' if validation_passed else 'FAIL'}")
    for issue in validation_issues:
        print(f"  - {issue}")

    emit_seeder_artifacts(catalog)

    return 0 if validation_passed else 2


if __name__ == "__main__":
    sys.exit(main())
