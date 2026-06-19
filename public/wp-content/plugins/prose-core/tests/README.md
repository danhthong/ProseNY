# ProSe Core — PHPUnit Tests

Unit and integration tests for the plugin. Tests run **outside WordPress** using stubs in `bootstrap.php`.

## Requirements

- PHP **8.0+** with `json` and `mbstring` extensions
- [Composer](https://getcomposer.org/)

## One-time setup

From the plugin root (`public/wp-content/plugins/prose-core`):

```bash
composer install
```

This installs PHPUnit into `vendor/` (gitignored).

## Run all tests

```bash
composer test
```

Or directly:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist
```

## Run a module suite

```bash
composer test:ai-intake
composer test:intake
composer test:routing
composer test:unit
```

Equivalent PHPUnit commands:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite ai-intake
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite intake
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite routing
```

## Run one test class or method

```bash
php vendor/bin/phpunit -c phpunit.xml.dist modules/ai-intake/tests/DomainScopeGuardTest.php
php vendor/bin/phpunit -c phpunit.xml.dist --filter test_allows_order_of_protection_message
```

## Windows (PowerShell)

```powershell
cd public\wp-content\plugins\prose-core
composer install
composer test
```

Or use the helper script:

```powershell
.\bin\run-tests.ps1
.\bin\run-tests.ps1 -Suite ai-intake
.\bin\run-tests.ps1 -Filter test_allows_order_of_protection_message
```

## Test layout

| Suite | Directory |
|-------|-----------|
| `unit` | `tests/unit/` |
| `ai-intake` | `modules/ai-intake/tests/` |
| `intake` | `modules/intake/tests/` |
| `routing` | `modules/routing/tests/` |
| `packagebuilder` | `modules/packagebuilder/tests/` |
| `assembly` | `modules/assembly/tests/` |
| `procedural` | `modules/procedural/tests/` |
| `packet` | `modules/packet/tests/` |
| `guidance` | `modules/guidance/tests/` |

Manual PDF / overlay scripts live in `tests/manual/` and are **not** part of PHPUnit.

## Configuration

- Config file: `phpunit.xml.dist`
- Bootstrap: `tests/bootstrap.php` (WordPress stubs + plugin autoloader)

## Troubleshooting

**`php` not found** — Install PHP and add it to your PATH, or use the full path to `php.exe`.

**`composer` not found** — Install Composer globally or use `php composer.phar install`.

**Class `PHPUnit\Framework\TestCase` not found** — Run `composer install` in this directory first.
