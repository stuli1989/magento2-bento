# Bento Email Marketing Integration for Art Lounge Magento 2

## Production-Ready Async Events Implementation

This package contains a complete, production-ready implementation for integrating Bento email marketing with Art Lounge's Magento 2 store using the Aligent Async Events framework.

---

## Package Contents

```
async events/
+-- README.md                          # This file
+-- DEVELOPER_HANDOFF.md               # Step-by-step installation guide (primary document)
+-- SERVER_TEAM_INSTRUCTIONS.md        # RabbitMQ + consumer setup for hosting team
+-- CHANGELOG.md                       # All changes since initial release
+-- PLUGIN_ARCHITECTURE.md             # Detailed architecture and design patterns
|
+-- docs/
|   +-- 01-TECHNICAL-SPECIFICATION.md  # Technical specification
|   +-- 02-INSTALLATION-GUIDE.md       # Detailed installation reference
|   +-- 03-ADMIN-CONFIGURATION-REFERENCE.md  # All admin settings
|   +-- 05-TESTING-GUIDE.md            # Testing procedures
|
+-- modules/
    +-- ArtLounge/
        +-- BentoCore/                  # Shared config & API client
        +-- BentoEvents/                # Server-side events
        +-- BentoTracking/              # Client-side tracking
```

---

## Quick Start

- **For developers:** see [DEVELOPER_HANDOFF.md](DEVELOPER_HANDOFF.md) for full installation, configuration, and deployment instructions.
- **For server teams:** see [SERVER_TEAM_INSTRUCTIONS.md](SERVER_TEAM_INSTRUCTIONS.md) — forward this to your hosting team for RabbitMQ and consumer setup.

---

## Features

### Server-Side Events (via Async Events + RabbitMQ)

| Event | Bento Event | When Triggered |
|-------|-------------|----------------|
| Order Placed | `$purchase` | Customer completes checkout |
| Order Shipped | `$OrderShipped` | Shipment created |
| Order Refunded | `$OrderRefunded` | Credit memo created |
| Newsletter Subscribe | `$subscribe` | Email subscribed |
| Newsletter Unsubscribe | `$unsubscribe` | Email unsubscribed |
| Customer Registered | `$Subscriber` | New account created |
| Abandoned Cart | `$cart_abandoned` | Cart idle for configured time |

### Client-Side Events (via JavaScript)

| Event | Bento Event | When Triggered |
|-------|-------------|----------------|
| Product View | `$view` | Customer views product page |
| Add to Cart | `$cart_created` / `$cart_updated` | Customer clicks add to cart (main or variant table row) |
| Checkout Started | `$checkoutStarted` | Customer enters checkout |
| Purchase (fallback) | `$purchase` | Checkout success page (client fallback, deduped with server-side via increment_id) |

### Reliability Features

- **Outbox fallback** — DB-backed retry for AMQP broker downtime. If RabbitMQ is unreachable, events are stored in the database outbox table and retried automatically when the broker recovers.
- **Dead-letter replay** — CLI command (`bento:deadletter:replay`) and cron-based queue monitoring, with up to 20 retry attempts before permanent failure.
- **Abandoned cart detection** — Configurable delay, minimum cart value, and customer group exclusions. Recovery links are HMAC-signed for security.
- **Anonymous view replay** — Product views from anonymous sessions are attributed to the customer after login.
- **Custom bento-identity section** — Dedicated Magento customerData section for user identification (Magento's built-in `customer` section lacks email).
- **Async processing** — Non-blocking event dispatch that does not slow down checkout or page loads.
- **Event tracing** — Full audit trail with UUIDs for debugging.
- **Per-event toggles** — Enable or disable individual events from admin without code changes.
- **MagePack-safe** — Window-level deduplication guards prevent multi-fire from RequireJS rebundling.
- **CSP whitelist** — Pre-configured Content Security Policy entries for Bento domains.

---

## CLI Commands

```bash
bin/magento bento:test                     # Test API connection (--store=N for per-store)
bin/magento bento:status                   # Show config flags + abandoned cart schedule stats
bin/magento bento:abandoned-cart:process    # Manually process pending carts (--limit=N)
bin/magento bento:abandoned-cart:cleanup    # Remove old schedule entries (--days=N)
bin/magento bento:deadletter:replay        # Replay failed events from the dead-letter queue
```

## Admin Grid

**Marketing > Bento > Abandoned Cart Schedule** — view all scheduled entries with status, email, grand total, timestamps. Supports mass delete and mass reset-to-pending.

---

## Requirements

| Component | Version |
|-----------|---------|
| Magento | 2.4.7-p2 |
| PHP | 8.1+ |
| RabbitMQ | 3.8+ |
| aligent/async-events | ^3.0 |
| Bento account | Site UUID, publishable key, secret key |

---

## Testing

The package includes 278 unit and integration tests covering all observers, service classes, CLI commands, and client-side tracking logic.

```bash
# Run all tests
vendor/bin/phpunit -c phpunit.xml

# Run unit tests only
vendor/bin/phpunit -c phpunit.xml --testsuite unit

# Run integration tests only
vendor/bin/phpunit -c phpunit.xml --testsuite integration
```

See [Testing Guide](docs/05-TESTING-GUIDE.md) for manual and automated testing procedures.

---

## Documentation

| Document | Purpose |
|----------|---------|
| [Developer Handoff](DEVELOPER_HANDOFF.md) | Step-by-step installation guide (primary document) |
| [Server Team Instructions](SERVER_TEAM_INSTRUCTIONS.md) | RabbitMQ + consumer setup for hosting team |
| [Changelog](CHANGELOG.md) | All changes since initial release |
| [Plugin Architecture](PLUGIN_ARCHITECTURE.md) | Detailed architecture and design patterns |
| [Technical Specification](docs/01-TECHNICAL-SPECIFICATION.md) | Architecture, data models, API contracts |
| [Installation Guide](docs/02-INSTALLATION-GUIDE.md) | Detailed installation reference |
| [Admin Configuration](docs/03-ADMIN-CONFIGURATION-REFERENCE.md) | All configuration options |
| [Testing Guide](docs/05-TESTING-GUIDE.md) | Manual and automated testing procedures |

---

## Module Overview

### ArtLounge_BentoCore

Shared foundation:
- `Model/Config.php` - Central configuration with hierarchical enable flags
- `Model/BentoClient.php` - HTTP client for Bento API (CurlFactory-based)
- Admin system configuration (system.xml)
- Test connection button + AJAX endpoint

### ArtLounge_BentoEvents

Server-side event engine:
- 8 Magento event observers (with outbox fallback for all 7 event types)
- 6 service classes for data formatting (with batch product preloading)
- Abandoned cart scheduler, checker, consumer, cron
- Dead-letter replay CLI and queue monitor cron
- Admin grid for schedule management
- CLI commands for diagnostics and manual ops
- HMAC-signed cart recovery tokens + Recover controller
- Database schema for cart tracking and outbox tables
- `async_events.xml` maps event names to service class methods
- `BentoNotifier` registered with Aligent's `NotifierFactory` via `notifierClasses` DI argument

### ArtLounge_BentoTracking

Client-side tracking:
- Bento JavaScript injection (Varnish-safe)
- Custom `bento-identity` customerData section for user identification (Magento's `customer` section lacks email)
- Product view + add-to-cart tracking (including variant table per-row ATC)
- Checkout + purchase tracking with deduplication guards
- MagePack-safe (window-level guards prevent multi-fire from RequireJS rebundling)
- Debug-gated console output
- CSP whitelist for Bento domains

---

## Support

- **Bento Support:** jesse@bentonow.com or Discord
- **Async Events Issues:** https://github.com/aligent/magento-async-events/issues

---

## License

MIT License - See individual module files for details.

---

**Prepared for Art Lounge**
**Last updated: March 20, 2026**
