# Plan 06 — Package Builder & Filing Package

**Status:** Draft — awaiting review  
**Priority:** P1  
**Depends on:** Plan 05  
**Estimated effort:** Medium (4–6 days)

---

## Goal

Users complete intake and download a **correct blank filing package** (merged PDF or form list) for their resolved workflow and county.

## Requirements reference

- PRD — Forms Automation, Filing Package Generator
- Divorce Ecosystem — packet structure by phase
- Case Actions “Get Documents” flow

## Current state

- `Package_Builder_Module`, `Merged_Blank_Pdf_Service`
- `Case_Actions_Resolver` — shows Get Documents when intake complete
- Package preview shortcode + REST
- Assembly module for filled PDFs (partial)

## Scope

### In scope

- End-to-end: workflow + county → package manifest → download
- Merged blank PDF for full workflow packet
- Individual form download fallback
- Package preview UI in workspace + homepage
- Direct form code request (e.g. “give me UD-1”) — already started in interpreter
- Manifest status: available / missing / county-specific gap

### Out of scope

- Auto-filled PDFs from intake facts (Assembly — later milestone within this plan or follow-up)
- E-filing submission (Plan 19)

## Deliverables

1. Reliable `POST /prose/v1/package/preview` and merged PDF route
2. Package manifest JSON: forms, order, stage labels, instructions links
3. UI: package list + download button enabled at intake complete
4. Tests: each divorce workflow produces non-empty package

## Acceptance criteria

- [ ] Uncontested divorce (no children) → downloadable packet matching workflow `required_forms`
- [ ] Missing PDF asset → clear message, not silent failure
- [ ] Get Documents disabled until intake complete (or explicit direct form codes)
- [ ] County stored in case profile reflected in package if county-specific forms exist

## Implementation tasks

1. Trace package resolution: workflow JSON → catalog → PDF paths
2. Fix missing PDF assets or aliases
3. Unify homepage widget + workspace download paths
4. Add package preview cards in chat on `complete_intake`
5. PHPUnit + manual download test per workflow

## Files likely touched

- `modules/packagebuilder/`
- `modules/intake/class-case-actions-resolver.php`
- `modules/forms/classification/class-workflow-package-builder.php`
- `themes/prose-app/template-parts/courtflow-context-panel.php`

## Review questions

1. Ship **blank forms only** for MVP acceptance, or require **partial auto-fill**?
