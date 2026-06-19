# CourtFlow Routing Engine

Standalone issue resolution and court routing engine for NYC Supreme Court and Family Court matters.

The engine converts free-form user statements into workflow selections using the Workflow Repository at `docs/workflows/` as the single source of truth.

## Pipeline

```
User Text
  → Intent Detection (signals)
  → Issue Resolution (issue_type)
  → Court Resolution (court)
  → Workflow Resolution (workflow)
  → Missing Information Detection (missing_fields)
```

## Architecture

```
modules/routing/
├── class-routing-engine.php      # Orchestrator
├── class-routing-result.php      # Routing result DTO
├── class-case-profile.php        # Session state DTO
├── class-fact-store.php          # Fact container
├── class-workflow-catalog.php    # Repository loader
├── class-signal-lexicon.php      # NL cue → fact tokens
├── resolver/                     # Pipeline steps 1–4
├── matcher/                        # Trigger, rule, priority matching
├── validators/                   # Ambiguity / missing fields
└── tests/                        # PHPUnit suite
```

The engine is independent from the Intake Chat Agent, Package Builder, and the legacy DB-backed `ProSe\Core\Forms\Engine\Routing_Service`.

## Workflow Repository

All workflow definitions are loaded from:

```
docs/workflows/divorce/*.json
docs/workflows/family_court/*.json
```

The engine reads:

- `triggers` — intent detection and workflow matching
- `issue_type` — issue classification
- `court` — court resolution
- `routing_rules` — NYC override rules (e.g. active divorce redirects custody/support)
- `routing_priority` / `intake_priority` — tie-breaking
- `required_fields` — intake question keys
- `required_forms` — form preview metadata

No workflow definitions are duplicated in PHP.

## Routing Result

```json
{
  "issue": "custody",
  "court": "family_court",
  "workflow": "custody_nyc",
  "confidence": 0.96,
  "candidate_workflows": [],
  "missing_fields": [],
  "required_form_codes": ["GF-17", "GF-40", "GF-41"]
}
```

When routing is ambiguous:

```json
{
  "issue": "divorce",
  "court": "supreme_court",
  "workflow": null,
  "confidence": 0.0,
  "candidate_workflows": [
    "uncontested_divorce_no_children_nyc",
    "uncontested_divorce_children_nyc",
    "contested_divorce_nyc"
  ],
  "missing_fields": ["children", "spouse_agrees"],
  "required_form_codes": []
}
```

## Case Profile

`Case_Profile` is the canonical session-state object for multi-turn intake:

```json
{
  "issue": null,
  "court": null,
  "workflow": null,
  "workflow_confidence": 0,
  "facts": {},
  "missing_fields": [],
  "candidate_workflows": [],
  "progress": 0
}
```

The Intake Agent should persist and resume `Case_Profile` across turns. After each routing call, the engine writes resolved fields back onto the profile.

## Fact Store

`Fact_Store` holds structured facts separately from routing metadata:

```php
$store = Fact_Store::from_array( array( 'children' => true ) );
$store->merge( array( 'spouse_agrees' => true ) ); // last write wins
$facts = $store->export();
```

The routing engine consumes facts via `Case_Profile → Fact_Store`. Callers can serialize facts with `export()` and restore with `from_array()` for session persistence.

## Usage

### MVP — text + facts array

```php
use ProSe\Core\Routing\Routing_Engine;

$engine = new Routing_Engine();
$result = $engine->route( 'I want a divorce and we have two children.' );
```

### Session-aware — Case Profile

```php
use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Routing_Engine;

$profile = Case_Profile::from_array( $saved_session );
$engine  = new Routing_Engine();
$result  = $engine->route_profile( 'We both agree to the divorce.', $profile );

// $profile now contains updated issue/court/workflow/missing_fields
$session = $profile->to_array();
```

## Form Preview Support

When a workflow is resolved, `required_form_codes` is populated from the workflow's `required_forms` metadata. This is informational only — the routing engine does not load, fill, or merge PDFs.

The Package Builder will later consume:

```
workflow + required_form_codes
```

to assemble document packages.

## NYC Divorce Override

When divorce context is active, custody and child support matters remain inside the divorce workflow in Supreme Court. Family Court workflows expose `routing_rules` such as:

```json
{ "condition": "active_divorce=true", "workflow": "uncontested_divorce_children_nyc" }
```

The engine evaluates these rules before selecting a standalone Family Court workflow.

## Testing

See [tests/README.md](../../tests/README.md) for setup and commands.

```bash
cd public/wp-content/plugins/prose-core
composer install
composer test
composer test:ai-intake   # domain scope guard, AI intake only
composer test:intake      # deterministic intake agent
composer test:routing
```

Routing tests live in `modules/routing/tests/` and cover all 11 issue types, divorce ambiguity, NYC override, Case Profile, and Fact Store behavior.

## Future Consumers

| Module | Consumes |
|--------|----------|
| Intake Chat Agent | `Case_Profile`, `missing_fields`, `candidate_workflows` |
| Session Persistence | `Case_Profile::to_array()`, `Fact_Store::export()` |
| Package Builder | `workflow`, `required_form_codes` |
| Workflow Progress | `Case_Profile::progress()` (placeholder) |
