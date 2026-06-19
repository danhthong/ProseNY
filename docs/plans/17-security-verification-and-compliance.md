# Plan 17 — Security, Verification & Compliance

**Status:** Complete  
**Priority:** P2  
**Depends on:** Plan 05, Plan 14  
**Estimated effort:** Medium (3–5 days)

---

## Goal

Meet **security requirements** and keep official forms/links verified — PRD Ch. 33, 35.

## Requirements reference

- PRD Ch. 33 — Verification System
- PRD Ch. 35 — Authentication, audit logs, document protection
- Disclaimer: not legal advice, not a law firm

## Current state

- `Security\Disclaimer` class used in theme
- REST routes mostly `permission_callback => __return_true` on public intake
- Official form URLs in JSON catalogs
- No automated link verification cron

## Scope

### In scope

- Rate limiting on public intake/document endpoints
- Nonce validation audit on all REST routes
- Uploaded document security (Plan 11): mime validation, size limits, private storage
- Audit log: intake complete, package download, admin changes
- Verification job: HTTP check official form URLs, report stale
- Privacy policy alignment: what is stored, retention
- PII minimization in logs and AI payloads

### Out of scope

- SOC2 certification
- HIPAA (unless scope expands)

## Deliverables

1. Security checklist completed and documented
2. Rate limit middleware or WP transient-based throttle
3. Verification cron + admin report (Plan 14)
4. Redacted AI logging mode (config flag)
5. Document retention policy

## Acceptance criteria

- [ ] Intake endpoint resists basic abuse (rate limit triggers)
- [ ] No secrets in repo; API keys in wp-config/env only
- [ ] Disclaimer on all user-facing surfaces
- [ ] Broken form URLs flagged within 7 days of check

## Implementation tasks

1. REST security audit
2. Implement rate limits
3. Build verification crawler (respect robots, cache results)
4. Audit log table + write hooks
5. Security review of document upload path

## Files likely touched

- All REST controllers in `prose-core/modules/*/rest/`
- New `modules/security/` or `includes/class-audit-log.php`
- Plan 11 upload handler

## Review questions

1. Required **user accounts** for document upload?
