# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Magento 2 integration package that connects Art Lounge's e-commerce store with Bento email marketing platform using the Aligent Async Events framework. It replaces synchronous webhook processing with reliable, non-blocking queue-based event delivery.

## Architecture

### Three-Module Structure

```
modules/ArtLounge/
├── BentoCore/      # Shared configuration, API client, admin UI
├── BentoEvents/    # Server-side event observers and services
└── BentoTracking/  # Client-side JavaScript tracking
```

**BentoCore** provides:
- `Model/Config.php` - Central configuration access (all admin settings)
- `Model/BentoClient.php` - HTTP client using official Bento API with Basic Auth
- Admin system configuration (`etc/adminhtml/system.xml`)
- Test connection controller

**BentoEvents** provides:
- Observers in `Observer/` that listen to Magento events and publish to the queue
- Service classes in `Service/` that format data for Bento (OrderService, CustomerService, etc.)
- Abandoned cart scheduler/checker in `Model/AbandonedCart/`
- Event definitions in `etc/async_events.xml`

**BentoTracking** provides:
- ViewModel for tracking data injection
- Layout XML files for frontend pages
- PHTML templates that inject Bento JavaScript

### Event Flow

1. Magento event fires (e.g., `sales_order_place_after`)
2. Observer publishes message to RabbitMQ queue
3. Aligent Async Events consumer processes the message
4. Service class formats the data payload
5. HTTP notifier sends to Bento API with retry on failure

### Key Dependencies

- `aligent/async-events ^3.0` - Queue-based async event framework
- `ramsey/uuid ^4.0` - UUID generation for event tracing
- Requires RabbitMQ (or falls back to database queue)
- PHP 8.1+, Magento 2.4.4+

## Common Commands

### Installation
```bash
# Install dependencies
composer require aligent/async-events

# Copy modules
cp -r modules/ArtLounge/* app/code/ArtLounge/

# Enable and setup
bin/magento module:enable ArtLounge_BentoCore ArtLounge_BentoEvents ArtLounge_BentoTracking
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Queue Consumers
```bash
# Start consumers (production uses Supervisor)
bin/magento queue:consumers:start event.trigger.consumer
bin/magento queue:consumers:start event.retry.consumer

# Check queue status
rabbitmqctl list_queues name messages consumers
```

### Testing
```bash
# Run all unit tests
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
  app/code/ArtLounge/BentoCore/Test/Unit \
  app/code/ArtLounge/BentoEvents/Test/Unit

# Run specific test
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
  app/code/ArtLounge/BentoEvents/Test/Unit/Service/OrderServiceTest.php

# Run integration tests
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist \
  app/code/ArtLounge/BentoEvents/Test/Integration
```

### Debugging
```bash
# Enable debug mode
bin/magento config:set artlounge_bento/general/debug 1

# Check logs
tail -f var/log/bento.log

# View async events admin logs
# Navigate to: System > Async Events > Logs
```

## Configuration Paths

All configuration is under `artlounge_bento/` in the config store:
- `artlounge_bento/general/enabled` - Master enable switch
- `artlounge_bento/general/site_uuid` - Bento site identifier
- `artlounge_bento/general/publishable_key` - Client-side API key
- `artlounge_bento/general/secret_key` - Server-side API key (encrypted)
- `artlounge_bento/abandoned_cart/delay_minutes` - Cart abandonment delay
- `artlounge_bento/abandoned_cart/min_value` - Minimum cart value threshold

## Bento Event Types

Server-side events map to Bento event names:
- `bento.order.placed` → `$purchase`
- `bento.order.shipped` → `$OrderShipped`
- `bento.order.refunded` → `$OrderRefunded`
- `bento.customer.created` → `$Subscriber`
- `bento.newsletter.subscribed` → `$subscribe`
- `bento.cart.abandoned` → `$abandoned`

Client-side events: `$view`, `$addToCart`, `$checkoutStarted`, `$purchase` (success page fallback)

## Important Patterns

- All monetary values are sent in cents (multiply by currency multiplier, default 100)
- Services return data arrays that are formatted for Bento's batch events API
- Uses official Bento API: `POST https://app.bentonow.com/api/v1/batch/events`
- Authentication: Basic Auth with `publishable_key:secret_key` (Base64 encoded)
- `site_uuid` is passed in the JSON body (per official bento-php-sdk), not in query string
- Required header: `User-Agent` (Cloudflare blocks requests without it)
- Failed events retry with exponential backoff (delay = min(60, attempt²) seconds)
- Abandoned cart uses either queue delay or cron-based checking (configurable)

## Retry Handling (Important!)

Aligent's async events framework retries ALL failures (`success=false`) until max deaths (default 5). This creates a problem for permanent failures (400 Bad Request, 401 Auth errors, missing email) which will never succeed on retry.

### How We Handle This

The `BentoNotifier` implements smart retry prevention:

| Error Type | Retryable? | How Handled |
|------------|------------|-------------|
| 429 Rate Limit | Yes | `success=false` → Aligent retries with backoff |
| 500, 502, 503, 504 Server Errors | Yes | `success=false` → Aligent retries with backoff |
| Network/Timeout Exceptions | Yes | `success=false` → Aligent retries with backoff |
| 400 Bad Request | **No** | `success=true` + `permanent_failure=true` in responseData |
| 401 Unauthorized | **No** | `success=true` + `permanent_failure=true` in responseData |
| 403 Forbidden | **No** | `success=true` + `permanent_failure=true` in responseData |
| Missing Email | **No** | `success=true` + `permanent_failure=true` in responseData |
| Module Disabled | **No** | `success=true` + `permanent_failure=true` in responseData |

### Why success=true for Permanent Failures?

Since `NotifierResult` has no `retryable` flag, we use `success=true` to prevent Aligent from retrying. The actual failure details are preserved in `responseData`:

```json
{
  "success": false,
  "permanent_failure": true,
  "failure_code": "http_400",
  "message": "Bad Request",
  "retryable": false,
  "note": "Marked as success to prevent retry - see failure_code and message for actual status"
}
```

### Identifying Permanent Failures in Logs

Search for "PERMANENT FAILURE" in logs:
```bash
grep "PERMANENT FAILURE" var/log/bento.log
```

Or query Aligent's async event logs where `response_data` contains `"permanent_failure":true`.

## Bento API Format

Events are sent to Bento in the official SDK format (verified against bento-php-sdk and bento-node-sdk):

```json
{
  "events": [{
    "type": "$purchase",
    "email": "customer@example.com",
    "fields": {
      "first_name": "John",
      "last_name": "Doe"
    },
    "details": {
      "unique": {
        "key": "100000123"
      },
      "value": {
        "amount": 9999,
        "currency": "USD"
      },
      "order": { ... },
      "cart": { "items": [...] }
    }
  }]
}
```

Key format requirements (per official SDK):
- `unique.key` - Object with `key` property (not a plain string) - uses order increment_id for deduplication
- `value.amount` - Integer in cents (9999 = $99.99)
- `value.currency` - ISO currency code

The `BentoClient` automatically transforms the service data into this format.

## Varnish/ESI Considerations

### Current Implementation Status

The BentoTracking module has partial Varnish compatibility:

| Template | Varnish Safe? | Notes |
|----------|---------------|-------|
| `bento_script.phtml` | ✅ Yes | Publishable key is same for all users |
| `product_tracking.phtml` | ✅ Yes | Product data is same for all users viewing same product |
| `checkout_tracking.phtml` | ✅ Yes | Uses `Magento_Customer/js/customer-data` (private content via AJAX) |
| `purchase_tracking.phtml` | ✅ Yes | Success page is not cached (session-dependent, one-time view) |

### Known Issues to Address

1. **Missing `cacheable="false"` on blocks** - While current implementation works, add explicit cache attributes if any user-specific data is added to ViewModels
2. **No ESI blocks defined** - If you need user-specific data in templates, use ESI or private content sections

### If Adding User-Specific Data

For any customer-specific tracking data, use Magento's private content system:
```javascript
// In template, use customer-data sections (loaded via AJAX, bypasses Varnish)
require(['Magento_Customer/js/customer-data'], function(customerData) {
    var customer = customerData.get('customer');
    // Use customer().email, etc.
});
```

## Browser-Side Tracking Implementation

### Varnish/FPC Compatibility

The tracking templates render NO user-specific data in HTML. All user data is loaded via Magento's private content system:

```
1. Varnish serves cached HTML (identical for all users)
2. JavaScript calls customerData.get('customer') / customerData.get('cart')
3. Magento makes AJAX request to /customer/section/load/
4. Response contains user email (if logged in) or cart email (if entered at checkout)
5. bento.identify() is called with the email
```

This allows full page caching while still tracking user-specific data.

### User Identification

The `bento_script.phtml` template automatically identifies users:
- Logged-in customers: identified from `customerData.get('customer').email`
- Guest users: identified from `customerData.get('cart').customer_email` (when entered at checkout)
- Both use AJAX-loaded private content, making it Varnish-safe

### Browse Abandonment Support

Product views are stored in localStorage for browse abandonment campaigns:

1. **Immediate tracking**: `bento.track('$view')` is called when product page loads (works for users with Bento cookie)
2. **localStorage storage**: View is also stored via `bentoBrowseHistory.addView()`
3. **Sync on identification**: When user is identified (login, checkout, newsletter), stored views are sent to Bento

**Deduplication strategy**:
- Uses `unique: { key: productId_YYYY-MM-DD }` (date-based, per Bento JS SDK format)
- Same product on different days = different events (allows tracking return visits)
- Same product multiple times same day = deduplicated by Bento
- Sent views are tracked in `bento_browse_history_sent` localStorage key
- Views expire after 7 days

**localStorage keys**:
- `bento_browse_history` - Array of stored product views
- `bento_browse_history_sent` - Array of viewKeys already sent to Bento

### Event Deduplication

All client-side events include deduplication to prevent duplicate tracking. Events use the official Bento JS SDK format: `unique: { key: "..." }`.

| Event | Deduplication Method |
|-------|---------------------|
| `$view` | Date-based key (productId_YYYY-MM-DD) + localStorage sent tracking |
| `$addToCart` | 2-second debounce + timestamp-based unique key |
| `$checkoutStarted` | Session storage with 30-minute expiry per cart ID |
| `$purchase` | Order increment_id (same as server-side for cross-channel dedupe) |

### Configurable Product Support

The `$addToCart` event captures selected options for configurable products (size, color, etc.) in the `selected_options` field.

## Migration: Removing Existing Bento Scripts

Before enabling ArtLounge_BentoTracking, remove any existing Bento JavaScript to prevent duplicate events.

### Step 1: Search for Existing Bento Code

```bash
# Search theme files
grep -r "bentonow.com" app/design/
grep -r "bento.track" app/design/
grep -r "bento.identify" app/design/

# Search for Bento in CMS blocks/pages (check database)
mysql -e "SELECT * FROM cms_block WHERE content LIKE '%bentonow%' OR content LIKE '%bento.track%'"
mysql -e "SELECT * FROM cms_page WHERE content LIKE '%bentonow%' OR content LIKE '%bento.track%'"

# Check for other Bento extensions
bin/magento module:status | grep -i bento

# Search in Mageplaza webhooks if installed
bin/magento config:show mageplaza_webhook
```

### Step 2: Common Locations to Check

1. **Theme `default_head_blocks.xml`** - Check for script injection
   ```
   app/design/frontend/[Vendor]/[Theme]/Magento_Theme/layout/default_head_blocks.xml
   ```

2. **Theme `default.xml`** - Check `before.body.end` container
   ```
   app/design/frontend/[Vendor]/[Theme]/Magento_Theme/layout/default.xml
   ```

3. **CMS Blocks** - Admin > Content > Blocks, search for "bento"

4. **Google Tag Manager** - If GTM is used, check for Bento tags in the GTM container

5. **Mageplaza Webhooks** - Admin > Marketing > Webhooks, disable any Bento webhooks

6. **Third-party extensions** - Check `app/code/` for any Bento-related modules

### Step 3: Disable/Remove Existing Scripts

```bash
# If there's a theme layout adding Bento script, override it
# Create: app/design/frontend/[Vendor]/[Theme]/ArtLounge_BentoTracking/layout/default.xml
# With empty referenceBlock to remove old implementation

# Or disable at theme level if it's a separate block
<referenceBlock name="existing.bento.script" remove="true"/>
```

### Step 4: Verify No Duplicates

After enabling the new module:
```bash
# Check frontend source for duplicate scripts
curl -s https://your-store.com/ | grep -c "bentonow.com"
# Should return: 1

# In browser console, verify single Bento instance
typeof bento  // Should be 'object', not duplicated
```

### Step 5: Test Event Deduplication

1. Open browser Network tab, filter by "bentonow"
2. View a product page - should see ONE `$view` event
3. Add to cart - should see ONE `$addToCart` event
4. Go to checkout - should see ONE `$checkoutStarted` event

If you see duplicate events, there's still old code present somewhere.
