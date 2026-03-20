# Contributing to Bento Integration for Magento 2

Thank you for considering contributing! Here's how to get started.

## Development Setup

This package can be developed standalone (without a full Magento installation) using the included test stubs.

### Prerequisites

- PHP 8.1+
- Composer

### Setup

```bash
git clone https://github.com/stuli1989/magento2-bento.git
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
