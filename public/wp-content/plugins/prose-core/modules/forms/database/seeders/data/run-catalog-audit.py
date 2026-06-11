#!/usr/bin/env python3
"""Read-only catalog audit against the live imported database. Generates catalog-audit-report.md."""

from __future__ import annotations

import subprocess
from datetime import datetime, timezone
from pathlib import Path

DATA_DIR = Path(__file__).resolve().parent
REPORT_OUT = DATA_DIR / "catalog-audit-report.md"

MYSQL = "/Users/dangthong/Library/Application Support/Local/lightning-services/mysql-8.4.0/bin/darwin/bin/mysql"
SOCKET = "/Users/dangthong/Library/Application Support/Local/run/05uZ7Ov1O/mysql/mysqld.sock"
DB = "local"
PREFIX = "wp_"

CRITICAL_PACKAGES = {
    "PKG_UNCONTESTED_NO_CHILDREN",
    "PKG_UNCONTESTED_WITH_CHILDREN",
    "PKG_CONTESTED_COMMENCEMENT",
    "PKG_CUSTODY_PETITION",
    "PKG_CHILD_SUPPORT_PETITION",
    "PKG_ORDER_OF_PROTECTION",
}


def q(sql: str) -> list[list[str]]:
    """Run a read-only SQL query; return rows as lists of strings (tab-separated, no header)."""
    result = subprocess.run(
        [MYSQL, "-uroot", "-proot", f"--socket={SOCKET}", "-N", "-B", DB, "-e", sql],
        capture_output=True,
        text=True,
        check=True,
    )
    rows = []
    for line in result.stdout.splitlines():
        if line == "":
            continue
        rows.append(line.split("\t"))
    return rows


def scalar(sql: str) -> int:
    rows = q(sql)
    if not rows or not rows[0]:
        return 0
    try:
        return int(rows[0][0])
    except ValueError:
        return 0


def meta_count(meta_key: str, meta_value: str | None = None) -> int:
    if meta_value is None:
        return scalar(
            f"SELECT COUNT(DISTINCT p.ID) FROM {PREFIX}posts p "
            f"JOIN {PREFIX}postmeta m ON m.post_id=p.ID "
            f"WHERE p.post_type='prose_package' AND p.post_status='publish' AND m.meta_key='{meta_key}'"
        )
    return scalar(
        f"SELECT COUNT(DISTINCT p.ID) FROM {PREFIX}posts p "
        f"JOIN {PREFIX}postmeta m ON m.post_id=p.ID "
        f"WHERE p.post_type='prose_package' AND p.post_status='publish' "
        f"AND m.meta_key='{meta_key}' AND m.meta_value='{meta_value}'"
    )


def main() -> int:
    issues_hard: list[str] = []
    issues_soft: list[str] = []

    # 1. Workflow Catalog.
    wf_total = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_workflows")
    wf_active = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_workflows WHERE is_active=1")
    wf_dupes = q(
        f"SELECT workflow_key, COUNT(*) c FROM {PREFIX}prose_workflows GROUP BY workflow_key HAVING c>1"
    )
    wf_empty_key = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_workflows WHERE workflow_key='' OR workflow_key IS NULL")

    # 2. Node Catalog.
    node_total = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_workflow_nodes")
    node_active = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_workflow_nodes WHERE status='active'")
    edge_total = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_workflow_edges")
    node_dupes = q(
        f"SELECT node_key, COUNT(*) c FROM {PREFIX}prose_workflow_nodes GROUP BY node_key HAVING c>1"
    )
    # Orphan nodes: workflow_key not present in workflows.
    orphan_nodes = q(
        f"SELECT n.node_key, n.workflow_key FROM {PREFIX}prose_workflow_nodes n "
        f"LEFT JOIN {PREFIX}prose_workflows w ON w.workflow_key=n.workflow_key "
        f"WHERE w.workflow_id IS NULL AND n.workflow_key<>''"
    )
    # Orphan edges: from/to node id missing.
    orphan_edges = q(
        f"SELECT e.edge_id FROM {PREFIX}prose_workflow_edges e "
        f"LEFT JOIN {PREFIX}prose_workflow_nodes nf ON nf.node_id=e.from_node_id "
        f"LEFT JOIN {PREFIX}prose_workflow_nodes nt ON nt.node_id=e.to_node_id "
        f"WHERE nf.node_id IS NULL OR nt.node_id IS NULL"
    )
    # Empty workflows: active workflow with no nodes.
    empty_workflows = q(
        f"SELECT w.workflow_key FROM {PREFIX}prose_workflows w "
        f"LEFT JOIN {PREFIX}prose_workflow_nodes n ON n.workflow_key=w.workflow_key AND n.status='active' "
        f"WHERE w.is_active=1 GROUP BY w.workflow_key HAVING COUNT(n.node_id)=0"
    )

    # 3. Package Catalog (CPT).
    pkg_total = scalar(
        f"SELECT COUNT(*) FROM {PREFIX}posts WHERE post_type='prose_package' AND post_status='publish'"
    )
    pkg_with_key = meta_count("prose_package_id")
    pkg_active = meta_count("prose_package_is_active", "1")
    pkg_dupes = q(
        f"SELECT m.meta_value, COUNT(*) c FROM {PREFIX}posts p "
        f"JOIN {PREFIX}postmeta m ON m.post_id=p.ID "
        f"WHERE p.post_type='prose_package' AND p.post_status='publish' AND m.meta_key='prose_package_id' "
        f"GROUP BY m.meta_value HAVING c>1"
    )

    # 4. Package Relations.
    rel_total = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_package_relations")
    # Invalid relations: from/to package key not present as a package_id meta.
    pkg_keys = {r[0] for r in q(
        f"SELECT DISTINCT meta_value FROM {PREFIX}postmeta m "
        f"JOIN {PREFIX}posts p ON p.ID=m.post_id "
        f"WHERE m.meta_key='prose_package_id' AND p.post_type='prose_package' AND p.post_status='publish'"
    )}
    all_rels = q(
        f"SELECT from_package_key, to_package_key, relation_type FROM {PREFIX}prose_package_relations"
    )
    invalid_rels = []
    self_rels = []
    for r in all_rels:
        frm, to = r[0], r[1]
        if frm not in pkg_keys or to not in pkg_keys:
            invalid_rels.append(r)
        if frm == to:
            self_rels.append(r)
    rel_dupes = q(
        f"SELECT from_package_key,to_package_key,relation_type,condition_key,COUNT(*) c "
        f"FROM {PREFIX}prose_package_relations "
        f"GROUP BY from_package_key,to_package_key,relation_type,condition_key HAVING c>1"
    )

    # 5. Package Forms.
    pf_total = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_package_forms")
    pf_required = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_package_forms WHERE requirement='required'")
    pf_optional = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_package_forms WHERE requirement='optional'")
    pf_supporting = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_package_forms WHERE requirement='supporting'")
    pf_resolved = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_package_forms WHERE form_id IS NOT NULL AND form_id>0")
    pf_unresolved = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_package_forms WHERE form_id IS NULL OR form_id=0")
    pf_dupes = q(
        f"SELECT package_id,form_code,requirement,COUNT(*) c FROM {PREFIX}prose_package_forms "
        f"GROUP BY package_id,form_code,requirement HAVING c>1"
    )
    # Orphan package_forms: package_id not an existing prose_package post.
    orphan_pf = q(
        f"SELECT pf.id FROM {PREFIX}prose_package_forms pf "
        f"LEFT JOIN {PREFIX}posts p ON p.ID=pf.package_id AND p.post_type='prose_package' "
        f"WHERE p.ID IS NULL"
    )
    # Empty packages: package post with zero package_forms rows.
    empty_packages = q(
        f"SELECT m.meta_value FROM {PREFIX}posts p "
        f"JOIN {PREFIX}postmeta m ON m.post_id=p.ID AND m.meta_key='prose_package_id' "
        f"LEFT JOIN {PREFIX}prose_package_forms pf ON pf.package_id=p.ID "
        f"WHERE p.post_type='prose_package' AND p.post_status='publish' "
        f"GROUP BY p.ID, m.meta_value HAVING COUNT(pf.id)=0"
    )
    # Node-package links.
    np_total = scalar(f"SELECT COUNT(*) FROM {PREFIX}prose_node_packages")
    orphan_np = q(
        f"SELECT npk.id FROM {PREFIX}prose_node_packages npk "
        f"LEFT JOIN {PREFIX}prose_workflow_nodes n ON n.node_id=npk.node_id "
        f"WHERE n.node_id IS NULL"
    )

    # 6. Alias Registry (wp_options) — decode PHP-serialized value via php for fidelity.
    alias_data = _decode_alias_option()
    alias_map = alias_data.get("alias_to_canonical", {}) if isinstance(alias_data, dict) else {}
    canon_map = alias_data.get("canonical_to_aliases", {}) if isinstance(alias_data, dict) else {}
    deprecated = alias_data.get("deprecated", []) if isinstance(alias_data, dict) else []
    variant_sets = alias_data.get("variant_sets", []) if isinstance(alias_data, dict) else []

    alias_count = sum(1 for a, c in alias_map.items() if a != c)
    canonical_count = len(canon_map)
    # Alias integrity: alias whose canonical missing from canon map.
    alias_dangling = [a for a, c in alias_map.items() if a != c and c not in canon_map and c not in alias_map]
    alias_cycle = [a for a, c in alias_map.items() if a != c and alias_map.get(c, c) != c]
    dual_role = [a for a in alias_map if a in canon_map and alias_map[a] != a]

    # Coverage: required import_backed forms resolved per package.
    cov_rows = q(
        f"SELECT m.meta_value AS pkgkey, "
        f"SUM(CASE WHEN pf.requirement='required' THEN 1 ELSE 0 END) AS req, "
        f"SUM(CASE WHEN pf.requirement='required' AND pf.form_id IS NOT NULL AND pf.form_id>0 THEN 1 ELSE 0 END) AS req_resolved "
        f"FROM {PREFIX}posts p "
        f"JOIN {PREFIX}postmeta m ON m.post_id=p.ID AND m.meta_key='prose_package_id' "
        f"LEFT JOIN {PREFIX}prose_package_forms pf ON pf.package_id=p.ID "
        f"WHERE p.post_type='prose_package' AND p.post_status='publish' "
        f"GROUP BY p.ID, m.meta_value ORDER BY m.meta_value"
    )

    coverage = []
    pkgs_pass = 0
    crit_pass = 0
    crit_total = 0
    for row in cov_rows:
        pkgkey = row[0]
        req = int(row[1]) if row[1] not in ("", "NULL") else 0
        req_resolved = int(row[2]) if row[2] not in ("", "NULL") else 0
        pct = (req_resolved / req * 100) if req > 0 else 100.0
        is_crit = pkgkey in CRITICAL_PACKAGES
        threshold = 90 if is_crit else 80
        ok = pct >= threshold
        if ok:
            pkgs_pass += 1
        if is_crit:
            crit_total += 1
            if ok:
                crit_pass += 1
        coverage.append((pkgkey, req, req_resolved, round(pct, 1), threshold, ok))

    # Assemble issues.
    if wf_dupes:
        issues_hard.append(f"{len(wf_dupes)} duplicate workflow_key(s)")
    if wf_empty_key:
        issues_hard.append(f"{wf_empty_key} workflow(s) with empty workflow_key")
    if node_dupes:
        issues_hard.append(f"{len(node_dupes)} duplicate node_key(s)")
    if orphan_nodes:
        issues_hard.append(f"{len(orphan_nodes)} orphan node(s) (workflow_key missing)")
    if orphan_edges:
        issues_hard.append(f"{len(orphan_edges)} orphan edge(s) (node id missing)")
    if empty_workflows:
        issues_soft.append(f"{len(empty_workflows)} empty workflow(s): {', '.join(r[0] for r in empty_workflows)}")
    if pkg_dupes:
        issues_hard.append(f"{len(pkg_dupes)} duplicate package key(s)")
    if invalid_rels:
        issues_hard.append(f"{len(invalid_rels)} invalid package relation(s) (unknown package key)")
    if self_rels:
        issues_soft.append(f"{len(self_rels)} self-referential package relation(s)")
    if rel_dupes:
        issues_hard.append(f"{len(rel_dupes)} duplicate package relation(s)")
    if pf_dupes:
        issues_hard.append(f"{len(pf_dupes)} duplicate package_form key(s)")
    if orphan_pf:
        issues_hard.append(f"{len(orphan_pf)} orphan package_form row(s)")
    if orphan_np:
        issues_hard.append(f"{len(orphan_np)} orphan node_package row(s)")
    if empty_packages:
        issues_soft.append(f"{len(empty_packages)} empty package(s): {', '.join(r[0] for r in empty_packages)}")
    if alias_dangling:
        issues_hard.append(f"{len(alias_dangling)} alias(es) with missing canonical")
    if alias_cycle:
        issues_hard.append(f"{len(alias_cycle)} alias cycle(s)")
    if dual_role:
        issues_hard.append(f"{len(dual_role)} code(s) both canonical and alias")
    if pf_unresolved:
        issues_soft.append(f"{pf_unresolved} package_form row(s) with unresolved form_id (no prose_form record)")

    failing_cov = [c for c in coverage if not c[5]]
    for c in failing_cov:
        issues_soft.append(f"Coverage {c[0]}: {c[3]}% (threshold {c[4]}%)")

    overall_pass = len(issues_hard) == 0

    # Readiness scoring.
    total_pkgs = len(coverage)
    readiness = {
        "structural_integrity": 100 if not issues_hard else max(0, 100 - len(issues_hard) * 20),
        "package_threshold_pass": round((pkgs_pass / total_pkgs * 100) if total_pkgs else 0, 1),
        "critical_pass": round((crit_pass / crit_total * 100) if crit_total else 0, 1),
        "form_resolution": round((pf_resolved / pf_total * 100) if pf_total else 0, 1),
    }

    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")

    L: list[str] = []
    L.append("# CourtFlow Catalog Audit Report")
    L.append("")
    L.append(f"Generated: {now}")
    L.append(f"Source: live database `{DB}` (read-only)")
    L.append(f"Final Result: **{'PASS' if overall_pass else 'FAIL'}**")
    L.append("")

    L.append("## Summary")
    L.append("")
    L.append("| Domain | Records | Status |")
    L.append("|--------|--------:|--------|")
    L.append(f"| Workflows | {wf_total} | {'OK' if not (wf_dupes or wf_empty_key) else 'ISSUES'} |")
    L.append(f"| Nodes | {node_total} | {'OK' if not (node_dupes or orphan_nodes) else 'ISSUES'} |")
    L.append(f"| Edges | {edge_total} | {'OK' if not orphan_edges else 'ISSUES'} |")
    L.append(f"| Packages | {pkg_total} | {'OK' if not pkg_dupes else 'ISSUES'} |")
    L.append(f"| Package Relations | {rel_total} | {'OK' if not (invalid_rels or rel_dupes) else 'ISSUES'} |")
    L.append(f"| Package Forms | {pf_total} | {'OK' if not (pf_dupes or orphan_pf) else 'ISSUES'} |")
    L.append(f"| Node-Package Links | {np_total} | {'OK' if not orphan_np else 'ISSUES'} |")
    L.append(f"| Alias Registry | {alias_count} aliases / {canonical_count} canonical | {'OK' if not (alias_dangling or alias_cycle or dual_role) else 'ISSUES'} |")
    L.append("")
    L.append(f"- Hard failures: **{len(issues_hard)}**")
    L.append(f"- Soft warnings: **{len(issues_soft)}**")
    L.append("")

    L.append("## Workflow Counts")
    L.append("")
    L.append(f"- Total workflows: {wf_total}")
    L.append(f"- Active workflows: {wf_active}")
    L.append(f"- Empty workflow_key: {wf_empty_key}")
    L.append(f"- Duplicate workflow keys: {len(wf_dupes)}")
    L.append(f"- Empty workflows (no nodes): {len(empty_workflows)}")
    if empty_workflows:
        for r in empty_workflows:
            L.append(f"  - {r[0]}")
    L.append("")

    L.append("## Node Counts")
    L.append("")
    L.append(f"- Total nodes: {node_total}")
    L.append(f"- Active nodes: {node_active}")
    L.append(f"- Total edges: {edge_total}")
    L.append(f"- Duplicate node keys: {len(node_dupes)}")
    L.append(f"- Orphan nodes (missing workflow): {len(orphan_nodes)}")
    L.append(f"- Orphan edges (missing node): {len(orphan_edges)}")
    L.append("")

    L.append("## Package Counts")
    L.append("")
    L.append(f"- Total package CPT posts: {pkg_total}")
    L.append(f"- Packages with package_key: {pkg_with_key}")
    L.append(f"- Active packages: {pkg_active}")
    L.append(f"- Duplicate package keys: {len(pkg_dupes)}")
    L.append(f"- Empty packages (no forms): {len(empty_packages)}")
    if empty_packages:
        for r in empty_packages:
            L.append(f"  - {r[0]}")
    L.append(f"- Package relations: {rel_total}")
    L.append(f"- Invalid package relations: {len(invalid_rels)}")
    L.append(f"- Duplicate package relations: {len(rel_dupes)}")
    L.append(f"- Self-referential relations: {len(self_rels)}")
    L.append(f"- Node-package links: {np_total}")
    L.append("")

    L.append("## Form Mapping Counts")
    L.append("")
    L.append(f"- Total package_form rows: {pf_total}")
    L.append(f"- Required: {pf_required}")
    L.append(f"- Optional: {pf_optional}")
    L.append(f"- Supporting: {pf_supporting}")
    L.append(f"- Resolved to prose_form (form_id set): {pf_resolved}")
    L.append(f"- Unresolved (form_id NULL): {pf_unresolved}")
    L.append(f"- Duplicate package_form keys: {len(pf_dupes)}")
    L.append("")

    L.append("## Orphan Records")
    L.append("")
    L.append(f"- Orphan nodes: {len(orphan_nodes)}")
    for r in orphan_nodes[:25]:
        L.append(f"  - node `{r[0]}` -> missing workflow `{r[1]}`")
    L.append(f"- Orphan edges: {len(orphan_edges)}")
    L.append(f"- Orphan package_forms: {len(orphan_pf)}")
    L.append(f"- Orphan node_packages: {len(orphan_np)}")
    L.append(f"- Invalid package relations: {len(invalid_rels)}")
    for r in invalid_rels[:25]:
        L.append(f"  - `{r[0]}` -> `{r[1]}` ({r[2]})")
    L.append(f"- Dangling aliases (missing canonical): {len(alias_dangling)}")
    for a in alias_dangling[:25]:
        L.append(f"  - `{a}` -> `{alias_map.get(a)}`")
    L.append("")

    L.append("## Duplicate Records")
    L.append("")
    L.append(f"- Duplicate workflow keys: {len(wf_dupes)}")
    for r in wf_dupes:
        L.append(f"  - `{r[0]}` x{r[1]}")
    L.append(f"- Duplicate node keys: {len(node_dupes)}")
    for r in node_dupes:
        L.append(f"  - `{r[0]}` x{r[1]}")
    L.append(f"- Duplicate package keys: {len(pkg_dupes)}")
    for r in pkg_dupes:
        L.append(f"  - `{r[0]}` x{r[1]}")
    L.append(f"- Duplicate package relations: {len(rel_dupes)}")
    L.append(f"- Duplicate package_form keys: {len(pf_dupes)}")
    L.append("")

    L.append("## Coverage Metrics")
    L.append("")
    L.append("Required import-backed form coverage per package (form_id resolved / required).")
    L.append("")
    L.append("| Package | Required | Resolved | Coverage % | Threshold | Status |")
    L.append("|---------|---------:|---------:|-----------:|----------:|--------|")
    for c in coverage:
        crit = " (critical)" if c[0] in CRITICAL_PACKAGES else ""
        L.append(f"| {c[0]}{crit} | {c[1]} | {c[2]} | {c[3]}% | {c[4]}% | {'PASS' if c[5] else 'FAIL'} |")
    L.append("")
    L.append(f"- Packages at/above threshold: {pkgs_pass}/{total_pkgs}")
    L.append(f"- Critical packages at/above threshold: {crit_pass}/{crit_total}")
    L.append("")

    L.append("## Readiness Score")
    L.append("")
    L.append("| Dimension | Score |")
    L.append("|-----------|------:|")
    L.append(f"| Structural integrity | {readiness['structural_integrity']}% |")
    L.append(f"| Package threshold pass rate | {readiness['package_threshold_pass']}% |")
    L.append(f"| Critical package pass rate | {readiness['critical_pass']}% |")
    L.append(f"| Form resolution rate | {readiness['form_resolution']}% |")
    L.append("")

    if issues_hard:
        L.append("### Hard Failures")
        L.append("")
        for i in issues_hard:
            L.append(f"- {i}")
        L.append("")
    if issues_soft:
        L.append("### Soft Warnings")
        L.append("")
        for i in issues_soft:
            L.append(f"- {i}")
        L.append("")

    L.append("---")
    L.append("")
    L.append(f"## Final Result: {'PASS' if overall_pass else 'FAIL'}")
    L.append("")
    if overall_pass:
        L.append("No structural (hard) failures detected. Catalog is referentially consistent.")
        if issues_soft:
            L.append("Soft warnings are non-blocking (unresolved optional/assembled forms or coverage notes).")
    else:
        L.append("Hard failures detected. See Hard Failures section.")

    REPORT_OUT.write_text("\n".join(L) + "\n", encoding="utf-8")
    print(f"Wrote {REPORT_OUT}")
    print(f"Final Result: {'PASS' if overall_pass else 'FAIL'}")
    print(f"Hard failures: {len(issues_hard)} | Soft warnings: {len(issues_soft)}")
    return 0 if overall_pass else 1


def _decode_alias_option() -> dict:
    """Decode the prose_form_alias_registry option via php -> json for fidelity."""
    php = (
        "$m=new mysqli('localhost','root','root','local',0,'" + SOCKET + "');"
        "if($m->connect_errno){fwrite(STDERR,$m->connect_error);exit(1);}"
        "$r=$m->query(\"SELECT option_value FROM " + PREFIX + "options "
        "WHERE option_name='prose_form_alias_registry' LIMIT 1\");"
        "$row=$r->fetch_row();"
        "if(!$row){echo '{}';exit;}"
        "$d=@unserialize($row[0]);"
        "echo json_encode($d===false?array():$d);"
    )
    try:
        out = subprocess.run(["php", "-r", php], capture_output=True, text=True, check=True)
        import json
        return json.loads(out.stdout or "{}")
    except Exception:
        return {}


def _php_unserialize_or_json(raw: str):
    """Decode a wp_options value: try JSON first, then minimal PHP unserialize."""
    import json
    raw = raw.strip()
    try:
        return json.loads(raw)
    except Exception:
        pass
    return _php_unserialize(raw)[0]


def _php_unserialize(s: str, i: int = 0):
    """Minimal PHP serialize parser (supports a, s, i, b, d, N)."""
    t = s[i]
    if t == "N":
        return None, i + 2
    if t == "b":
        j = s.index(";", i)
        return s[i + 2:j] == "1", j + 1
    if t == "i":
        j = s.index(";", i)
        return int(s[i + 2:j]), j + 1
    if t == "d":
        j = s.index(";", i)
        return float(s[i + 2:j]), j + 1
    if t == "s":
        colon1 = s.index(":", i + 2)
        length = int(s[i + 2:colon1])
        start = colon1 + 2
        val = s[start:start + length]
        return val, start + length + 2
    if t == "a":
        colon1 = s.index(":", i + 2)
        count = int(s[i + 2:colon1])
        j = colon1 + 2
        out = {}
        for _ in range(count):
            k, j = _php_unserialize(s, j)
            v, j = _php_unserialize(s, j)
            out[k] = v
        return out, j + 1
    raise ValueError(f"Unsupported token {t!r} at {i}")


if __name__ == "__main__":
    raise SystemExit(main())
