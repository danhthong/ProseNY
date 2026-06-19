# MVP Test Matrix

Gate checklist exported from Plan 18. Run automated suites in CI; complete manual UAT on staging before launch.

## Automated (PHPUnit)

| Suite | Location | Covers |
|-------|----------|--------|
| IntakeAgentTest | `modules/intake/tests/` | All 12 NYC workflows incl. `default_divorce_nyc` |
| DomainScopeGuardTest | `modules/ai-intake/tests/` | Entry phrase domain guard |
| AiIntakeInterpreterTest | `modules/ai-intake/tests/` | Stub provider paths |
| RoutingEngineTest | `modules/routing/tests/` | Overlap + default divorce routing |
| FormsCatalogSearchTest | `tests/unit/` | Form code search + workflow coverage |
| GuidanceEngineTest | `modules/guidance/tests/` | Procedural guidance + county notes |

**Command:** `cd public/wp-content/plugins/prose-core && composer test`

## Manual UAT (per workflow)

For each workflow:

1. Start from prompt chip or free text
2. Answer required fields (county + workflow-specific)
3. Verify workflow key, court, completion 100%
4. Download package; confirm forms list
5. Ask “what happens next”; verify procedural response

### Workflows

- [ ] uncontested_divorce_no_children_nyc
- [ ] uncontested_divorce_children_nyc
- [ ] contested_divorce_nyc
- [ ] default_divorce_nyc
- [ ] custody_nyc
- [ ] visitation_nyc
- [ ] child_support_nyc
- [ ] family_offense_nyc
- [ ] order_of_protection_nyc
- [ ] paternity_nyc
- [ ] guardianship_nyc
- [ ] adoption_nyc

## Cross-cutting scenarios

- [ ] Divorce + children + Kings County
- [ ] Active divorce + custody routing_rules
- [ ] Matter switch mid-intake (support → divorce)
- [ ] Domain blocked: unrelated topic
- [ ] AI provider down → deterministic fallback
- [ ] Mobile viewport workspace + homepage

## Success metrics (baseline at launch)

| Metric | Target (MVP) | How measured |
|--------|--------------|--------------|
| Workflow completion rate | Track baseline | intake_complete / sessions started |
| Procedural accuracy | 100% on test matrix | automated + manual |
| Package download rate | Track baseline | downloads / intake_complete |
| AI escalation rate | < 10% | needs_review / turns |

## Launch gate

- [ ] Plans 01–02 complete
- [ ] Plans 13–18 complete
- [ ] Test matrix 100% pass on staging
- [ ] Known limitations documented
- [ ] No P0/P1 bugs open
