# Plan 03 — Court Routing & Overlap UX

**Status:** Complete  
**Priority:** P1  
**Depends on:** Plan 01  
**Estimated effort:** Medium (3–5 days)

---

## Goal

Correctly classify **Supreme Court vs Family Court vs overlap** and explain it clearly to users when multiple courts apply.

## Requirements reference

- PRD Ch. 15 — Routing Matrix
- Workflow README — `routing_rules`, `active_divorce` overrides
- Executive Blueprint — “Divorce + Custody → overlap”

## Current state

- `Routing_Engine` — 5-step pipeline with overlap detection
- `Court_Overlap_Resolver` — multi-court detection and redirect notes
- `Court_Routing_Explainer` — i18n template strings
- Workspace context panel — “Courts involved” section
- `Case_Actions_Resolver` — court rows in case summary

## Scope

### In scope

- Audit all `routing_rules` in workflow JSON for correctness
- Extend routing result to expose `courts: string[]`, `overlap: bool`, `overlap_reason`
- Case summary / context panel shows multi-court scenarios
- Intake completion message explains which court(s) and why (template-driven, not AI-decided)
- Tests: divorce-only, custody-only, divorce+children, active divorce + standalone custody petition, divorce + OP

### Out of scope

- Cross-court procedural transitions (motion filing) — guidance only
- County-specific routing differences (Plan 13)

## Deliverables

1. Enhanced `Routing_Result` / `Case_Profile` overlap metadata
2. User-facing overlap explainer strings (i18n)
3. Context panel “Courts involved” section
4. PHPUnit: `CourtResolutionTest` extended, `CourtOverlapTest`, routing engine overlap scenarios

## Acceptance criteria

- [x] “I want a divorce with two kids” → Supreme Court, divorce workflow with children
- [x] “I need custody, we are already in a divorce” → Supreme Court via routing_rules (not standalone FC custody)
- [x] “I need child support, no divorce” → Family Court
- [x] “Divorce and I need an order of protection” → overlap flag + both courts explained
- [x] AI never sets court — only displays system routing output

## Implementation tasks

1. Map routing matrix from requirements to test cases
2. Implement overlap detection in routing layer
3. Pass overlap to `Case_Actions_Resolver` summary
4. Add procedural explainer templates per overlap type
5. Update workspace context panel render

## Files touched

- `modules/routing/class-routing-engine.php`
- `modules/routing/class-routing-result.php`
- `modules/routing/class-case-profile.php`
- `modules/routing/class-court-routing-explainer.php`
- `modules/routing/resolver/class-court-overlap-resolver.php`
- `modules/routing/resolver/class-court-resolver.php`
- `modules/routing/class-signal-lexicon.php`
- `modules/intake/class-case-actions-resolver.php`
- `modules/intake/rest/class-courtflow-response-mapper.php`
- `themes/prose-app/template-parts/courtflow-context-panel.php`
- `themes/prose-app/build/courtflow.js`
- `themes/prose-app/assets/css/courtflow.css`

## Review questions

1. When overlap applies, show **one combined package** or **separate packages per court**?
