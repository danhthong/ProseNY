# CourtFlow AI — Project Map

Quick navigation for the codebase and implementation plans. Read `AGENTS.md` first for product rules.

## Product summary

CourtFlow AI is a **procedural navigation platform** for NYC Divorce and Family Court — not a chatbot, not a law firm. Rules Engine decides; AI assists.

## Documentation index

| Area | Path |
|------|------|
| **Platform architecture** | [docs/architecture/README.md](./architecture/README.md) |
| Guiding principles | [docs/architecture/guiding-principles.md](./architecture/guiding-principles.md) |
| Architecture decisions (ADR) | [docs/adr/README.md](./adr/README.md) |
| RFC process | [docs/rfc/README.md](./rfc/README.md) |
| **Implementation plans** | [docs/plans/README.md](./plans/README.md) |
| Reference specifications | [docs/reference/README.md](./reference/README.md) |
| Requirements (source) | [docs/requires/](./requires/) |
| UI specifications | [docs/ui/](./ui/) |
| Agent / dev rules | [AGENTS.md](../AGENTS.md) |

## Codebase map

### Plugin: `public/wp-content/plugins/prose-core`

| Module | Path | Purpose |
|--------|------|---------|
| Routing | `modules/routing/` | Court, issue, workflow resolution |
| Intake (deterministic) | `modules/intake/` | Intake agent, REST `/intake`, chat widget |
| AI Intake | `modules/ai-intake/` | Interpreter, domain guard, OpenAI |
| Forms | `modules/forms/` | Catalog, engine, PDF, DB seeders |
| Package Builder | `modules/packagebuilder/` | Merged PDFs, preview |
| Assembly | `modules/assembly/` | Document assembly / fill |
| Procedural | `modules/procedural/` | Procedural navigator |
| Guidance | `modules/guidance/` | Stage guidance content |
| Packet | `modules/packet/` | Packet handling |

### Procedural source of truth

| Asset | Path |
|-------|------|
| Workflows (12 NYC) | `prose-core/docs/workflows/` |
| Forms catalog | `prose-core/docs/forms/` |
| Validation | `prose-core/bin/validate-workflows.php` |
| PHPUnit tests | `prose-core/tests/README.md` |

### Theme: `public/wp-content/themes/prose-app`

| Area | Path |
|------|------|
| Workspace UI | `blocks/courtflow-workspace/`, `build/courtflow.js` |
| Intake chat block | `blocks/courtflow-intake-chat/` |
| Context panel | `template-parts/courtflow-context-panel.php` |
| Step catalog | `inc/courtflow/steps.php` |
| Homepage | `front-page.php` + `[prose_intake_chat]` |

## API surface (current)

| Endpoint | Status | Used by |
|----------|--------|---------|
| `POST /prose/v1/intake/interpret` | ✅ | Homepage chat widget |
| `POST /prose/v1/intake` | ✅ | Deterministic fallback |
| `POST /prose/v1/case/actions` | ✅ | Case summary panel |
| `POST courtflow/v1/sessions/*/messages` | ✅ Adapter → `AI_Intake_Service` | Workspace chat |

## Plan execution order (summary)

1. **P0 blockers:** [01 Domain scope](./plans/01-intake-domain-scope-and-entry-points.md) → [02 Workspace API](./plans/02-workspace-chat-api-unification.md)
2. **MVP core:** [03–08](./plans/README.md#recommended-execution-order)
3. **Launch gate:** [18 MVP QA](./plans/18-mvp-acceptance-and-qa.md)
4. **Post-MVP:** Plans 09–17
5. **Future:** Plans 19–20

## Current status snapshot

| Component | Status |
|-----------|--------|
| Workflow JSON repository | ✅ Complete |
| Routing engine | 🟡 Functional; overlap UX incomplete |
| AI intake interpreter | 🟡 Works; domain guard aligned with 12 workflows (Plan 01) |
| Homepage intake chat | 🟡 Works via `/intake/interpret` |
| Workspace intake chat | 🔴 Broken (missing API) |
| Forms catalog | 🟡 Large catalog; mapping incomplete |
| Package download | 🟡 Partial |
| Timeline / document intelligence | 🔴 Not user-facing |
| Admin hub | 🟡 Fragmented admin pages |

Legend: ✅ Done · 🟡 Partial · 🔴 Not started / blocked

---

*Last updated: architecture documentation evolution (2026-06). Update when plans are approved or completed.*
