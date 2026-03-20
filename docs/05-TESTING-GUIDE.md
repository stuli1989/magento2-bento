# Testing Guide - Bento Integration with Async Events

**Document Version:** 2.0
**Date:** March 3, 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Running Automated Tests](#running-automated-tests)
3. [Manual Testing Procedures](#manual-testing-procedures)
4. [Event Verification Checklist](#event-verification-checklist)
5. [Debugging Guide](#debugging-guide)
6. [Performance Testing](#performance-testing)

---

## Overview

This guide covers testing procedures for the Art Lounge Bento integration modules:

- **ArtLounge_BentoCore** - Configuration and API client
- **ArtLounge_BentoEvents** - Server-side event tracking
- **ArtLounge_BentoTracking** - Client-side tracking

### Test Types

| Type | Purpose | Location |
|------|---------|----------|
| Unit Tests | Test individual classes in isolation | `Test/Unit/` |
| Integration Tests | Test database and service interactions | `Test/Integration/` |
| Manual Tests | End-to-end verification | This document |

---

## Running Automated Tests

The plugin ships with its own test infrastructure in the project root (`async events/`). Tests run **outside Magento** using stub classes — no Magento installation or database needed.

### Prerequisites

```bash
# From the project root (async events/)
composer install

# Verify PHPUnit is available
vendor/bin/phpunit --version
# Expected: PHPUnit 9.6.x
```

### Running All Tests

```bash
# Run everything (unit + integration) — 278 tests total
vendor/bin/phpunit --no-coverage
```

### Running Unit Tests Only (233 tests, 399 assertions)

```bash
# All unit tests
vendor/bin/phpunit --testsuite "ArtLounge Bento Unit Tests" --no-coverage

# Single module
vendor/bin/phpunit modules/ArtLounge/BentoCore/Test/Unit --no-coverage
vendor/bin/phpunit modules/ArtLounge/BentoEvents/Test/Unit --no-coverage
vendor/bin/phpunit modules/ArtLounge/BentoTracking/Test/Unit --no-coverage

# Single file
vendor/bin/phpunit modules/ArtLounge/BentoEvents/Test/Unit/Service/OrderServiceTest.php
```

### Running Integration Tests Only (45 tests, 138 assertions)

```bash
# All integration tests
vendor/bin/phpunit --testsuite "ArtLounge Bento Integration Tests" --no-coverage
```

Integration tests verify multi-class collaboration:

| Test Class | Tests | What It Covers |
|------------|-------|-----------------|
| **EventPipelineTest** | 5 | Real BentoNotifier + EventTypeMapper + BentoClient. All 9 event mappings, retry classification, disabled module, MissingEmailException. |
| **AbandonedCartLifecycleTest** | 4 | Real Scheduler + Checker with mock DB. Schedule→check→trigger, cart reschedule, converted cart, cleanup. |
| **ConfigHierarchyTest** | 8 | Real Config + mock ScopeConfig. Master cascade, section cascade, granular flags, defaults, debug logging, tags/groups parsing. |
| **CliCommandTest** | 10 | All 4 CLI commands via Symfony CommandTester. Success/failure, --store/--limit/--days options, invalid input. |
| **RecoveryFlowTest** | 11 | Real RecoveryToken + Recover controller. Token round-trip, tampered/expired/garbage rejection, guest/customer cart paths. |

### Expected Output

```
PHPUnit 9.6.34 by Sebastian Bergmann and contributors.

.............................................                     45 / 45 (100%)

Time: 00:01.326, Memory: 8.00 MB

OK (45 tests, 138 assertions)
```

### CLI Commands for Post-Deployment Verification

Once deployed to a Magento instance, you can also verify the integration using CLI:

```bash
# Test API connectivity
bin/magento bento:test
bin/magento bento:test --store=3

# Show config status and abandoned cart schedule summary
bin/magento bento:status

# Manually process pending abandoned carts
bin/magento bento:abandoned-cart:process --limit=10

# Clean up old schedule entries
bin/magento bento:abandoned-cart:cleanup --days=7
```

---

## Manual Testing Procedures

### Setup for Testing

Before manual testing, enable debug mode:

```bash
# Via CLI
bin/magento config:set artlounge_bento/general/debug 1

# Or in Admin
Stores > Configuration > ArtLounge > Bento > Debug Mode = Yes
```

### Test 1: Connection Test

**Purpose:** Verify API credentials and connectivity

**Option A — CLI (recommended for initial verification):**
```bash
bin/magento bento:test
# Expected: "Connection successful!" with status code and response time
```

**Option B — Admin panel:**
1. Navigate to: Stores > Configuration > ArtLounge > Bento Integration
2. Enter your Bento credentials (Site UUID, Publishable Key, Secret Key)
3. Click "Save Config"
4. Click "Test Connection" button

**Expected Result:**
```
✓ Successfully connected to Bento API
  Response time: 145ms
```

**If Failed:**
- Verify credentials are correct
- Check firewall allows outbound HTTPS to bentonow.com
- Review `var/log/bento.log` for details

---

### Test 2: Order Placed Event

**Purpose:** Verify order events are sent to Bento

**Steps:**
1. Create a test order on the storefront
2. Complete checkout
3. Check the Magento queue

**Verification:**

```bash
# Check if message was queued
bin/magento queue:consumers:list

# Check async events admin
# System > Async Events > Logs

# Check Bento log
tail -f var/log/bento.log
```

**Check in Bento Dashboard:**
1. Login to Bento
2. Navigate to Events > Recent Events
3. Look for `$purchase` event with:
   - Correct order ID
   - Customer email
   - Order total (in cents)
   - Line items

**Expected Payload in Bento:**
```json
{
  "event_type": "$purchase",
  "order": {
    "id": 12345,
    "increment_id": "#000012345"
  },
  "financials": {
    "total_value": 250000,
    "currency_code": "INR"
  },
  "customer": {
    "email": "customer@example.com"
  },
  "items": [...]
}
```

---

### Test 3: Order Shipped Event

**Purpose:** Verify shipment events are sent

**Steps:**
1. Find the test order in Admin
2. Create a shipment:
   - Sales > Orders > View Order
   - Click "Ship"
   - Add tracking number (optional)
   - Submit shipment
3. Verify in Bento

**Expected Event:** `$OrderShipped`

---

### Test 4: Abandoned Cart Event

**Purpose:** Verify abandoned cart tracking

**Setup:**
```bash
# For faster testing, reduce delay
bin/magento config:set artlounge_bento/abandoned_cart/delay_minutes 1
```

**Steps:**
1. Open storefront in incognito window
2. Add items to cart
3. Proceed to checkout
4. Enter email address
5. **Do NOT complete the order**
6. Wait for configured delay (1 minute for testing)

**Verification:**

```bash
# If using cron method, run cron
bin/magento cron:run

# Check scheduled carts
mysql -e "SELECT * FROM artlounge_bento_abandoned_cart_schedule ORDER BY scheduled_at DESC LIMIT 5"

# Check logs
tail -f var/log/bento.log
```

**Check in Bento:**
- Look for `$abandoned` event
- Verify cart items are included
- Verify recovery URL is present (if configured)

**Expected Payload:**
```json
{
  "event_type": "$abandoned",
  "cart": {
    "quote_id": 98765,
    "abandoned_duration_minutes": 62
  },
  "items": [...],
  "customer": {
    "email": "guest@example.com"
  },
  "recovery": {
    "cart_url": "https://artlounge.in/checkout/cart?recover=..."
  }
}
```

---

### Test 5: Customer Registration Event

**Purpose:** Verify new customer events

**Steps:**
1. Create new customer account on storefront
2. Complete registration form
3. Verify in Bento

**Expected Event:** `$Subscriber` with tags `["lead", "mql"]`

---

### Test 6: Newsletter Subscription Event

**Purpose:** Verify newsletter events

**Steps:**
1. Find newsletter signup form on storefront
2. Enter email and subscribe
3. Verify in Bento

**Expected Event:** `$subscribe` with tag `["newsletter"]`

**For Unsubscribe:**
1. Use unsubscribe link in any email
2. Verify `$unsubscribe` event in Bento

---

### Test 7: Product View Tracking (Client-Side)

**Purpose:** Verify browser-side tracking

**Steps:**
1. Open browser developer tools (F12)
2. Navigate to Network tab
3. Visit a product page
4. Filter for "bentonow.com"

**Verification:**
- Look for request to `app.bentonow.com`
- Check Console for: `[Bento] Product view tracked: Product Name`

**In Browser Console:**
```javascript
// Manually trigger to test
bento.track('$view', {
  unique_key: '12345',
  details: { product_id: 12345, name: 'Test Product' }
});
```

---

### Test 8: Add to Cart Tracking

**Purpose:** Verify add to cart events

**Steps:**
1. Open browser developer tools
2. Navigate to a product page
3. Click "Add to Cart"
4. Check Console for: `[Bento] Add to cart tracked: Product Name, qty: 1`

---

### Test 9: Queue Consumer Processing

**Purpose:** Verify queue consumers are processing events

**Steps:**
```bash
# Start consumers in foreground to watch output
bin/magento queue:consumers:start event.trigger.consumer --max-messages=10

# In another terminal, place an order
# Watch the consumer output for processing
```

**Expected Output:**
```
Processing message for event: bento.order.placed
Successfully notified subscription 1 for event bento.order.placed
```

---

### Test 10: Retry Mechanism

**Purpose:** Verify failed events are retried

**Setup:**
```bash
# Temporarily set an invalid Bento secret key
bin/magento config:sensitive:set artlounge_bento/general/secret_key "invalid_secret_key"
```

**Steps:**
1. Place a test order
2. Watch retry consumer:
   ```bash
   bin/magento queue:consumers:start event.retry.consumer
   ```
3. Verify retries occur with exponential backoff

**Verification:**
- Check Async Events Logs for retry attempts
- Verify delay between attempts increases

**Cleanup:**
```bash
# Restore valid secret key
bin/magento config:sensitive:set artlounge_bento/general/secret_key "your_real_secret_key"
```

---

## Event Verification Checklist

Use this checklist to verify all events are working:

### Server-Side Events

| Event | Trigger | Bento Event | Verified |
|-------|---------|-------------|----------|
| Order Placed | Complete checkout | `$purchase` | ☐ |
| Order Shipped | Create shipment | `$OrderShipped` | ☐ |
| Order Cancelled | Cancel order | `$OrderCanceled` | ☐ |
| Order Refunded | Create credit memo | `$OrderRefunded` | ☐ |
| Customer Created | Register account | `$Subscriber` | ☐ |
| Customer Updated | Update profile | `$CustomerUpdated` | ☐ |
| Newsletter Subscribe | Subscribe | `$subscribe` | ☐ |
| Newsletter Unsubscribe | Unsubscribe | `$unsubscribe` | ☐ |
| Abandoned Cart | Leave cart idle | `$abandoned` | ☐ |

### Client-Side Events

| Event | Trigger | Bento Event | Verified |
|-------|---------|-------------|----------|
| Product View | Visit product page | `$view` | ☐ |
| Add to Cart | Click add to cart | `$addToCart` | ☐ |
| Checkout Started | Enter checkout | `$checkoutStarted` | ☐ |

### Data Verification

For each event, verify:

- ☐ Event appears in Bento dashboard
- ☐ Customer email is correct
- ☐ Monetary values are in cents (multiplied by 100)
- ☐ Currency code is correct (INR)
- ☐ Product data is complete
- ☐ Categories are captured
- ☐ Tags are applied correctly

---

## Debugging Guide

### Enable Debug Logging

```bash
bin/magento config:set artlounge_bento/general/debug 1
```

### Log Locations

| Log | Path | Content |
|-----|------|---------|
| Bento Log | `var/log/bento.log` | All Bento-related activity |
| System Log | `var/log/system.log` | General Magento logs |
| Exception Log | `var/log/exception.log` | PHP exceptions |
| Queue Log | Supervisor logs | Consumer output |

### Common Issues

#### Events Not Appearing in Bento

1. **Check if enabled:**
   ```bash
   bin/magento config:show artlounge_bento/general/enabled
   ```

2. **Check queue consumers:**
   ```bash
   supervisorctl status
   # or
   ps aux | grep queue:consumers
   ```

3. **Check Async Events logs:**
   - Admin > System > Async Events > Logs
   - Look for failed events

4. **Check Bento log:**
   ```bash
   tail -100 var/log/bento.log
   ```

#### Abandoned Cart Not Triggering

1. **Check configuration:**
   ```bash
   bin/magento config:show artlounge_bento/abandoned_cart
   ```

2. **Check scheduled carts:**
   ```sql
   SELECT * FROM artlounge_bento_abandoned_cart_schedule
   WHERE status = 'pending'
   ORDER BY check_at ASC
   LIMIT 10;
   ```

3. **Check cron is running:**
   ```bash
   bin/magento cron:status
   ```

4. **Check conditions:**
   - Email is captured
   - Cart total >= minimum value
   - Customer group not excluded
   - Processing method matches setup

#### Client-Side Tracking Not Working

1. **Check Bento script is loaded:**
   - View page source
   - Search for "bentonow.com"

2. **Check browser console:**
   - Look for JavaScript errors
   - Look for `[Bento]` log messages

3. **Check tracking is enabled:**
   ```bash
   bin/magento config:show artlounge_bento/tracking/enabled
   ```

### Replay Failed Events

From Admin:

1. Navigate to: System > Async Events > Logs
2. Filter by: Success = No
3. Select failed events
4. Click "Replay" action

---

## Performance Testing

### Load Test Considerations

Before load testing:

1. Ensure queue consumers can handle volume
2. Monitor RabbitMQ queue depth
3. Monitor Bento API response times

### Queue Depth Monitoring

```bash
# Check RabbitMQ queue depth
rabbitmqctl list_queues name messages consumers

# Watch in real-time
watch -n 5 'rabbitmqctl list_queues name messages'
```

**Healthy Metrics:**
- `event.trigger` queue: < 100 messages
- Consumer count: >= 1 per queue
- No growing backlog

### Response Time Monitoring

Add to monitoring dashboard:
- Bento API response times (from logs)
- Queue processing latency
- Event delivery success rate

### Stress Test Procedure

```bash
# Generate load with test orders
# Use Magento's performance toolkit or custom script

# Monitor during test:
# Terminal 1: Queue depth
watch -n 1 'rabbitmqctl list_queues name messages'

# Terminal 2: Consumer output
tail -f /var/log/magento/queue-event-trigger.out.log

# Terminal 3: Bento log
tail -f var/log/bento.log | grep -E "(SUCCESS|ERROR)"
```

---

## Post-Testing Cleanup

After testing:

```bash
# Restore production delay
bin/magento config:set artlounge_bento/abandoned_cart/delay_minutes 60

# Disable debug mode
bin/magento config:set artlounge_bento/general/debug 0

# Clear old test data
mysql -e "DELETE FROM artlounge_bento_abandoned_cart_schedule WHERE customer_email LIKE '%test%'"

# Flush cache
bin/magento cache:flush
```

---

## Sign-Off Checklist

Before going live:

- [ ] All automated tests pass
- [ ] All manual tests verified
- [ ] Queue consumers running in production
- [ ] Supervisor/systemd configured for auto-restart
- [ ] Debug mode disabled
- [ ] Production Bento credentials configured
- [ ] First real order verified in Bento
- [ ] Abandoned cart flow verified end-to-end
- [ ] Monitoring/alerting configured

---

*Document prepared for Art Lounge Magento 2 Integration Project*
