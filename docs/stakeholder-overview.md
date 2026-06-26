# CourtFlow AI — Stakeholder Overview

**CourtFlow AI** (ProSeNY) is a procedural navigation platform for people representing themselves in New York City family and matrimonial courts. It helps users understand where they are in a court process, what information is needed, which forms apply, and what typically happens next — without replacing a lawyer or giving legal advice.

---

## Who this is for

| Audience | How they benefit |
|----------|------------------|
| **Self-represented litigants** | Clear path through confusing court procedures; correct forms for their situation; plain-language guidance |
| **Platform operators** | Consistent, auditable procedure driven by rules — not ad hoc prompts |
| **Content reviewers** | Workflow and knowledge content can be updated without rewriting application code |
| **Future court partners** | Deterministic routing and form packages aligned to official NYC OCA forms |

---

## The problem CourtFlow solves

Family and divorce court procedures are difficult to navigate. Users must often determine:

- Which court handles their matter (Supreme Court vs Family Court)
- Which workflow applies (divorce, custody, support, order of protection, etc.)
- Which official forms are required at each stage
- What happens after filing, service, and response

CourtFlow reduces guesswork by encoding **official procedural rules** into the platform and presenting them through a guided experience.

---

## What CourtFlow does

### 1. Conversational intake

Users describe their situation in natural language. The assistant collects facts (county, children, agreement status, safety concerns, etc.) through conversation — not a rigid form wizard.

### 2. Deterministic court and workflow routing

A **Rules Engine** (not AI) decides:

- Primary court and any overlapping courts
- The correct workflow (one of 12 NYC entry workflows)
- Required information still missing before forms can be generated

### 3. Forms and filing packages

The **Forms Library** maps each workflow stage to official NYC court forms. The **Package Builder** assembles a deterministic, downloadable PDF packet when intake is complete and eligibility checks pass.

### 4. Procedural roadmap and guidance

After routing, users see:

- A procedural roadmap (current focus, completed steps, upcoming steps)
- Stage-specific guidance and county notes
- Links to knowledge articles (service of process, answer period, etc.)

### 5. Case lifecycle tracking (divorce)

For matrimonial matters, users can record milestones:

- Forms generated → Filed → Served → Awaiting answer

The platform computes informational deadlines (e.g. answer period after service) and shows the next procedural path (default vs contested track). Users confirm milestones; the system does not infer legal outcomes.

### 6. Dashboard and workspace

Logged-in users see:

- Case progress summary
- Lifecycle checklist for active divorce matters
- Parallel court tracks when divorce overlaps with Family Court issues (custody, support, order of protection)
- Resume links back to the workspace

### 7. Knowledge center and search

Educational articles and searchable content explain procedures, forms, and common NYC family court topics. Content supports the roadmap; it does not override routing.

### 8. AI procedural assistant (bounded)

AI **explains** procedures, forms, and deadlines using content supplied by the platform. AI **does not**:

- Choose court or workflow
- Recommend legal strategy
- Invent forms or deadlines
- Override the Rules Engine

---

## Geographic and matter coverage (current)

| Scope | Detail |
|-------|--------|
| **Geography** | Five NYC counties (Manhattan, Brooklyn, Queens, Bronx, Staten Island) |
| **Supreme Court** | Divorce workflows (uncontested, contested, default, with/without children) |
| **Family Court** | Custody, visitation, child support, order of protection, family offense, paternity, guardianship, adoption |

---

## Typical user journey

```
Start conversation
    → Facts collected (AI assists, rules validate)
    → Court and workflow determined (rules engine)
    → Eligibility checked (residency, county)
    → Required forms identified
    → Filing package downloaded
    → User records milestones (filed, served, etc.)
    → Roadmap and deadlines update
    → Guidance for next procedural stage
```

---

## How decisions are made (trust model)

| Decision | Owner |
|----------|-------|
| Court, workflow, required forms | Rules Engine + workflow definitions |
| Stage progression, packages | Workflow Engine + JSON configuration |
| Deadlines (informational) | Deadline catalog + user-confirmed dates |
| Explanations and conversation | AI Assistant (reads engine output only) |

This separation is intentional: **procedure is deterministic; AI is assistive.**

---

## What CourtFlow is not

- **Not a law firm** — does not provide legal advice or representation
- **Not a strategy tool** — does not tell users what outcome to pursue
- **Not autonomous legal AI** — AI never owns procedural decisions
- **Not e-filing (today)** — users download packages and file with the court themselves; NYSCEF integration is a future phase
- **Not statewide (today)** — NYC five counties only in the current release

---

## Current platform capabilities

| Capability | Status |
|------------|--------|
| 12 NYC workflow definitions | Complete |
| Court routing and overlap detection | Complete |
| AI intake with domain guard | Complete |
| Workspace chat and context panel | Complete |
| Forms catalog and package builder | Complete |
| User dashboard and case summary | Complete |
| Timeline and procedural navigator | Complete |
| Divorce lifecycle milestones | Complete |
| Knowledge center and search | Complete |
| Case persistence (database) | Complete |
| Security, rate limits, audit | Complete |
| NYSCEF e-filing integration | Future (Phase 2) |
| Statewide county expansion | Future (Phase 3) |

---

## Roadmap direction (high level)

| Phase | Focus |
|-------|--------|
| **Phase 1 (MVP)** | NYC intake → routing → forms package → procedural guidance |
| **Phase 2** | Full divorce lifecycle, post-filing tracking, ecosystem content, NYSCEF |
| **Phase 3** | Statewide expansion beyond NYC |

Implementation follows approved plans incrementally. Architecture and workflow definitions remain data-driven and backward compatible.

---

## Stakeholder assurances

1. **Consistency** — The same facts produce the same court, workflow, and forms every time.
2. **Auditability** — Procedural rules live in version-controlled workflow JSON and configuration, not hidden in AI prompts.
3. **Safety boundaries** — Safety-sensitive workflows (order of protection, family offense) are prioritized in routing rules.
4. **Incremental evolution** — New matter types and counties are added through configuration and content, not platform redesign.
5. **User clarity** — Informational disclaimers: guidance is procedural, not legal advice.

---

## Summary

CourtFlow AI is a **procedural workflow platform** that helps self-represented New Yorkers navigate family and matrimonial court with confidence. The platform determines *what procedure applies* through rules; the assistant helps users *understand and complete* that procedure. The system is built to grow through configuration — additional workflows, counties, and lifecycle stages — without replacing its core architecture.

For technical architecture and implementation status, see [project-map.md](./project-map.md) and [plans/README.md](./plans/README.md).
