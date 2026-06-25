# Plan 21 — NY Divorce Ecosystem Lifecycle — Full Test Matrix

**Scope:** Plan 21 (lifecycle model, eligibility, post-filing milestones, dashboard/workspace UI, dual-court map, knowledge base)  
**Environment:** Staging with logged-in user, NYC counties only  
**Core rule under test:** Rules engine owns stages/deadlines/branches — AI never invents them

---

## 1. Automated tests (PHPUnit)

### 1.1 Run commands

```powershell
cd public\wp-content\plugins\prose-core

composer test -- --filter CaseLifecycleServiceTest
composer test -- --filter EligibilityPresenterTest
composer test -- --filter ProceduralRoadmapPresenterTest
composer test -- --filter ProactiveGuidanceTest
composer test -- --filter CourtflowResponseMapperTest
composer test -- --filter UserDashboardRestControllerTest
```

Full regression (recommended before release):

```powershell
composer test
```

### 1.2 Implemented automated cases

| ID | Suite | Test method | Asserts |
|----|-------|-------------|---------|
| A-01 | CaseLifecycleServiceTest | `test_filed_event_advances_stage` | After `forms_generated` + `filed`, `lifecycle_stage` = `served` |
| A-02 | CaseLifecycleServiceTest | `test_service_date_computes_answer_deadline` | After `served` on 2026-06-10, stage = `awaiting_answer`, answer due 2026-06-30 (+20 days) |
| A-03 | CaseLifecycleServiceTest | `test_spouse_no_answer_sets_default_branch` | `spouse_no_answer` → branch = `default_track` |
| A-04 | EligibilityPresenterTest | `test_missing_county_needs_more_info` | Empty facts → `needs_more_info` |
| A-05 | EligibilityPresenterTest | `test_nyc_residency_eligible` | Queens + `1_year_state` → `eligible` |
| A-06 | EligibilityPresenterTest | `test_ineligible_blocks_package` | `residency_qualification` = `ineligible` → `blocks_package()` true |

### 1.3 Recommended automated additions (not yet coded)

| ID | Suite (proposed) | Case | Expected |
|----|------------------|------|----------|
| A-07 | CaseLifecycleServiceTest | `spouse_answered` on uncontested workflow | branch = `contested_track` |
| A-08 | CaseLifecycleServiceTest | `apply_event` served without date | `WP_Error`, code `prose_lifecycle_service_date_required` |
| A-09 | CaseLifecycleServiceTest | `apply_event` unknown event | `WP_Error`, code `prose_lifecycle_invalid_event` |
| A-10 | CaseLifecycleServiceTest | `judgment_entered` then `case_closed` | stage progresses `post_judgment` → `closed` |
| A-11 | CaseLifecycleServiceTest | Non-divorce workflow (e.g. custody) | `build().show` = false |
| A-12 | EligibilityPresenterTest | County = `Albany` | `needs_more_info` (non-NYC) |
| A-13 | EligibilityPresenterTest | DV concern + eligible residency | `eligible` + reason mentions OP |
| A-14 | ProceduralRoadmapPresenterTest | Workflow roadmap + lifecycle overlay | Post-intake milestones replace intake steps when `lifecycle.show` |
| A-15 | ProceduralRoadmapPresenterTest | Fingerprint changes on lifecycle stage change | `resolve_with_change_detection().changed` = true |
| A-16 | UserDashboardRestControllerTest | Dashboard payload includes `case_lifecycle`, `matter_map` | Keys present when divorce conversation exists |

---

## 2. API test cases

**Auth:** Logged-in user with dashboard access; `X-WP-Nonce` for REST.

### 2.1 Session state — lifecycle hydration

| ID | Method | Endpoint | Preconditions | Steps | Expected |
|----|--------|----------|---------------|-------|----------|
| API-01 | GET | `courtflow/v1/sessions/{id}/state` | Divorce intake in progress | Load state | Response includes `eligibility`, `lifecycle`, `matter_map` |
| API-02 | GET | `courtflow/v1/sessions/{id}/state` | Custody-only session | Load state | `lifecycle.show` = false |
| API-03 | GET | `courtflow/v1/sessions/{id}/state` | Intake complete, no events | Load state | `lifecycle.stage` = `forms_ready` or `filed` |

### 2.2 Package generation — forms_generated + eligibility gate

| ID | Method | Endpoint | Preconditions | Steps | Expected |
|----|--------|----------|---------------|-------|----------|
| API-04 | POST | `courtflow/v1/sessions/{id}/documents` | Intake 100%, eligible | Generate package | 200; `lifecycle` includes `forms_generated` event; downloadable PDF |
| API-05 | POST | `courtflow/v1/sessions/{id}/documents` | `residency_qualification` = ineligible | Generate package | 422; message explains residency; no download |
| API-06 | POST | `courtflow/v1/sessions/{id}/documents` | Intake incomplete | Generate package | 422; blocked message |

### 2.3 Lifecycle milestone recording

| ID | Method | Endpoint | Body | Expected |
|----|--------|----------|------|----------|
| API-07 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "filed" }` | 200; `lifecycle.stage` advances; `roadmap_changed` may be true |
| API-08 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "served", "date": "2026-06-10" }` | 200; `lifecycle.deadlines[0].due_date` = `2026-06-30` |
| API-09 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "served" }` (no date) | 400; service date required |
| API-10 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "invalid_event" }` | 400; unknown event |
| API-11 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "spouse_no_answer" }` | 200; branch = `default_track`; suggested workflow `default_divorce_nyc` |
| API-12 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "spouse_answered" }` | 200; branch = `contested_track` |
| API-13 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "settlement_reached" }` | 200; stage moves toward judgment |
| API-14 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "judgment_entered" }` | 200; stage = `post_judgment` |
| API-15 | PATCH | `courtflow/v1/sessions/{id}/lifecycle` | `{ "event": "case_closed" }` | 200; stage = `closed` |

### 2.4 Dashboard API

| ID | Method | Endpoint | Preconditions | Expected |
|----|--------|----------|---------------|----------|
| API-16 | GET | `prose/v1/me/dashboard` | Logged in, divorce conversation saved | `case_lifecycle.show` = true; `milestones` array present |
| API-17 | GET | `prose/v1/me/dashboard` | Divorce + children + custody_dispute | `matter_map.show` = true; Supreme + Family Court tracks |
| API-18 | GET | `prose/v1/me/dashboard` | No conversations | `case_lifecycle.show` = false |
| API-19 | GET | `prose/v1/me/dashboard` | After service recorded | `case_lifecycle.deadlines` non-empty |
| API-20 | DELETE | `prose/v1/me/conversations/session/{session_id}` | Own conversation | 200; conversation removed from dashboard list |

### 2.5 Security / ownership

| ID | Case | Expected |
|----|------|----------|
| API-21 | PATCH lifecycle on another user's session | 403 `prose_forbidden` |
| API-22 | GET dashboard when logged out | 401 |
| API-23 | PATCH lifecycle on expired/invalid session id | 404 |

---

## 3. Manual UAT — Intake & eligibility (Phase C)

| ID | Scenario | Steps | Pass criteria |
|----|----------|-------|---------------|
| UAT-01 | New divorce — eligibility first | Start chat: "I want a divorce" | AI asks county; roadmap shows **Eligibility** / residency step |
| UAT-02 | Collect marriage location | Provide marriage date + location when asked | Facts appear in case summary; intake roadmap step **Basic marriage information** completes |
| UAT-03 | Residency qualification | Answer residency basis (e.g. 1-year NY) | Eligibility status moves toward `eligible` in API/state |
| UAT-04 | Missing residency | Provide county only, skip residency | Eligibility = `needs_more_info`; package blocked or warned per rules |
| UAT-05 | Likely ineligible | Set residency to not met / ineligible | Package generation returns 422 with plain-language reason |
| UAT-06 | DV concern flag | Indicate domestic violence concerns during intake | Eligibility reason mentions OP; matter map may show Family Court OP track |
| UAT-07 | Prior court cases | Mention existing orders | `prior_court_cases` collected; no AI-invented case numbers |
| UAT-08 | Uncontested no children | Complete all required fields | Workflow = `uncontested_divorce_no_children_nyc`; 100% intake |
| UAT-09 | Uncontested with children | Complete with child facts | Workflow = `uncontested_divorce_children_nyc` |
| UAT-10 | Contested path | Spouse disagrees | Workflow = `contested_divorce_nyc` |
| UAT-11 | All 5 NYC counties | Repeat UAT-08 for Manhattan, Brooklyn, Queens, Bronx, Staten Island | County badge correct; package generates |

---

## 4. Manual UAT — Post-filing lifecycle (Phases A, D, E)

### 4.1 Happy path — uncontested divorce

| ID | Step | User action | UI / API check |
|----|------|-------------|----------------|
| LC-01 | Forms ready | Complete intake 100% | Workspace lifecycle: current = **Forms** or **Filed** |
| LC-02 | Generate package | Click **Generate Filing Package** | Download works; lifecycle records `forms_generated` |
| LC-03 | Filed | Click **I filed my documents** | Milestone **Filed** complete; current = **Served** |
| LC-04 | Served | Click **My spouse was served**; enter `2026-06-10` | Milestone **Served** complete; answer deadline shown (≈ +20 days) |
| LC-05 | Awaiting answer | Observe workspace + dashboard | Both show **Waiting for answer** / deadline |
| LC-06 | No answer | Click **Spouse did not answer in time** | Branch = default track (informational); roadmap updates |
| LC-07 | Judgment | Click **Default judgment entered** (or judgment action) | Stage advances toward judgment |
| LC-08 | Closed | Click **Mark case closed** | Stage = **Closed**; dashboard lifecycle reflects closed |

### 4.2 Happy path — contested divorce

| ID | Step | User action | Expected |
|----|------|-------------|----------|
| LC-09 | Contested intake | Complete contested workflow intake | Lifecycle visible after intake complete |
| LC-10 | Through service | Forms → filed → served (with date) | Answer deadline visible |
| LC-11 | Spouse answered | Click **Spouse filed an answer** | Branch = contested; discovery/settlement actions appear |
| LC-12 | Discovery | Click **Discovery started** | Stage = discovery; roadmap focus updates |
| LC-13 | Settlement | Click **We reached a settlement** | Stage moves toward judgment |

### 4.3 Roadmap integration

| ID | Case | Pass criteria |
|----|------|---------------|
| LC-14 | Roadmap after each lifecycle event | Roadmap card refreshes in-place (no duplicate chat cards) |
| LC-15 | AI procedural question mid-lifecycle | Ask "How long does my spouse have to answer?" — AI explains using system guidance; does not invent dates |
| LC-16 | Dashboard summary only | Dashboard shows compact stage + deadline; **not** full discovery/settlement step lists |
| LC-17 | Resume session | Log out/in; open `?conversation_id={uuid}` | Lifecycle events and stage restored from conversation context |

---

## 5. Manual UAT — Dashboard (Phase B)

| ID | Widget | Steps | Pass criteria |
|----|--------|-------|---------------|
| DB-01 | Case Progress | Open dashboard with active divorce | Shows stage, %, confidence, next step, **Continue Case** |
| DB-02 | Case Lifecycle | Same session | Milestone chips: completed / current / upcoming |
| DB-03 | Answer deadline | After service recorded | Deadline line visible on dashboard |
| DB-04 | Update milestones CTA | Click **Update milestones** | Opens workspace with same session |
| DB-05 | No divorce case | New user, no conversations | Lifecycle widget shows empty state |
| DB-06 | Courts Involved | Divorce + children + custody dispute | Two tracks: Supreme Court divorce + Family Court custody |
| DB-07 | Courts Involved — DV | `domestic_violence_concerns` = true | OP track appears on matter map |
| DB-08 | Remove conversation | Delete conversation from list | Lifecycle widget updates after reload |

---

## 6. Manual UAT — Workspace UI (Phase B)

| ID | Component | Pass criteria |
|----|-----------|---------------|
| WS-01 | Case milestones card | Visible when `lifecycle.show`; hidden for non-divorce |
| WS-02 | Milestone list | Completed = green marker; current = blue; upcoming = gray |
| WS-03 | Deadline block | Yellow info box after service with date + disclaimer language |
| WS-04 | Action buttons | Only actions valid for current stage shown |
| WS-05 | Service date prompt | Cancel prompt → no API call; valid date → PATCH succeeds |
| WS-06 | Roadmap card | Still shows at top; merges lifecycle overlay post-intake |
| WS-07 | Mobile | Lifecycle card readable; buttons tappable on narrow viewport |

---

## 7. Dual-court & knowledge base (Phases F, G)

| ID | Case | Pass criteria |
|----|------|---------------|
| KB-01 | Knowledge: service of process | Article exists at `docs/knowledge-center/service-of-process-nyc-divorce.md` |
| KB-02 | Knowledge: answer period | Article exists at `docs/knowledge-center/answer-period-nyc-divorce.md` |
| KB-03 | Knowledge: property/debt | Article exists at `docs/knowledge-center/property-debt-nyc-divorce.md` |
| KB-04 | Matter map — divorce only | Single Supreme Court track |
| KB-05 | Matter map — divorce + support | Supreme + Family Court child support track |
| KB-06 | Post-judgment CTA (future) | Closed case links to modification/enforcement workflows via rules (no AI suggestion) |

---

## 8. Regression — must not break

| ID | Area | Test | Pass |
|----|------|------|------|
| REG-01 | AI roadmap | AI chat reply contains **no** markdown roadmap tables/lists | Prose only |
| REG-02 | Roadmap fingerprint | Unchanged facts + same lifecycle → `roadmap_changed` false on message | No UI flicker |
| REG-03 | Material change | County or spouse_agrees changes → roadmap refreshes | Card updates |
| REG-04 | 12 workflows | MVP matrix workflows still route correctly | IntakeAgentTest green |
| REG-05 | Package builder | Uncontested no-children packet forms match workflow JSON | Non-empty PDF |
| REG-06 | Conversation delete | DELETE conversation still works | Dashboard list updates |

---

## 9. Negative & edge cases

| ID | Case | Expected behavior |
|----|------|-------------------|
| NEG-01 | Record `filed` before intake complete | PATCH allowed but stage logic consistent; or 422 if gated (document actual) |
| NEG-02 | Double-click lifecycle button | Idempotent or duplicate events handled; UI does not break |
| NEG-03 | Service date in future | Accepted but deadline calculated from given date |
| NEG-04 | Invalid date format in prompt | API rejects or normalizes; user sees error |
| NEG-05 | `spouse_answered` then `spouse_no_answer` | Branch resolves to contested (answered wins) |
| NEG-06 | Session expired (30-day transient) | 404; user starts new session |
| NEG-07 | Guest user lifecycle | PATCH works same-origin; no DB context until login |

---

## 10. Test data templates

### 10.1 Uncontested divorce — no children (Queens)

```
Issue: divorce
County: Queens
marriage_date: 2010-06-01
marriage_location: Queens, NY
residency_qualification: 1_year_state
spouse_agrees: true
has_minor_children: false
marital_property_resolved: true
```

### 10.2 Contested divorce — with children (Kings)

```
Issue: divorce
County: Kings
marriage_date: 2008-03-15
marriage_location: Brooklyn, NY
residency_qualification: 2_year_state
spouse_agrees: false
has_minor_children: true
child_count: 2
custody_dispute: true
support_dispute: true
assets: [home, retirement]
debts: [mortgage, credit cards]
income: 75000
```

### 10.3 Lifecycle event sequence (API)

```json
1. POST /documents          → forms_generated (auto)
2. PATCH { "event": "filed" }
3. PATCH { "event": "served", "date": "2026-06-10" }
4. PATCH { "event": "spouse_no_answer" }   OR   { "event": "spouse_answered" }
5. PATCH { "event": "judgment_entered" }
6. PATCH { "event": "case_closed" }
```

---

## 11. Sign-off checklist

| Gate | Owner | Status |
|------|-------|--------|
| Automated A-01–A-06 pass | Dev | [ ] |
| API API-01–API-20 pass | QA | [ ] |
| UAT LC-01–LC-17 pass (one county minimum) | QA | [ ] |
| Dashboard DB-01–DB-08 pass | QA | [ ] |
| Regression REG-01–REG-06 pass | QA | [ ] |
| No P0/P1 lifecycle bugs open | PM | [ ] |

---

## 12. Known limitations (expected at Plan 21)

- Lifecycle milestones are **user-confirmed**, not inferred from uploaded court documents (Plan 11 future).
- Answer deadline uses **calendar +20 days** from service date — not court-day calculation or county-specific variations.
- NYSCEF docket sync not included (Plan 19).
- AI does not recommend legal strategy (default vs contested is informational only).
