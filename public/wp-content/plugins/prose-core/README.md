# CourtFlow AI (Prose Core)

WordPress plugin — procedural workflow engine for NY divorce and family court.

## Requirements

- PHP 8.3+
- WordPress 6.0+
- Composer
- `pdftk` (for PDF autofill in production)
- OpenAI API key

## Setup

```bash
cd wp-content/plugins/prose-core
composer install
```

Configure in **wp-admin → CourtFlow → Settings**, or in `wp-config.php`:

```php
define( 'COURTFLOW_OPENAI_API_KEY', 'sk-...' );
define( 'COURTFLOW_PII_SECRET', 'random-32-byte-secret' );
```

Activate the plugin, then:

```bash
wp courtflow migrate
wp courtflow seed
```

## Theme

Use the **prose-app** theme with the **CourtFlow Workspace** page template and `courtflow/workspace` block.

## Architecture

- **Rules Engine** — deterministic JsonLogic evaluator (`app/Rules/`)
- **Workflow Engine** — state machine (`app/Workflows/`)
- **AI Agents** — intake, explanation, forms, validation, PDF (`app/AI/Agents/`)
- **REST API** — `courtflow/v1` namespace (`app/API/REST/`)

AI never decides workflows. The rules engine does.
