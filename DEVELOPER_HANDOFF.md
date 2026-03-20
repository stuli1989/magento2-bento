# Bento Integration for Magento 2 -- Developer Handoff

This is the primary installation guide for the Art Lounge Bento email marketing integration. Follow it step by step on a staging server. It assumes zero prior knowledge of the Bento integration.

---

## 1. Overview

This plugin connects a Magento 2 store with [Bento](https://bentonow.com) (email marketing platform) via three modules:

- **BentoCore** -- Shared configuration, API client (`BentoClient`), admin settings panel (Stores > Configuration > Art Lounge > Bento Integration). All other modules depend on this one.
- **BentoEvents** -- Server-side event observers for orders, customers, newsletter, and cart abandonment. Events are published to RabbitMQ via the [Aligent Async Events](https://github.com/aligent/magento-async-events) framework, consumed by workers, and delivered to the Bento API. Includes an outbox fallback for AMQP failures, a dead-letter replay system, and CLI diagnostic commands.
- **BentoTracking** -- Client-side JavaScript tracking. Loads the Bento SDK on every storefront page and tracks product views, add-to-cart, checkout, and purchase events directly from the browser to the Bento API.

### Server-side event flow

```
Customer action
  --> Magento event (e.g. sales_order_place_after)
    --> Observer publishes message to AMQP queue
      --> event.trigger.consumer picks up message
        --> BentoNotifier formats and sends to Bento API
```

If AMQP publish fails:
```
Observer catch --> OutboxWriter --> artlounge_bento_outbox DB table
  --> OutboxProcessor cron (runs every 2 minutes) --> retries AMQP publish
```

If Bento API fails:
```
Message re-queued --> event.retry.consumer --> retry with backoff (up to 20 attempts)
  --> If all retries exhausted: dead-letter queue
    --> DeadLetterMonitor cron alerts --> manual replay via bento:deadletter:replay
```

### Client-side event flow

```
Page load --> Bento SDK (loaded from bentonow.com)
  --> bento.identify(email) -- from customer session or localStorage
  --> bento.track(eventName, data) -- sent directly to Bento API from the browser
```

---

## 2. Prerequisites

Before you begin, confirm the following:

- **Magento 2.4.7-p2** (or compatible 2.4.x) with **PHP 8.1+**
- **Composer** installed and working
- **RabbitMQ 3.8+** installed and running. If your hosting team needs setup instructions, forward them **SERVER_TEAM_INSTRUCTIONS.md** (included in this package).
- **AMQP configured** in Magento's `app/etc/env.php` (see Section 4 below)
- **Bento account credentials** -- you need three values from the Bento dashboard (Settings > Site):
  - Site UUID
  - Publishable Key
  - Secret Key

---

## 3. Installation Steps

Run these commands from your Magento root directory.

**Step 1.** Install the Aligent Async Events framework (server-side event pipeline):

```bash
composer require aligent/async-events:^3.0
```

**Step 2.** Copy the three modules from this package into Magento:

```bash
cp -r modules/ArtLounge/BentoCore app/code/ArtLounge/BentoCore
cp -r modules/ArtLounge/BentoTracking app/code/ArtLounge/BentoTracking
cp -r modules/ArtLounge/BentoEvents app/code/ArtLounge/BentoEvents
```

**Step 3.** Run Magento setup:

```bash
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

`setup:upgrade` creates the required database tables and registers the 9 async event subscriptions via a data patch. It must complete successfully before `setup:di:compile`.

**Step 4.** Verify the modules are enabled:

```bash
php bin/magento module:status | grep -i bento
```

Expected output:

```
ArtLounge_BentoCore
ArtLounge_BentoTracking
ArtLounge_BentoEvents
```

If any module is missing, enable it explicitly:

```bash
php bin/magento module:enable ArtLounge_BentoCore ArtLounge_BentoTracking ArtLounge_BentoEvents
php bin/magento setup:upgrade
php bin/magento cache:flush
```

---

## 4. AMQP Configuration

Server-side events require RabbitMQ. Add the following to your `app/etc/env.php` file (get the actual values from your server/hosting team):

```php
'queue' => [
    'amqp' => [
        'host' => 'localhost',
        'port' => '5672',
        'user' => 'magento',
        'password' => 'YOUR_RABBITMQ_PASSWORD',
        'virtualhost' => '/magento',
        'ssl' => ''
    ]
],
```

After editing `env.php`, flush the cache:

```bash
php bin/magento cache:flush
```

If your hosting team has not set up RabbitMQ yet, forward them **SERVER_TEAM_INSTRUCTIONS.md** from this package. It covers RabbitMQ installation, vhost creation, user permissions, and systemd consumer setup.

---

## 5. Admin Configuration

Navigate to: **Stores > Configuration > Art Lounge > Bento Integration**

The configuration has 7 sections. Fill them in as described:

| Section | Key Fields | What to Set |
|---------|-----------|-------------|
| **General** | Enable Integration, Site UUID, Publishable Key, Secret Key, Debug Mode | Enter your Bento credentials. Enable the integration. Turn Debug Mode **ON** for initial testing (turn it OFF before go-live). |
| **Order Events** | Enable, Track Placed/Shipped/Cancelled/Refunded, Currency Multiplier | Enable all order events. Set Currency Multiplier to `100` (converts INR to paisa for Bento). |
| **Customer Events** | Enable, Track Registration/Updates, Default Tags | Enable all. Set Default Tags to `lead,mql` (or whatever tags your marketing team wants on new subscribers). |
| **Newsletter** | Enable, Track Subscribe/Unsubscribe, Subscribe Tags | Enable all. Set Subscribe Tags to `newsletter`. |
| **Abandoned Cart** | Enable, Delay (minutes), Min Value, Recovery URL, Prevent Duplicates | Enable. Set Delay to `15` minutes. Min Value: `0`. Recovery URL: Yes. Prevent Duplicates: Yes, 24-hour window. |
| **Client-Side Tracking** | Enable, Track Views/ATC/Checkout, ATC Selector, Include Brand | Enable all. ATC Selector: `.add_cart_btn.addToCart` (Art Lounge theme default -- change this if your theme uses a different selector). Include Brand: Yes. |
| **Advanced** | Max Retries, Log Retention, Request Timeout | Leave defaults (3 retries, 30 days, 30s timeout) unless you have a reason to change them. |

After saving, click the **Test Connection** button in the General section.

- **Expected:** "Successfully connected to Bento API"
- **"Authentication failed":** Check your Publishable Key and Secret Key
- **"Access forbidden":** Check your Site UUID
- **"Connection failed":** Your server cannot reach `app.bentonow.com` on port 443 -- check firewall/DNS

---

## 6. Start Queue Consumers

Server-side events (orders, customers, newsletter, abandoned carts) are published to RabbitMQ queues. Two consumer processes must be running to pick up and deliver those events to Bento:

```bash
# Start the main event consumer
php bin/magento queue:consumers:start event.trigger.consumer &

# Start the retry consumer (handles failed events with backoff)
php bin/magento queue:consumers:start event.retry.consumer &
```

**IMPORTANT:** These consumers must run persistently on staging and production. If they stop, server-side events will queue up in RabbitMQ but never reach Bento. For a systemd/supervisor setup that auto-restarts consumers, see **SERVER_TEAM_INSTRUCTIONS.md** Section 5 (forward to your hosting team).

Verify the consumers are registered:

```bash
php bin/magento queue:consumers:list | grep event
```

Expected output:

```
event.trigger.consumer
event.retry.consumer
```

---

## 7. Verification Checklist

After installation and configuration, verify each component works. Check items off in order:

**1. Admin Panel**
- Go to Stores > Configuration > Art Lounge > Bento Integration
- All 7 sections should be visible
- Click Test Connection -- expect "Successfully connected to Bento API"

**2. CLI -- Test Connection**
```bash
php bin/magento bento:test
```
Expected: "Connection successful"

**3. CLI -- Status**
```bash
php bin/magento bento:status
```
Expected: Config summary showing all events enabled, queue consumer status

**4. Product View (client-side)**
- Visit any product page on the storefront
- Open browser Developer Tools > Console tab
- Look for: `[Bento] Product view tracked: {product name}`
- (Requires Debug Mode ON in admin, or run `window.bentoDebug = true` in the console)

**5. Add to Cart (client-side)**
- Click the Add to Cart button on any product
- Console should show: `[Bento] $cart_created tracked (full cart): N items`

**6. Place a Test Order (server-side)**
- Complete a COD order (or any available payment method)
- Check `var/log/bento.log` for a `$purchase` entry
- Look for `status_code: 200` and `results: 1, failed: 0`

**7. Register a Customer (server-side)**
- Create a new customer account on the storefront
- Check `var/log/bento.log` for a `$Subscriber` entry with `200` status

**8. Newsletter Subscribe (server-side)**
- Subscribe via the footer newsletter form
- Check `var/log/bento.log` for a `$subscribe` entry with `200` status

If any step fails, see Section 9 (Troubleshooting) below.

---

## 8. CLI Commands Reference

All commands are run from the Magento root directory.

| Command | Description | Example |
|---------|-------------|---------|
| `bento:test` | Test Bento API connection | `php bin/magento bento:test` |
| `bento:test --store=N` | Test connection for a specific store view | `php bin/magento bento:test --store=3` |
| `bento:status` | Show config summary and queue status | `php bin/magento bento:status` |
| `bento:outbox:status` | Show pending outbox entries (failed AMQP publishes waiting for retry) | `php bin/magento bento:outbox:status` |
| `bento:outbox:process` | Manually process/retry outbox entries | `php bin/magento bento:outbox:process` |
| `bento:deadletter:replay` | Replay dead-lettered messages (events that exhausted all retries) | `php bin/magento bento:deadletter:replay` |
| `bento:abandoned-cart:process` | Manually process pending abandoned cart events | `php bin/magento bento:abandoned-cart:process` |
| `bento:abandoned-cart:process --limit=N` | Process with a batch limit | `php bin/magento bento:abandoned-cart:process --limit=25` |
| `bento:abandoned-cart:cleanup` | Clean up old abandoned cart schedule entries | `php bin/magento bento:abandoned-cart:cleanup --days=30` |

---

## 9. Troubleshooting

| Problem | Solution |
|---------|----------|
| **Server-side events don't appear in Bento** | Consumers are probably not running. Start them: `php bin/magento queue:consumers:start event.trigger.consumer &` and `php bin/magento queue:consumers:start event.retry.consumer &`. For persistent setup, see SERVER_TEAM_INSTRUCTIONS.md Section 5. |
| **"AMQP connection refused"** | RabbitMQ is not running or the credentials are wrong. Verify RabbitMQ is up: `rabbitmqctl status`. Check that the `queue > amqp` block in `app/etc/env.php` matches what your server team configured. |
| **Events not appearing in Bento dashboard** | Check `var/log/bento.log` for errors. Verify API credentials in admin config. If you see `429` status codes, you hit the Bento rate limit -- wait 1 hour for cooldown. |
| **"Bento API 429 Too Many Requests"** | Rate limit is approximately 250 events in 10 minutes. Real users will not hit this. It happens during bulk testing. Wait 1 hour, then resume. The SDK does not auto-retry on 429. |
| **`setup:di:compile` fails on Windows** | Not needed in developer mode. Only run `setup:di:compile` on staging/production (Linux). |
| **Events return 200 but don't appear in Bento** | Check the `value` format in the event payload. It must be `{ "amount": N, "currency": "INR" }`. A flat number (e.g., `value: 82000`) causes Bento to silently drop the event. This is already fixed in the current version of the code. |
| **Outbox has pending entries** | AMQP was temporarily unavailable when events fired. Run `php bin/magento bento:outbox:process` to retry. Entries also auto-process via cron every 2 minutes. |
| **bento.log shows "PERMANENT FAILURE"** | Bento API rejected the event due to bad data. Check the log entry for the response body. Common causes: `missing_email` (entity has no email), `http_401` (wrong API credentials), `http_400` (malformed payload -- report as a bug). |
| **Duplicate events in Bento** | An older Bento script may still be running alongside this plugin. Search for existing scripts: `grep -r "bentonow.com" app/design/` and check CMS blocks/pages. Remove any duplicates before enabling BentoTracking. |
| **Console shows no `[Bento]` messages** | Debug Mode may be OFF. Enable it in admin (Stores > Config > Art Lounge > Bento Integration > General > Debug Mode = Yes) or run `window.bentoDebug = true` in the browser console. |
| **Customer identified as "Visitor #..."** | The `bento-identity` customer data section may not be loading. Clear browser cache, log out, log back in, and check the Network tab for a request to `customer/section/load` that includes `bento-identity` in the response. |

---

## 10. Architecture Quick Reference

For the full architecture document, see **PLUGIN_ARCHITECTURE.md**.

### Server-side event flow (detail)

```
Observer
  --> publishes message to AMQP queue (via Aligent AsyncEventPublisher)
    --> event.trigger.consumer picks up message
      --> Aligent framework resolves the Service class + method from async_events.xml
        --> Service method loads the entity and returns formatted data
          --> BentoNotifier receives formatted data
            --> BentoClient sends HTTP POST to Bento batch API
              --> Success: message acknowledged, removed from queue
              --> Retryable failure (429, 500, 502, 503, 504, network error):
                    message re-queued for event.retry.consumer with backoff
              --> Permanent failure (400, 401, 403, missing email):
                    marked as "success" to stop retries, failure logged
```

If AMQP publish fails in the Observer:
```
Observer catch block
  --> OutboxWriter inserts row into artlounge_bento_outbox table
    --> OutboxProcessor cron (*/2 min) reads pending rows
      --> Retries AMQP publish
        --> On success: row marked as processed
        --> On failure: retry count incremented, re-attempted next cron run
```

### Client-side event flow (detail)

```
Every page load:
  bento_script.phtml loads Bento SDK from bentonow.com
    --> Checks for logged-in customer via bento-identity customerData section
    --> If email found: bento.identify(email)
    --> Also checks localStorage for bento_identity (return visitors)

Product page:
  product_tracking.phtml
    --> bento.track('$view', { product_id, sku, name, price, ... })
    --> Attaches ATC click handler to configured selector
    --> On click: bento.track('$cart_created', { cart items, value, ... })

Checkout page:
  checkout_tracking.phtml
    --> bento.track('$checkoutStarted', { items, subtotal, item_count, ... })

Order success page:
  purchase_tracking.phtml
    --> bento.track('$purchase', { order data, items, value, ... })
```

### Key files

| Component | File |
|-----------|------|
| Bento SDK + User Identify | `BentoTracking/view/frontend/templates/bento_script.phtml` |
| Product View + ATC Tracking | `BentoTracking/view/frontend/templates/product_tracking.phtml` |
| Checkout Tracking | `BentoTracking/view/frontend/templates/checkout_tracking.phtml` |
| Purchase Tracking (client) | `BentoTracking/view/frontend/templates/purchase_tracking.phtml` |
| Tracking ViewModel | `BentoTracking/ViewModel/TrackingData.php` |
| Custom Identity Section | `BentoTracking/CustomerData/BentoSection.php` |
| Order Service | `BentoEvents/Service/OrderService.php` |
| Customer Service | `BentoEvents/Service/CustomerService.php` |
| Newsletter Service | `BentoEvents/Service/NewsletterService.php` |
| Abandoned Cart Service | `BentoEvents/Service/AbandonedCartService.php` |
| Bento API Client | `BentoCore/Model/BentoClient.php` |
| Bento Notifier (queue bridge) | `BentoEvents/Model/BentoNotifier.php` |
| Event Type Mapper | `BentoEvents/Model/EventTypeMapper.php` |
| Outbox Writer | `BentoEvents/Model/Outbox/Writer.php` |
| Outbox Processor | `BentoEvents/Model/Outbox/Processor.php` |
| Recovery Token (cart recovery) | `BentoEvents/Model/RecoveryToken.php` |
| Admin Config (system.xml) | `BentoCore/etc/adminhtml/system.xml` |
| Async Event Subscriptions | `BentoEvents/etc/async_events.xml` |
| Observer Event Registration | `BentoEvents/etc/events.xml` |
