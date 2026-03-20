# Design: Convert to Distributable Composer Package

**Date:** 2026-03-20
**Status:** Approved
**Goal:** Transform the current three-module Magento 2 integration into a single installable Composer package (`artlounge/magento2-bento`) that can be shared on GitHub and installed via `composer require`.

## Decisions

- **Single metapackage** — one `composer require` installs all three modules
- **Package name:** `artlounge/magento2-bento`
- **Hard dependency on `aligent/async-events ^3.0`** — no fallback to native queues
- **Keep `ArtLounge` vendor namespace** — no renaming of PHP namespaces, module names, or config paths
- **Internal docs preserved locally** in `docs/internal/` (gitignored), not deleted
- **`PLUGIN_ARCHITECTURE.md` stays public** — moved to `docs/architecture.md`
- **Tests in repo but export-ignored** — visible on GitHub, excluded from Composer dist
- **Package type is `library`** — not `magento2-module`, because we ship three modules in one package; the `registration.php` files autoload entries handle Magento module discovery (see rationale below)

## Target Directory Structure

```
artlounge-magento2-bento/              (GitHub repo root = package root)
├── composer.json                       (single package, type: library)
├── README.md                           (public: install, configure, usage, events reference)
├── CONTRIBUTING.md                     (dev setup, running tests, PR guidelines)
├── LICENSE                             (MIT)
├── CHANGELOG.md                        (existing, carried over as-is)
├── .gitignore                          (vendor/, docs/internal/, .phpunit.result.cache, etc.)
├── .gitattributes                      (export-ignore tests, docs, .github, dev files)
├── phpunit.xml                         (updated paths from modules/ to src/)
├── .github/
│   └── workflows/
│       └── ci.yml                      (PHPUnit + PHP linting on push/PR)
├── src/
│   ├── BentoCore/                      (from modules/ArtLounge/BentoCore/)
│   │   ├── registration.php
│   │   ├── etc/
│   │   ├── Api/
│   │   ├── Model/
│   │   ├── Block/
│   │   ├── Controller/
│   │   ├── view/
│   │   └── Test/
│   ├── BentoEvents/                    (from modules/ArtLounge/BentoEvents/)
│   │   ├── registration.php
│   │   ├── etc/
│   │   ├── Observer/
│   │   ├── Service/
│   │   ├── Model/
│   │   ├── Console/
│   │   ├── Cron/
│   │   ├── Controller/
│   │   ├── Setup/
│   │   ├── view/
│   │   └── Test/
│   └── BentoTracking/                  (from modules/ArtLounge/BentoTracking/)
│       ├── registration.php
│       ├── etc/
│       ├── ViewModel/
│       ├── CustomerData/
│       ├── view/
│       └── Test/
├── tests/                              (test infrastructure, export-ignored)
│   ├── bootstrap.php
│   └── Stubs/
│       ├── Aligent/
│       ├── Magento/
│       ├── Psr/
│       ├── Ramsey/
│       ├── Zend/
│       ├── translation.php
│       └── Zend_Db_Expr.php
└── docs/
    ├── architecture.md                 (public: from PLUGIN_ARCHITECTURE.md)
    └── internal/                       (gitignored: private docs)
        ├── CLAUDE.md
        ├── DEVELOPER_HANDOFF.md
        ├── SERVER_TEAM_INSTRUCTIONS.md
        ├── bento-magento-integration-complete-guide.md
        ├── 01-TECHNICAL-SPECIFICATION.md
        ├── 02-INSTALLATION-GUIDE.md
        ├── 03-ADMIN-CONFIGURATION-REFERENCE.md
        └── 05-TESTING-GUIDE.md
```

## Why `type: library` Instead of `magento2-module`

Magento's Composer installer (`magento/magento-composer-installer`) treats `type: magento2-module` packages as containing a single module and tries to copy/symlink the entire package into `app/code/`. Since we ship three modules under `src/`, this would break the expected layout.

Using `type: library` means Composer installs the package into `vendor/artlounge/magento2-bento/` like any other library. The three `registration.php` files — loaded via Composer's `autoload.files` — call `ComponentRegistrar::register()` with `__DIR__`, which tells Magento exactly where each module lives. This is the standard pattern for multi-module Composer packages in Magento 2.

**Note on `autoload-dev` safety:** The dev autoload maps `Magento\\` to `tests/Stubs/Magento/`. This is safe because Composer only loads `autoload-dev` entries for the root package, never for dependencies. When someone `composer require`s this package into their Magento store, the stub mappings are completely ignored.

## composer.json (Root)

Replaces the current root `composer.json` and all three module-level `composer.json` files.

```json
{
    "name": "artlounge/magento2-bento",
    "description": "Magento 2 integration with Bento email marketing platform via async event queues",
    "type": "library",
    "license": "MIT",
    "version": "1.0.0",
    "keywords": ["magento2", "bento", "email", "marketing", "async", "events", "queue"],
    "homepage": "https://github.com/artlounge/magento2-bento",
    "require": {
        "php": ">=8.1",
        "magento/framework": "*",
        "magento/module-store": "*",
        "magento/module-config": "*",
        "magento/module-backend": "*",
        "magento/module-sales": "*",
        "magento/module-customer": "*",
        "magento/module-newsletter": "*",
        "magento/module-quote": "*",
        "magento/module-catalog": "*",
        "magento/module-checkout": "*",
        "aligent/async-events": "^3.0",
        "ramsey/uuid": "^4.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.5",
        "mockery/mockery": "^1.6",
        "symfony/console": "^5.4 || ^6.0 || ^7.0"
    },
    "autoload": {
        "files": [
            "src/BentoCore/registration.php",
            "src/BentoEvents/registration.php",
            "src/BentoTracking/registration.php"
        ],
        "psr-4": {
            "ArtLounge\\BentoCore\\": "src/BentoCore/",
            "ArtLounge\\BentoEvents\\": "src/BentoEvents/",
            "ArtLounge\\BentoTracking\\": "src/BentoTracking/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/",
            "Aligent\\AsyncEvents\\": "tests/Stubs/Aligent/AsyncEvents/",
            "Magento\\": "tests/Stubs/Magento/",
            "Ramsey\\Uuid\\": "tests/Stubs/Ramsey/Uuid/",
            "Psr\\Log\\": "tests/Stubs/Psr/Log/"
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

## .gitattributes

Export-ignore rules exclude tests, docs, and dev files from Composer `--prefer-dist` downloads.
Module-level `Test/` directories are also excluded so end users don't get test files.

```
/tests                      export-ignore
/docs                       export-ignore
/.github                    export-ignore
/src/BentoCore/Test         export-ignore
/src/BentoEvents/Test       export-ignore
/src/BentoTracking/Test     export-ignore
CONTRIBUTING.md             export-ignore
phpunit.xml                 export-ignore
.phpunit.result.cache       export-ignore
```

## .gitignore

```
/vendor/
/build/
/docs/internal/
/.claude/
/.cursor/
.phpunit.result.cache
composer.lock
```

## phpunit.xml (Updated)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    colors="true"
    stopOnFailure="false"
    failOnRisky="true"
    failOnWarning="true"
>
    <testsuites>
        <testsuite name="ArtLounge Bento Unit Tests">
            <directory>src/BentoCore/Test/Unit</directory>
            <directory>src/BentoEvents/Test/Unit</directory>
            <directory>src/BentoTracking/Test/Unit</directory>
        </testsuite>
        <testsuite name="ArtLounge Bento Integration Tests">
            <directory>src/BentoCore/Test/Integration</directory>
            <directory>src/BentoEvents/Test/Integration</directory>
        </testsuite>
    </testsuites>

    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">src/BentoCore</directory>
            <directory suffix=".php">src/BentoEvents</directory>
            <directory suffix=".php">src/BentoTracking</directory>
        </include>
        <exclude>
            <directory>src/BentoCore/Test</directory>
            <directory>src/BentoEvents/Test</directory>
            <directory>src/BentoTracking/Test</directory>
            <file>src/BentoCore/registration.php</file>
            <file>src/BentoEvents/registration.php</file>
            <file>src/BentoTracking/registration.php</file>
        </exclude>
        <report>
            <text outputFile="php://stdout" showOnlySummary="false"/>
            <html outputDirectory="build/coverage"/>
        </report>
    </coverage>

    <php>
        <ini name="error_reporting" value="-1"/>
    </php>
</phpunit>
```

## File Operations Summary

### Moves
| From | To |
|------|----|
| `modules/ArtLounge/BentoCore/*` | `src/BentoCore/*` |
| `modules/ArtLounge/BentoEvents/*` | `src/BentoEvents/*` |
| `modules/ArtLounge/BentoTracking/*` | `src/BentoTracking/*` |
| `PLUGIN_ARCHITECTURE.md` | `docs/architecture.md` |
| `CLAUDE.md` | `docs/internal/CLAUDE.md` |
| `DEVELOPER_HANDOFF.md` | `docs/internal/DEVELOPER_HANDOFF.md` |
| `SERVER_TEAM_INSTRUCTIONS.md` | `docs/internal/SERVER_TEAM_INSTRUCTIONS.md` |
| `Reference/bento-magento-integration-complete-guide.md` | `docs/internal/bento-magento-integration-complete-guide.md` |
| `docs/01-TECHNICAL-SPECIFICATION.md` | `docs/internal/01-TECHNICAL-SPECIFICATION.md` |
| `docs/02-INSTALLATION-GUIDE.md` | `docs/internal/02-INSTALLATION-GUIDE.md` |
| `docs/03-ADMIN-CONFIGURATION-REFERENCE.md` | `docs/internal/03-ADMIN-CONFIGURATION-REFERENCE.md` |
| `docs/05-TESTING-GUIDE.md` | `docs/internal/05-TESTING-GUIDE.md` |
| `docs/superpowers/` | `docs/internal/superpowers/` |

### Deletes
| File | Reason |
|------|--------|
| `modules/ArtLounge/BentoCore/composer.json` | Replaced by root composer.json |
| `modules/ArtLounge/BentoEvents/composer.json` | Replaced by root composer.json |
| `modules/ArtLounge/BentoTracking/composer.json` | Replaced by root composer.json |
| `modules/` (empty dir after moves) | Cleanup |
| `Reference/` (empty dir after moves) | Cleanup |
| `_ul` | Empty artifact file |

### Creates
| File | Purpose |
|------|---------|
| `CONTRIBUTING.md` | Developer contribution guide |
| `LICENSE` | MIT license |
| `.gitattributes` | Export-ignore for Composer dist |
| `.github/workflows/ci.yml` | GitHub Actions CI |

### Updates
| File | Change |
|------|--------|
| `composer.json` | Complete rewrite (see spec above) |
| `README.md` | Rewrite for public audience (currently exists, will be replaced) |
| `.gitignore` | Rewrite for new structure (currently exists, will be replaced) |
| `phpunit.xml` | Update paths from `modules/` to `src/`, add coverage excludes |

### Kept As-Is
| File | Notes |
|------|-------|
| `CHANGELOG.md` | Carried over, no changes needed |
| `tests/bootstrap.php` | Path to vendor autoload (`__DIR__ . '/../vendor/autoload.php'`) remains valid |
| `tests/Stubs/*` | All stubs including `translation.php`, `Zend_Db_Expr.php`, and `Zend/` dir |
| `src/*/registration.php` | `__DIR__` resolves at runtime; no edits needed after move |

## Registration.php Consideration

Magento's `ComponentRegistrar::register()` uses a module name string (e.g., `ArtLounge_BentoCore`), not a file path. The registrar maps the module name to the directory containing `registration.php`. Since Composer's autoload handles file loading, and Magento resolves the module directory from the registration call's `__DIR__`, the existing `registration.php` files work without changes after the move.

## README.md Outline

1. **Header** — Package name, one-line description, badges (Packagist version, PHP version, license)
2. **What it does** — Brief overview of Bento + Magento integration via async queues
3. **Requirements** — PHP 8.1+, Magento 2.4.4+, RabbitMQ, Aligent Async Events
4. **Installation** — `composer require` + module enable + setup commands
5. **Configuration** — Admin path, key settings with screenshots placeholder
6. **Events Reference** — Table of server-side and client-side events tracked
7. **Queue Consumers** — How to start and monitor consumers
8. **Abandoned Cart** — Configuration and how it works
9. **Troubleshooting** — Common issues (connection test, queue not processing, debug mode)
10. **Architecture** — Link to `docs/architecture.md`
11. **Contributing** — Link to `CONTRIBUTING.md`
12. **License** — MIT

## CI Workflow Outline (.github/workflows/ci.yml)

```yaml
name: CI
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: ['8.1', '8.2', '8.3']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/phpunit
```

## What Does NOT Change

- PHP namespaces (`ArtLounge\BentoCore\*`, `ArtLounge\BentoEvents\*`, `ArtLounge\BentoTracking\*`)
- Magento module names (`ArtLounge_BentoCore`, `ArtLounge_BentoEvents`, `ArtLounge_BentoTracking`)
- XML config paths (`artlounge_bento/*`)
- Any PHP logic, XML config, templates, or JavaScript
- `etc/module.xml` dependency declarations
- Test logic (only paths in phpunit.xml change)
- `tests/bootstrap.php` relative path to vendor autoload
