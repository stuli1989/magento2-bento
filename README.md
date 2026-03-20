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
