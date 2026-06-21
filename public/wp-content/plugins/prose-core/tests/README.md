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
composer test:procedural
```

Equivalent PHPUnit commands:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite ai-intake
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite intake
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite routing
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite procedural
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite guidance
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite packet
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite packagebuilder
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite users
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
.\bin\run-tests.ps1 -Suite unit
.\bin\run-tests.ps1 -Suite procedural
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
| `documents` | `modules/documents/tests/` |
| `forms` | `modules/forms/tests/` |
| `users` | `modules/users/tests/` |

Manual PDF / overlay scripts live in `tests/manual/` and are **not** part of PHPUnit.

## Configuration

- Config file: `phpunit.xml.dist`
- Bootstrap: `tests/bootstrap.php` (WordPress stubs + plugin autoloader)

## Temp directories (Windows)

Tests that need writable temp dirs should use **`prose_test_temp_dir()`** and clean up with **`prose_test_remove_tree()`** from `tests/bootstrap.php`.

Do **not** use `sys_get_temp_dir()` / `C:\Windows\TEMP` for test fixtures — teardown can fail with **Access is denied** when PHPUnit runs `scandir()` on Windows system temp.

Artifact dirs are created under `tests/tmp/` (safe to delete locally; not committed).

## Troubleshooting

**`php` not found** — Install PHP and add it to your PATH, or use the full path to `php.exe`.

**`composer` not found** — Install Composer globally or use `php composer.phar install`.

**Class `PHPUnit\Framework\TestCase` not found** — Run `composer install` in this directory first.

**`scandir(...): Access is denied` on Windows** — Update the test to use `prose_test_temp_dir()` / `prose_test_remove_tree()` instead of `sys_get_temp_dir()`.
