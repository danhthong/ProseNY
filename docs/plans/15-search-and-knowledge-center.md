# Plan 15 — Search & Knowledge Center

**Status:** Draft — awaiting review  
**Priority:** P3  
**Depends on:** Plan 05, Plan 10  
**Estimated effort:** Medium (4–6 days)

---

## Goal

Public **search** across forms, workflows, and procedural topics plus an educational **Knowledge Center** — PRD Ch. 31–32.

## Requirements reference

- PRD Ch. 31 — Search System
- PRD Ch. 32 — Knowledge Center
- Executive Blueprint — search forms, download, view instructions

## Current state

- Forms catalog searchable internally
- No public-facing search UI
- Guidance content in module seeds, not exposed as articles

## Scope

### In scope

- Site search: forms by code/title, workflows by issue, guidance articles
- Knowledge Center: procedural articles linked to workflows (e.g. “How uncontested divorce works in NYC”)
- REST search endpoint + optional WordPress page template
- Results show court, county scope, link to start intake pre-filled

### Out of scope

- Full-text search inside PDF forms
- User-generated content / forums

## Deliverables

1. `GET /prose/v1/search?q=` unified index
2. Knowledge Center page template or block
3. Minimum 10 seed articles covering MVP workflows
4. Search from homepage header (optional)

## Acceptance criteria

- [ ] Search “custody” returns custody workflow + relevant forms
- [ ] Search “UD-1” returns form record with download link
- [ ] Articles link to intake with suggested prompt chip
- [ ] Out-of-scope topics not in index

## Implementation tasks

1. Build search index from forms catalog + workflows + guidance
2. Implement REST search with ranking (form code exact match priority)
3. Create KC page structure in theme
4. Write/import seed articles from requirements docs
5. SEO metadata for public articles

## Files likely touched

- New `modules/search/` or extend forms module
- `themes/prose-app/` templates
- `docs/knowledge-center/` content folder

## Review questions

1. Knowledge Center **public** (SEO) or **login-only**?
