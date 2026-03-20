# Composer Package Restructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the repo from a development workspace into a distributable Composer package (`artlounge/magento2-bento`) that anyone can `composer require` into their Magento store.

**Architecture:** Three Magento modules stay under `src/`, internal docs move to gitignored `docs/internal/`, root gets new composer.json (type: library), README, LICENSE, CONTRIBUTING, .gitattributes, and CI workflow. No PHP code changes — only file moves and config rewrites.

**Tech Stack:** Composer, Magento 2, PHPUnit, GitHub Actions

**Spec:** `docs/superpowers/specs/2026-03-20-composer-package-design.md`

**Rollback:** If anything goes wrong mid-migration, reset to the initial snapshot commit: `git reset --hard HEAD~N` (where N is the number of commits made since the snapshot). All original files are preserved in that first commit.

---

### Task 1: Initialize Git Repository and Prepare .gitignore

This project is not currently a git repo. We initialize one and immediately set up the `.gitignore` to exclude tool configs and `docs/internal/` **before** any files are moved there. This ensures internal docs are never committed.

**Files:**
- Create: `.git/` (via `git init`)
- Update: `.gitignore`

- [ ] **Step 1: Initialize git**

```bash
cd "C:/Users/Kshitij Shah/OneDrive/Documents/Art Lounge/async events"
git init
```

- [ ] **Step 2: Write the final `.gitignore` immediately**

This must happen before the initial commit so that `.claude/`, `.cursor/`, and `docs/internal/` are never tracked. Replace entire contents of `.gitignore` with:

```
/vendor/
/build/
/docs/internal/
/.claude/
/.cursor/
.phpunit.result.cache
composer.lock
```

- [ ] **Step 3: Create initial commit with current state**

This preserves the pre-migration state as a rollback point. Because `.gitignore` now excludes `.claude/`, `.cursor/`, and `vendor/`, those won't be committed.

```bash
git add -A
git commit -m "chore: snapshot pre-migration state"
```

- [ ] **Step 4: Verify commit**

```bash
git log --oneline -1
git status
```

Expected: Clean working tree, one commit. Verify `.claude/` and `.cursor/` are NOT in the commit:

```bash
git ls-files | grep -E "\.(claude|cursor)" || echo "Clean - no tool configs tracked"
```

---

### Task 2: Move Modules from `modules/` to `src/`

**Files:**
- Move: `modules/ArtLounge/BentoCore/*` → `src/BentoCore/*`
- Move: `modules/ArtLounge/BentoEvents/*` → `src/BentoEvents/*`
- Move: `modules/ArtLounge/BentoTracking/*` → `src/BentoTracking/*`
- Delete: `modules/ArtLounge/BentoCore/composer.json` (before move — excluded from `src/`)
- Delete: `modules/ArtLounge/BentoEvents/composer.json`
- Delete: `modules/ArtLounge/BentoTracking/composer.json`

- [ ] **Step 1: Delete per-module composer.json files**

These are replaced by the root composer.json. Remove before moving to keep `src/` clean.

```bash
cd "C:/Users/Kshitij Shah/OneDrive/Documents/Art Lounge/async events"
rm modules/ArtLounge/BentoCore/composer.json
rm modules/ArtLounge/BentoEvents/composer.json
rm modules/ArtLounge/BentoTracking/composer.json
```

- [ ] **Step 2: Create `src/` and move modules**

```bash
mkdir -p src
mv modules/ArtLounge/BentoCore src/BentoCore
mv modules/ArtLounge/BentoEvents src/BentoEvents
mv modules/ArtLounge/BentoTracking src/BentoTracking
```

- [ ] **Step 3: Clean up empty `modules/` directory**

```bash
rm -rf modules
```

- [ ] **Step 4: Verify structure**

```bash
ls src/
ls src/BentoCore/registration.php
ls src/BentoEvents/registration.php
ls src/BentoTracking/registration.php
```

Expected: Three module directories, each with `registration.php`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: move modules from modules/ArtLounge/ to src/"
```

---

### Task 3: Move Internal Docs to `docs/internal/` (Gitignored)

Since `.gitignore` already includes `/docs/internal/` (set up in Task 1), files moved there will exist on disk but **not** be tracked by git. Git will only see the deletions from original locations — which is exactly what we want for the public repo.

**Files:**
- Move: `CLAUDE.md` → `docs/internal/CLAUDE.md`
- Move: `DEVELOPER_HANDOFF.md` → `docs/internal/DEVELOPER_HANDOFF.md`
- Move: `SERVER_TEAM_INSTRUCTIONS.md` → `docs/internal/SERVER_TEAM_INSTRUCTIONS.md`
- Move: `Reference/bento-magento-integration-complete-guide.md` → `docs/internal/bento-magento-integration-complete-guide.md`
- Move: `docs/01-TECHNICAL-SPECIFICATION.md` → `docs/internal/01-TECHNICAL-SPECIFICATION.md`
- Move: `docs/02-INSTALLATION-GUIDE.md` → `docs/internal/02-INSTALLATION-GUIDE.md`
- Move: `docs/03-ADMIN-CONFIGURATION-REFERENCE.md` → `docs/internal/03-ADMIN-CONFIGURATION-REFERENCE.md`
- Move: `docs/05-TESTING-GUIDE.md` → `docs/internal/05-TESTING-GUIDE.md`
- Move: `docs/superpowers/` → `docs/internal/superpowers/`
- Move: `PLUGIN_ARCHITECTURE.md` → `docs/architecture.md` (public — stays tracked)
- Delete: `Reference/` (empty after move)
- Delete: `_ul` (empty artifact)

- [ ] **Step 1: Create `docs/internal/` directory**

```bash
cd "C:/Users/Kshitij Shah/OneDrive/Documents/Art Lounge/async events"
mkdir -p docs/internal
```

- [ ] **Step 2: Move root-level internal docs**

```bash
mv CLAUDE.md docs/internal/CLAUDE.md
mv DEVELOPER_HANDOFF.md docs/internal/DEVELOPER_HANDOFF.md
mv SERVER_TEAM_INSTRUCTIONS.md docs/internal/SERVER_TEAM_INSTRUCTIONS.md
```

- [ ] **Step 3: Move Reference guide and clean up directory**

```bash
mv "Reference/bento-magento-integration-complete-guide.md" docs/internal/bento-magento-integration-complete-guide.md
rm -rf Reference
```

- [ ] **Step 4: Move docs/ subdirectory files to docs/internal/**

```bash
mv docs/01-TECHNICAL-SPECIFICATION.md docs/internal/01-TECHNICAL-SPECIFICATION.md
mv docs/02-INSTALLATION-GUIDE.md docs/internal/02-INSTALLATION-GUIDE.md
mv docs/03-ADMIN-CONFIGURATION-REFERENCE.md docs/internal/03-ADMIN-CONFIGURATION-REFERENCE.md
mv docs/05-TESTING-GUIDE.md docs/internal/05-TESTING-GUIDE.md
```

- [ ] **Step 5: Move superpowers directory**

```bash
mv docs/superpowers docs/internal/superpowers
```

- [ ] **Step 6: Move PLUGIN_ARCHITECTURE.md to public docs**

This file stays public (tracked by git) as the architecture guide for contributors.

```bash
mv PLUGIN_ARCHITECTURE.md docs/architecture.md
```

- [ ] **Step 7: Delete empty artifact file**

```bash
rm -f _ul
```

- [ ] **Step 8: Verify**

```bash
ls docs/
ls docs/internal/
```

Expected: `docs/` has `architecture.md` and `internal/`. `docs/internal/` has all moved files + `superpowers/` dir.

Verify that `docs/internal/` files are NOT staged by git:

```bash
git status
```

Expected: Shows `docs/architecture.md` as a new file (renamed from PLUGIN_ARCHITECTURE.md). Shows deletions for CLAUDE.md, DEVELOPER_HANDOFF.md, SERVER_TEAM_INSTRUCTIONS.md, Reference/, docs/*.md, _ul. Does NOT show any additions in `docs/internal/`.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "refactor: remove internal docs from repo, keep architecture.md public

Internal docs (CLAUDE.md, DEVELOPER_HANDOFF.md, SERVER_TEAM_INSTRUCTIONS.md,
technical specs) moved to docs/internal/ which is gitignored. These files
exist on disk for local reference but are excluded from the public repository."
```

---

### Task 4: Rewrite Root Config Files

**Files:**
- Update: `composer.json`
- Update: `phpunit.xml`
- Create: `.gitattributes`

Note: `.gitignore` was already updated in Task 1.

- [ ] **Step 1: Rewrite `composer.json`**

Replace entire contents of `composer.json` with:

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

- [ ] **Step 2: Rewrite `phpunit.xml`**

Replace entire contents of `phpunit.xml` with:

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

- [ ] **Step 3: Create `.gitattributes`**

Create new file `.gitattributes` with:

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

- [ ] **Step 4: Regenerate Composer autoload**

```bash
cd "C:/Users/Kshitij Shah/OneDrive/Documents/Art Lounge/async events"
composer dump-autoload
```

Expected: No errors.

- [ ] **Step 5: Run tests to verify nothing broke**

```bash
vendor/bin/phpunit -c phpunit.xml
```

Expected: All tests pass. If any fail, the path update or autoload mapping is wrong — fix before proceeding.

- [ ] **Step 6: Commit**

```bash
git add composer.json .gitattributes phpunit.xml
git commit -m "refactor: rewrite root config files for Composer package distribution"
```

---

### Task 5: Create LICENSE File

**Files:**
- Create: `LICENSE`

- [ ] **Step 1: Create MIT LICENSE file**

Create `LICENSE` with the standard MIT license text. Use `2026 Art Lounge` as the copyright holder.

```
MIT License

Copyright (c) 2026 Art Lounge

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

- [ ] **Step 2: Commit**

```bash
git add LICENSE
git commit -m "chore: add MIT license"
```

---

### Task 6: Create GitHub Actions CI Workflow

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Create workflow directory**

```bash
cd "C:/Users/Kshitij Shah/OneDrive/Documents/Art Lounge/async events"
mkdir -p .github/workflows
```

- [ ] **Step 2: Create `ci.yml`**

Create `.github/workflows/ci.yml` with:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    name: PHP ${{ matrix.php-version }}
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: ['8.1', '8.2', '8.3']

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress --no-suggest

      - name: Run tests
        run: vendor/bin/phpunit -c phpunit.xml
```

- [ ] **Step 3: Commit**

```bash
git add .github/
git commit -m "ci: add GitHub Actions workflow for PHPUnit across PHP 8.1-8.3"
```

---

### Task 7: Write Public README.md

**Files:**
- Update: `README.md`

The README needs to be rewritten from Art Lounge's internal delivery doc into a public-facing install guide.

- [ ] **Step 1: Rewrite README.md**

Replace entire contents of `README.md` with:

```markdown
# Bento Integration for Magento 2

Connect your Magento 2 store with [Bento](https://bentonow.com) email marketing using reliable, non-blocking async event queues.

## What It Does

This package automatically sends customer activity from your Magento store to Bento for email marketing automation. Events are processed through RabbitMQ queues so they never slow down your store.

### Server-Side Events

| Magento Event | Bento Event | Trigger |
|---------------|-------------|---------|
| Order Placed | `$purchase` | Customer completes checkout |
| Order Shipped | `$OrderShipped` | Shipment created |
| Order Refunded | `$OrderRefunded` | Credit memo created |
| Customer Registered | `$Subscriber` | New account created |
| Newsletter Subscribe | `$subscribe` | Email subscribed |
| Newsletter Unsubscribe | `$unsubscribe` | Email unsubscribed |
| Abandoned Cart | `$cart_abandoned` | Cart idle for configured time |

### Client-Side Events

| Event | Bento Event | Trigger |
|-------|-------------|---------|
| Product View | `$view` | Customer views product page |
| Add to Cart | `$addToCart` | Customer adds item to cart |
| Checkout Started | `$checkoutStarted` | Customer enters checkout |
| Purchase (fallback) | `$purchase` | Success page (deduplicated with server-side) |

## Requirements

- PHP 8.1+
- Magento 2.4.4+
- RabbitMQ 3.8+ (or Magento database queue fallback)
- [Aligent Async Events](https://github.com/aligent/magento-async-events) ^3.0
- Bento account with Site UUID, Publishable Key, and Secret Key

## Installation

```bash
composer require artlounge/magento2-bento
bin/magento module:enable ArtLounge_BentoCore ArtLounge_BentoEvents ArtLounge_BentoTracking
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Configuration

Navigate to **Stores > Configuration > Art Lounge > Bento Integration** in Magento Admin.

### Required Settings

| Setting | Path | Description |
|---------|------|-------------|
| Enable | General > Enable | Master on/off switch |
| Site UUID | General > Site UUID | Your Bento site identifier |
| Publishable Key | General > Publishable Key | Client-side API key |
| Secret Key | General > Secret Key | Server-side API key (stored encrypted) |

### Optional Settings

- **Orders** — Toggle tracking for placed, shipped, cancelled, refunded orders. Configure tax inclusion and currency multiplier.
- **Customers** — Toggle tracking for customer creation/updates. Add default tags.
- **Newsletter** — Toggle subscribe/unsubscribe tracking.
- **Abandoned Cart** — Set delay (minutes), minimum cart value, and whether email is required.

### Test Your Connection

After entering your API keys, click **Test Connection** in the admin config to verify your credentials.

## Queue Consumers

Start the async event consumers (production environments should use Supervisor):

```bash
bin/magento queue:consumers:start event.trigger.consumer
bin/magento queue:consumers:start event.retry.consumer
```

Check queue status:

```bash
rabbitmqctl list_queues name messages consumers
```

## CLI Commands

```bash
bin/magento bento:test                     # Test API connection
bin/magento bento:status                   # Show config and queue status
bin/magento bento:abandoned-cart:process    # Manually process pending carts
bin/magento bento:abandoned-cart:cleanup    # Remove old schedule entries
bin/magento bento:deadletter:replay        # Replay failed events
```

## Reliability Features

- **Outbox fallback** — If RabbitMQ is unreachable, events are stored in a database outbox and retried automatically when the broker recovers.
- **Dead-letter replay** — Events that fail after max retries can be replayed via CLI or monitored by cron.
- **Smart retry prevention** — Permanent failures (400, 401, 403) are not retried; transient failures (429, 5xx, network errors) are retried with exponential backoff.
- **Abandoned cart detection** — Configurable delay, minimum value, and HMAC-signed recovery links.
- **Event deduplication** — All events include unique keys to prevent duplicate processing.

## Varnish / Full Page Cache Compatibility

All client-side tracking is Varnish-safe. Templates render no user-specific data in HTML — all customer identification happens via Magento's private content AJAX system (`customerData`).

## Architecture

See [docs/architecture.md](docs/architecture.md) for a detailed walkthrough of how the three modules work together, the event flow, retry system, and design decisions.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup, running tests, and pull request guidelines.

## License

[MIT](LICENSE)
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: rewrite README for public Composer package"
```

---

### Task 8: Create CONTRIBUTING.md

**Files:**
- Create: `CONTRIBUTING.md`

- [ ] **Step 1: Create CONTRIBUTING.md**

```markdown
# Contributing to Bento Integration for Magento 2

Thank you for considering contributing! Here's how to get started.

## Development Setup

This package can be developed standalone (without a full Magento installation) using the included test stubs.

### Prerequisites

- PHP 8.1+
- Composer

### Setup

```bash
git clone https://github.com/artlounge/magento2-bento.git
cd magento2-bento
composer install
```

### Running Tests

```bash
# All tests
vendor/bin/phpunit

# Unit tests only
vendor/bin/phpunit --testsuite "ArtLounge Bento Unit Tests"

# Integration tests only
vendor/bin/phpunit --testsuite "ArtLounge Bento Integration Tests"

# Specific test file
vendor/bin/phpunit src/BentoEvents/Test/Unit/Service/OrderServiceTest.php
```

## Project Structure

```
src/
  BentoCore/       # Shared configuration, API client, admin UI
  BentoEvents/     # Server-side event observers and services
  BentoTracking/   # Client-side JavaScript tracking
tests/
  bootstrap.php    # Test bootstrap (loads Composer autoloader)
  Stubs/           # Magento framework mocks for standalone testing
```

## Pull Request Guidelines

1. Fork the repository and create a feature branch from `main`
2. Write tests for new functionality
3. Ensure all tests pass: `vendor/bin/phpunit`
4. Keep commits focused — one logical change per commit
5. Write clear commit messages describing *why*, not just *what*

## Architecture

See [docs/architecture.md](docs/architecture.md) for an overview of how the modules work together before making changes.

## Reporting Issues

Open an issue on GitHub with:
- Magento version
- PHP version
- Steps to reproduce
- Expected vs actual behavior
- Relevant log entries from `var/log/bento.log`
```

- [ ] **Step 2: Commit**

```bash
git add CONTRIBUTING.md
git commit -m "docs: add CONTRIBUTING.md for public contributors"
```

---

### Task 9: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
cd "C:/Users/Kshitij Shah/OneDrive/Documents/Art Lounge/async events"
vendor/bin/phpunit -c phpunit.xml
```

Expected: All tests pass (same count as before restructure).

- [ ] **Step 2: Verify Composer autoload works**

```bash
composer dump-autoload
```

Expected: No errors.

- [ ] **Step 3: Verify directory structure matches spec**

```bash
echo "=== Root ===" && ls -la
echo "=== src/ ===" && ls src/
echo "=== docs/ ===" && ls docs/
echo "=== docs/internal/ (on disk, gitignored) ===" && ls docs/internal/
echo "=== tests/ ===" && ls tests/
echo "=== .github/workflows/ ===" && ls .github/workflows/
```

Verify these files exist at root: `README.md`, `CONTRIBUTING.md`, `LICENSE`, `CHANGELOG.md`, `composer.json`, `phpunit.xml`, `.gitignore`, `.gitattributes`.

Verify these are gone: `modules/`, `Reference/`, `_ul`, `CLAUDE.md`, `DEVELOPER_HANDOFF.md`, `SERVER_TEAM_INSTRUCTIONS.md`, `PLUGIN_ARCHITECTURE.md`.

Verify `docs/architecture.md` exists (the public architecture guide).

- [ ] **Step 4: Verify internal docs are NOT tracked by git**

```bash
git ls-files docs/internal/
```

Expected: Empty output (no files tracked under `docs/internal/`).

- [ ] **Step 5: Verify .gitattributes export-ignore works**

```bash
git archive --format=tar HEAD | tar -t | head -40
```

Expected: No `tests/`, `docs/`, `.github/`, or `src/*/Test/` directories in the archive.

- [ ] **Step 6: Verify no secrets in tracked files**

```bash
git ls-files | xargs grep -l "artlounge\.in" 2>/dev/null || echo "No matches - clean"
```

Expected: "No matches - clean" (the `system-test@artlounge.in` email was already fixed).

- [ ] **Step 7: Verify CHANGELOG.md survived**

```bash
head -3 CHANGELOG.md
```

Expected: Shows the changelog header.

- [ ] **Step 8: Review git log**

```bash
git log --oneline
```

Expected (most recent first):
```
docs: add CONTRIBUTING.md for public contributors
docs: rewrite README for public Composer package
chore: add MIT license
ci: add GitHub Actions workflow for PHPUnit across PHP 8.1-8.3
refactor: rewrite root config files for Composer package distribution
refactor: remove internal docs from repo, keep architecture.md public
refactor: move modules from modules/ArtLounge/ to src/
chore: snapshot pre-migration state
```

- [ ] **Step 9: Tag the release**

If everything looks good:

```bash
git tag -a v1.0.0 -m "v1.0.0: Initial public release"
```
