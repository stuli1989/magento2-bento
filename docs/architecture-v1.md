# Bento Integration Plugin - Complete Architecture Guide

This document explains how the entire plugin works, end to end, so you can get comfortable with the codebase quickly. It covers what each piece does, how the pieces fit together, why certain decisions were made, and what trade-offs exist.

---

## Table of Contents

1. [The Big Picture](#the-big-picture)
2. [The Three Modules](#the-three-modules)
3. [How Events Flow Through the System](#how-events-flow-through-the-system)
4. [Module 1: BentoCore (The Foundation)](#module-1-bentocore)
5. [Module 2: BentoEvents (The Server-Side Engine)](#module-2-bentoevents)
6. [Module 3: BentoTracking (The Client-Side Tracker)](#module-3-bentotracking)
7. [The Abandoned Cart System (Deep Dive)](#the-abandoned-cart-system)
8. [Cart Recovery Links](#cart-recovery-links)
9. [The Retry and Failure System](#the-retry-and-failure-system)
10. [Configuration Architecture](#configuration-architecture)
11. [How Money is Handled](#how-money-is-handled)
12. [Varnish and Caching Compatibility](#varnish-and-caching-compatibility)
13. [Event Deduplication Strategy](#event-deduplication-strategy)
14. [Design Decisions and Trade-Offs](#design-decisions-and-trade-offs)
15. [Outbox Fallback System](#outbox-fallback-system)
16. [Dead-Letter Queue Improvements](#dead-letter-queue-improvements)
17. [Anonymous View Replay (Client-Side)](#anonymous-view-replay-client-side)
18. [bento-identity Customer Data Section](#bento-identity-customer-data-section)
19. [ServiceDataResolver Pattern](#servicedataresolver-pattern)
20. [Event Naming Convention](#event-naming-convention)
21. [Value Format Requirement](#value-format-requirement)
22. [File-by-File Reference](#file-by-file-reference)

---

## The Big Picture

This plugin connects Art Lounge's Magento 2 store to Bento, an email marketing platform. When things happen on the store (someone places an order, signs up for a newsletter, abandons their cart, etc.), the plugin sends that information to Bento so it can trigger automated email campaigns.

The key design principle is **non-blocking**: none of this tracking should slow down the customer's experience. If a customer places an order, the order should go through immediately. The Bento notification happens in the background, asynchronously, via a message queue.

This is built on top of the **Aligent Async Events** framework (a third-party Magento extension). Aligent provides the queue infrastructure (publish/consume messages, retry on failure, dead letter handling). Our plugin provides the Bento-specific logic: what data to collect, how to format it, and how to send it to Bento's API.

---

## The Three Modules

The plugin is split into three Magento modules. Each has a focused responsibility:

```
modules/ArtLounge/
  BentoCore/       The shared foundation
  BentoEvents/     Server-side event processing
  BentoTracking/   Client-side JavaScript tracking
```

### Why three modules instead of one?

Separation of concerns. A store might want server-side events without the JavaScript tracking, or vice versa. Splitting them means you can enable/disable each independently. BentoCore is the shared base that both depend on.

The dependency chain is:

```
BentoCore  <--  BentoEvents
BentoCore  <--  BentoTracking
```

BentoEvents and BentoTracking never depend on each other. They both depend on BentoCore.

---

## How Events Flow Through the System

There are two separate event pipelines: server-side and client-side. Here's how each works.

### Server-Side Pipeline (BentoEvents)

```
Step 1: Something happens in Magento
        (customer places order, signs up, etc.)

Step 2: Magento fires an internal event
        (e.g. sales_order_place_after)

Step 3: Our Observer catches the event
        (Observer/Sales/OrderPlaced.php)

Step 4: The Observer publishes a tiny message to the queue
        (just the entity ID, like {"id": 42})

Step 5: Aligent's queue consumer picks up the message
        (this happens in a separate background process)

Step 6: Aligent calls our BentoNotifier
        (Model/BentoNotifier.php)

Step 7: BentoNotifier asks the Service class to load and format the data
        (Service/OrderService.php loads order #42 and builds a data array)

Step 8: BentoNotifier passes the data to BentoClient
        (Model/BentoClient.php)

Step 9: BentoClient formats it into Bento's expected JSON structure
        and sends an HTTP POST to Bento's API

Step 10: If it fails, Aligent's framework handles the retry
```

**Why publish just the ID, not the full data?**

Two reasons:
1. Queue messages should be small. Sending a full order with all its items, addresses, and product images as a queue message would be wasteful.
2. By loading the data at processing time (not at publish time), we always get the freshest version. If an order gets updated between when it's published and when it's processed, the consumer sees the latest state.

### Client-Side Pipeline (BentoTracking)

```
Step 1: Customer visits a page on the store

Step 2: The Bento JavaScript library loads
        (injected by bento_script.phtml on every page)

Step 3: JavaScript identifies the user
        (loads email from Magento's private content AJAX system)

Step 4: Page-specific tracking fires
        (product view, add to cart, checkout started, or purchase)

Step 5: Bento's JS library sends the event directly to Bento
        (no queue involved, it's a direct browser-to-Bento API call)
```

The client-side pipeline is simpler because there's no queue. The browser talks directly to Bento's servers.

---

## Module 1: BentoCore

**Location:** `modules/ArtLounge/BentoCore/`

BentoCore provides three things that the other two modules both need: configuration, the API client, and logging.

### Config.php - The Central Configuration

**File:** `Model/Config.php` (implements `Api/ConfigInterface.php`)

Every configurable setting in the plugin flows through this single class. It reads values from Magento's `core_config_data` table (the same system that backs Stores > Configuration in the admin panel).

**How configuration is organized:**

All config paths start with `artlounge_bento/` and are grouped into sections:

| Section | Example Path | What It Controls |
|---------|-------------|-----------------|
| `general/` | `artlounge_bento/general/enabled` | Master on/off, API keys, debug mode |
| `orders/` | `artlounge_bento/orders/track_placed` | Which order events to track |
| `customers/` | `artlounge_bento/customers/default_tags` | Customer event settings |
| `newsletter/` | `artlounge_bento/newsletter/subscribe_tags` | Newsletter subscribe/unsubscribe |
| `abandoned_cart/` | `artlounge_bento/abandoned_cart/delay_minutes` | Abandoned cart timing and rules |
| `tracking/` | `artlounge_bento/tracking/track_views` | Client-side JS tracking settings |
| `advanced/` | `artlounge_bento/advanced/max_retries` | Retry limits, timeouts, log retention |

**The hierarchical enable pattern:**

This is an important design concept. Many methods in Config don't just check their own flag - they check a chain of flags. For example:

```php
public function isTrackViewsEnabled(?int $storeId = null): bool
{
    return $this->isEnabled($storeId)           // Master switch ON?
        && $this->isTrackingEnabled($storeId)    // Tracking section ON?
        && $this->getFlag('tracking/track_views', $storeId);  // This specific flag ON?
}
```

This means turning off the master switch (`general/enabled`) disables everything. Turning off the tracking section disables all JS tracking but not server-side events. You can be very granular about what's active.

**Multi-store support:**

Almost every method accepts an optional `$storeId` parameter. This means different Magento store views can have different Bento configurations (different API keys, different events enabled, etc.). When `$storeId` is null, Magento uses the current store scope.

**Secret key encryption:**

The Bento secret key is stored encrypted in the database (using Magento's `Encrypted` backend model in `system.xml`). Config.php decrypts it on read using Magento's `EncryptorInterface`. If decryption fails (corrupted data, changed encryption key), it returns `null` rather than crashing.

**Debug logging:**

The `debug()` method is a convenience wrapper. It only actually logs when debug mode is enabled in admin. This means you can sprinkle `$this->config->debug(...)` calls everywhere without performance impact in production (when debug is off, the log call is a no-op).

### BentoClient.php - The HTTP Client

**File:** `Model/BentoClient.php` (implements `Api/BentoClientInterface.php`)

This is the class that actually talks to Bento's API over HTTP. It has two main jobs:

**1. Sending events (`sendEvent`)**

Takes an event type (like `$purchase`) and a data array (from a Service class), transforms it into Bento's expected JSON format, and POSTs it to `https://app.bentonow.com/api/v1/batch/events`.

The Bento API expects a very specific JSON structure:

```json
{
  "site_uuid": "your-site-uuid",
  "events": [{
    "type": "$purchase",
    "email": "customer@example.com",
    "fields": {
      "first_name": "John",
      "last_name": "Doe"
    },
    "details": {
      "unique": { "key": "100000123" },
      "value": { "amount": 25000, "currency": "INR" },
      "order": { ... },
      "cart": { "items": [...] }
    }
  }]
}
```

BentoClient's `formatEventForBento()` method does the heavy lifting of transforming our flat data arrays into this nested structure. Key things it handles:

- **Email extraction**: Looks for the email in three places: top-level `email`, nested `customer.email`, or nested `subscriber.email`. Throws `MissingEmailException` if none found.
- **Subscriber fields**: Extracts first/last name and tags from either the `customer` or `subscriber` sub-arrays.
- **Event details**: Builds the `unique.key` (uses order increment_id for deduplication when available), `value` (financial data), and all event-specific data.
- **Authentication**: Uses HTTP Basic Auth with `base64(publishable_key:secret_key)`.
- **Required headers**: Includes a `User-Agent` header because Cloudflare (which sits in front of Bento) blocks requests without one.

**2. Testing connectivity (`testConnection`)**

Sends a harmless test event to verify the API credentials work. Used by the "Test Connection" button in admin. Returns human-readable error messages for common problems (wrong credentials, wrong site UUID, can't reach the server).

**The return value pattern:**

`sendEvent` always returns a structured array:

```php
[
    'success' => true/false,
    'message' => 'Human-readable description',
    'status_code' => 200,
    'retryable' => true/false,  // Can this error be fixed by trying again?
    'event_uuid' => 'abc-123',  // For tracing in logs
    'response' => '...'         // Raw API response body
]
```

The `retryable` flag is critical for the retry system (explained later).

### The Dedicated Logger

Defined in `BentoCore/etc/di.xml`, a virtual type called `ArtLoungeBentoLogger` is configured to write to `var/log/bento.log`. This is injected into every class across all three modules, so all Bento-related log output goes to one file rather than being scattered across Magento's various log files.

### Admin Configuration UI

**File:** `etc/adminhtml/system.xml`

This creates the configuration page at Stores > Configuration > ArtLounge > Bento Integration. It defines all the fields, their types (text, select, multiselect, encrypted, etc.), default values, and visual grouping. The "Test Connection" button is rendered by a custom block class (`Block/Adminhtml/System/Config/TestConnection`).

---

## Module 2: BentoEvents

**Location:** `modules/ArtLounge/BentoEvents/`

This module is the server-side event engine. It has four main components: observers, services, the notifier, and the abandoned cart subsystem.

### Observers - Catching Magento Events

**Location:** `Observer/`

Each observer watches for a specific Magento event and publishes a message to the queue. All eight observers follow the exact same pattern:

```php
public function execute(Observer $observer): void
{
    // 1. Extract the entity from the event
    $order = $observer->getEvent()->getOrder();
    if (!$order) return;

    // 2. Check if this event type is enabled
    if (!$this->config->isTrackOrderPlacedEnabled($order->getStoreId())) return;

    // 3. Serialize just the entity ID
    $args = $this->serializer->serialize(['id' => (int)$order->getEntityId()]);

    // 4. Publish to the queue
    $this->publisher->publish('event.trigger', ['bento.order.placed', $args]);
}
```

The observers are intentionally thin. They don't load any extra data, don't format anything, and don't make HTTP calls. Their only job is to say "something happened, here's the ID, deal with it later."

**Error handling in observers:** If the queue publish fails, the error is caught and logged, but the original Magento action (order placement, etc.) is NOT disrupted. A Bento tracking failure should never block a customer from completing their purchase.

Here are all eight observers and what they watch:

| Observer | Magento Event | Queue Message | Config Check |
|----------|--------------|---------------|-------------|
| `OrderPlaced` | `sales_order_place_after` | `bento.order.placed` | `isTrackOrderPlacedEnabled` |
| `OrderShipped` | `sales_order_shipment_save_after` | `bento.order.shipped` | `isTrackOrderShippedEnabled` |
| `OrderRefunded` | `sales_order_creditmemo_save_after` | `bento.order.refunded` | `isTrackOrderRefundedEnabled` |
| `OrderStatusChanged` | `sales_order_save_after` | `bento.order.cancelled` | `isTrackOrderCancelledEnabled` |
| `CustomerCreated` | `customer_register_success` | `bento.customer.created` | `isTrackCustomerCreatedEnabled` |
| `CustomerUpdated` | `customer_save_after` | `bento.customer.updated` | `isTrackCustomerUpdatedEnabled` |
| `SubscriberChanged` | `newsletter_subscriber_save_after` | `bento.newsletter.subscribed` or `.unsubscribed` | `isTrackSubscribeEnabled` / `isTrackUnsubscribeEnabled` |
| `QuoteSaved` | `sales_quote_save_after` | (delegates to Scheduler) | `isAbandonedCartEnabled` |

**Two observers worth highlighting:**

**`OrderStatusChanged`** is more complex than the others. There's no dedicated "order cancelled" event in Magento. Instead, this observer listens to ALL order saves and filters for cancellations by checking:
1. Is the order state now `canceled`?
2. Did the state actually change? (via `dataHasChangedFor('state')`)

Without check #2, it would fire every time a cancelled order is re-saved for any reason.

**`CustomerUpdated`** has a deduplication mechanism. Magento sometimes fires `customer_save_after` multiple times in a single HTTP request (during multi-step customer operations). The observer keeps a `$processedIds` array in memory and skips customer IDs it has already published. It also checks if an "original" customer data object exists -- if not, this is a new customer (handled by `CustomerCreated`), so it skips to avoid double-tracking.

**`SubscriberChanged`** is a single observer that handles both subscribe and unsubscribe. It reads the subscriber's current status and publishes to different queue topics accordingly. Statuses other than "subscribed" or "unsubscribed" (like "pending" or "unconfirmed") are ignored.

### Services - Loading and Formatting Data

**Location:** `Service/`

When the queue consumer processes a message, it needs the full data payload to send to Bento. That's what the service classes do: they load the Magento entity by ID and format it into a structured array.

**The mapping between events and services is defined in `etc/async_events.xml`:**

| Event | Service Class | Method |
|-------|--------------|--------|
| `bento.order.placed` | `OrderService` | `getOrderData` |
| `bento.order.shipped` | `ShipmentService` | `getShipmentData` |
| `bento.order.cancelled` | `OrderService` | `getOrderData` |
| `bento.order.refunded` | `RefundService` | `getRefundData` |
| `bento.customer.created` | `CustomerService` | `getCustomerData` |
| `bento.customer.updated` | `CustomerService` | `getCustomerData` |
| `bento.newsletter.subscribed` | `NewsletterService` | `getSubscriberData` |
| `bento.newsletter.unsubscribed` | `NewsletterService` | `getSubscriberData` |
| `bento.cart.abandoned` | `AbandonedCartService` | `getAbandonedCartData` |

Each service has two public methods:
- `get*Data(int $id)` - Loads the entity by ID, then calls the format method
- `format*Data($entity)` - Takes the loaded entity and builds the data array

The split exists so you can call `formatOrderData()` directly when you already have the order object (used by `ShipmentService` and `RefundService` which need to include the parent order data).

#### OrderService (the most complex one)

**File:** `Service/OrderService.php`

Builds a comprehensive data array including:

- **Order metadata**: entity ID, increment ID, timestamps, status, state
- **Financials**: grand total, subtotal, shipping, discount, tax, currency -- all in cents
- **Items**: Each line item with product ID, SKU, name, quantity, price, row total, optionally product URL/image/categories
- **Customer info**: email, name, customer ID, group name
- **Addresses**: billing and shipping (street, city, region, postcode, country)
- **Payment**: method code and title
- **Shipping**: method code and description
- **Flags**: is_discounted, is_virtual, is_guest
- **Summary**: product names list, item count, unique categories list

**Child item filtering**: For configurable products (e.g., a T-shirt with size/color options), Magento creates both a parent item and a child item. The service filters out children (items where `getParentItemId()` returns a value) to avoid double-counting.

**Tax handling**: The grand total can optionally include or exclude tax, controlled by the `includeTaxInTotals` config setting. When excluding, it subtracts `taxAmount` from `grandTotal`.

**Discount handling**: Magento stores discounts as negative numbers (e.g., -20.00 for a twenty rupee discount). The service uses `abs()` to convert to a positive number.

#### ShipmentService and RefundService (composition pattern)

**Files:** `Service/ShipmentService.php` and `Service/RefundService.php`

These two services follow a composition pattern rather than building data from scratch. They:

1. Load their entity (shipment or credit memo)
2. Get the parent order from the entity
3. Call `$this->orderService->formatOrderData($order)` to get the full order data
4. Merge their specific data on top using `array_merge()`

Since `array_merge()` overwrites duplicate keys, the `event_type` gets changed from `$purchase` to `$OrderShipped` or `$OrderRefunded`.

ShipmentService adds: shipment ID, increment ID, tracking numbers (carrier + title + number), and shipped items with quantities.

RefundService adds: credit memo ID, increment ID, refund amount, adjustment amounts, and refunded items (only items with qty > 0, handling partial refunds correctly).

**Trade-off**: Using `array_merge()` is simple but means the shipment/refund data must not accidentally overwrite important order fields. The convention is that service-specific data goes under its own key (`shipment` or `refund`), and only `event_type` is intentionally overwritten.

#### CustomerService

**File:** `Service/CustomerService.php`

Formats customer data including: ID, email, names, creation date, date of birth, gender (mapped from Magento's integer codes: 1=Male, 2=Female), customer group name (loaded from the group repository, unlike OrderService which uses a hardcoded map), and optionally the default billing address.

Tags from the config (`getDefaultTags()`) are included so new customers automatically get tagged in Bento.

#### NewsletterService

**File:** `Service/NewsletterService.php`

Handles both subscribe and unsubscribe from a single service. Determines the event type (`$subscribe` or `$unsubscribe`) based on the subscriber's status. Subscribe events include the configured tags; unsubscribe events have empty tags (you don't want to tag someone who's leaving).

**Note**: Uses `SubscriberFactory` + `load()` which is an older Magento pattern. Magento's newsletter module doesn't have a clean repository interface for subscribers, so this was the practical choice.

#### AbandonedCartService

**File:** `Service/AbandonedCartService.php`

Formats abandoned cart (quote) data including: cart metadata (quote ID, timestamps, how long it's been abandoned), financials (total, subtotal, discount calculated from `subtotal - subtotalWithDiscount`), customer info, and cart items with product images always included (essential for abandoned cart emails, unlike orders where images are configurable).

Optionally generates a cart recovery URL (see the [Cart Recovery Links](#cart-recovery-links) section).

### BentoNotifier - The Bridge to Aligent

**File:** `Model/BentoNotifier.php`

This is the most architecturally important class in BentoEvents. It implements Aligent's `NotifierInterface`, meaning it's the class that Aligent's framework calls when it's time to actually deliver an event.

**How Aligent finds it:** In `etc/di.xml`, there's a configuration that registers `BentoNotifier` with Aligent's `NotifierFactory` under the key `"bento"`. When Aligent processes a queued event that has the "bento" notifier type, it creates a `BentoNotifier` instance and calls `notify()`.

**What `notify()` does:**

1. Creates an Aligent `NotifierResult` object (the return value)
2. Checks if the module is enabled (if not, it's a permanent failure)
3. Maps the internal event name to a Bento event type using `EventTypeMapper`
4. Calls `BentoClient::sendEvent()` with the data
5. Interprets the response and decides: success, retryable failure, or permanent failure

The permanent failure handling is explained in detail in [The Retry and Failure System](#the-retry-and-failure-system).

### EventTypeMapper - Name Translation

**File:** `Model/EventTypeMapper.php`

A simple lookup table that translates internal async event names to Bento event types:

| Internal Name | Bento Event Type |
|--------------|-----------------|
| `bento.order.placed` | `$purchase` |
| `bento.order.shipped` | `$OrderShipped` |
| `bento.order.cancelled` | `$OrderCanceled` |
| `bento.order.refunded` | `$OrderRefunded` |
| `bento.customer.created` | `$Subscriber` |
| `bento.customer.updated` | `$CustomerUpdated` |
| `bento.newsletter.subscribed` | `$subscribe` |
| `bento.newsletter.unsubscribed` | `$unsubscribe` |
| `bento.cart.abandoned` | `$abandoned` |

Unknown event names pass through unchanged (safety fallback).

### Queue Configuration Files

Several XML files configure the message queue infrastructure:

- **`etc/async_events.xml`** - Tells Aligent which service class and method to call for each event type
- **`etc/events.xml`** - Registers all eight Magento event observers
- **`etc/communication.xml`** - Declares the `artlounge.abandoned.cart` queue topic (separate from Aligent's queue)
- **`etc/queue_topology.xml`** - Sets up the RabbitMQ exchange and queue binding for abandoned carts
- **`etc/queue_consumer.xml`** - Registers two consumers: one for RabbitMQ, one for database queue fallback
- **`etc/queue_publisher.xml`** - Maps the topic to both AMQP and database delivery connections

---

## Module 3: BentoTracking

**Location:** `modules/ArtLounge/BentoTracking/`

This module handles everything that happens in the customer's browser. It injects JavaScript into the store's frontend pages.

### TrackingData ViewModel

**File:** `ViewModel/TrackingData.php`

A ViewModel is Magento 2's recommended way to pass data from PHP to templates. Layout XML files inject this ViewModel into templates, which then call its methods to get the data they need.

Key methods:
- `isEnabled()`, `isProductViewEnabled()`, `isAddToCartEnabled()`, `isCheckoutEnabled()` - Enable/disable checks
- `getPublishableKey()` - For initializing the Bento JS library
- `getProductDataJson()` - Current product data as JSON (for product pages)
- `getLastOrderDataJson()` - Last order data as JSON (for success page)
- `getCurrencyCode()`, `getCurrencyMultiplier()` - For price conversion in JS
- `getCurrentProduct()` - Loads the current product from Magento's registry
- `getAddToCartSelector()` - CSS selector for the add-to-cart button

**Note about Magento's Registry**: The ViewModel uses `Magento\Framework\Registry` to get the current product, which is technically deprecated in Magento 2.4+. However, there's no clean alternative for layout-injected ViewModels that need the current product. This is a known Magento limitation.

### Layout XML Files

Four layout files inject templates into specific page types:

| Layout File | Page | Template |
|------------|------|----------|
| `default.xml` | Every page | `bento_script.phtml` |
| `catalog_product_view.xml` | Product pages | `product_tracking.phtml` |
| `checkout_index_index.xml` | Checkout page | `checkout_tracking.phtml` |
| `checkout_onepage_success.xml` | Order success page | `purchase_tracking.phtml` |

All templates are placed in the `before.body.end` container (just before `</body>`), and the page-specific ones are ordered to load after the main Bento script.

### Template: bento_script.phtml (Every Page)

This is the foundation template that loads on every page. It does three things:

**1. Loads the Bento JavaScript library**

Dynamically creates a `<script>` tag pointing to `https://app.bentonow.com/{publishableKey}.js`. If tracking is disabled or the key is missing, the template outputs nothing.

**2. Identifies the user**

This is the most important part for Varnish compatibility. The template does NOT render any user-specific data in the HTML. Instead, it uses Magento's private content system:

```javascript
// After page load, JavaScript makes an AJAX call to Magento
// to get the current user's data (email, cart contents, etc.)
var customer = customerData.get('customer');
var cart = customerData.get('cart');

// When the data arrives, identify the user to Bento
customer.subscribe(function(data) {
    if (data.email) {
        bento.identify(data.email);
    }
});
```

This means:
- Varnish can cache the full HTML (no user-specific content in it)
- After the cached page loads, JavaScript fetches user data via AJAX
- The user is identified to Bento in the browser, not in the HTML

**3. Manages browse history for browse abandonment**

Creates a `bentoBrowseHistory` object that stores product views in `localStorage`. When a user views products but doesn't identify themselves (e.g., browsing without being logged in), the views are stored locally. When the user later identifies themselves (logs in, enters email at checkout), all stored views are synced to Bento at once.

### Template: product_tracking.phtml (Product Pages)

Handles two events:

**Product View (`$view`)**: Fires immediately when the product page loads. The product data (ID, SKU, name, price, image, categories) is rendered server-side in the HTML because it's the same for all users viewing the same product (Varnish-safe).

**Add to Cart (`$cart_created` / `$cart_updated`)**: Binds a click handler to the add-to-cart button (using the configurable CSS selector from admin). Sends `$cart_created` when the cart is empty (first item), or `$cart_updated` for subsequent additions. Includes:
- 2-second debounce to prevent double-tracking on rapid clicks
- Quantity detection from the quantity input field
- Configurable product option detection (captures selected size, color, etc.)

### Template: checkout_tracking.phtml (Checkout Page)

Tracks `$checkoutStarted` with heavy deduplication:
- Page-load flag prevents multiple fires per load
- Session storage with 30-minute window per cart ID prevents re-tracking on page refreshes

Cart data is loaded entirely client-side via `customerData.get('cart')`, making it Varnish-safe.

### Template: purchase_tracking.phtml (Success Page)

Tracks `$purchase` as a client-side fallback for the server-side order event. The success page is the ONE template that renders user-specific data (the order) directly in HTML. This is safe because:
- The success page is never cached (it depends on the checkout session)
- It's shown only once per order

Uses the same `increment_id` as the server-side event for deduplication, so if both fire, Bento deduplicates them. Marks the source as `client_fallback` to distinguish from server-side events.

---

## The Abandoned Cart System

This is the most complex subsystem in the plugin. It has its own dedicated infrastructure because abandoned carts need a **delay** -- you don't want to send an "abandoned cart" email 5 seconds after someone adds an item. You want to wait (typically 60 minutes) and only send it if they haven't come back.

### The Four Classes

```
QuoteSaved (Observer)
    |
    v
Scheduler --> stores in database table + optionally publishes to queue
    |
    v
[delay period elapses]
    |
    v
Consumer (queue mode) OR ProcessAbandonedCarts (cron mode)
    |
    v
Checker --> validates the cart is truly abandoned
    |
    v
Publisher --> sends to Aligent's event.trigger queue
    |
    v
[standard event pipeline: BentoNotifier -> AbandonedCartService -> BentoClient -> Bento API]
```

### Step 1: QuoteSaved Observer

When any shopping cart (quote) is saved in Magento, this observer fires. It checks five eligibility criteria:

1. Is abandoned cart tracking enabled?
2. Is the quote active? (not already converted to an order)
3. Does the quote have at least one item?
4. Does the quote have a customer email? (configurable requirement)
5. Is the grand total above the minimum threshold?
6. Is the customer group not in the excluded list?

If all checks pass, it asks the Scheduler to schedule an abandoned cart check.

### Step 2: Scheduler

**File:** `Model/AbandonedCart/Scheduler.php`

The Scheduler does two things:

**Records the schedule in a database table** (`artlounge_bento_abandoned_cart_schedule`). This table tracks:
- Which quote was scheduled
- When it was scheduled
- When the check should happen (`check_at` = now + delay minutes)
- The quote's last update time (for freshness checking later)
- Status (pending, sent, converted, not_found, etc.)

**Optionally publishes to a queue** (if using queue-based processing). The message contains the quote ID and the expected check time.

The Scheduler uses `insertOnDuplicate()` for the database write. This means if a customer updates their cart (adding another item), the existing schedule entry is updated rather than creating a duplicate. This effectively resets the abandonment timer.

### Step 3a: Consumer (Queue Mode)

**File:** `Model/AbandonedCart/Consumer.php`

When using queue-based processing, messages arrive at the consumer. There's an important caveat: **without RabbitMQ's delayed message plugin, messages arrive immediately**. The consumer checks if the current time is past the `check_after` timestamp. If not, the message is dropped (with a log warning).

This is a known limitation. The message is dropped, but the schedule is still in the database, so the cron job can pick it up as a fallback.

### Step 3b: ProcessAbandonedCarts (Cron Mode)

**File:** `Cron/ProcessAbandonedCarts.php`

When using cron-based processing, this job runs every 5 minutes (configured in `etc/crontab.xml`). It queries the schedule table for entries where `status = 'pending'` and `check_at <= now()`, processes them in batches of 100, and also cleans up entries older than 7 days.

The cron mode is more reliable for delay enforcement because it naturally respects the `check_at` timestamp through its SQL query.

### Step 4: Checker

**File:** `Model/AbandonedCart/Checker.php`

This is the validation engine. It performs six checks before declaring a cart truly abandoned:

1. **Quote exists** - The cart might have been deleted
2. **Quote is active** - If it's been converted to an order, it's not abandoned
3. **Quote not modified** - If the customer came back and updated their cart after scheduling, the check is rescheduled (reset the timer)
4. **No order exists** - Double-checks the orders table for this quote ID
5. **Not already sent** - Prevents duplicate abandoned cart events for the same quote
6. **Has email** - Bento needs an email to associate the event with a subscriber

Each failure updates the schedule entry with a descriptive status: `not_found`, `converted`, `ordered`, `no_email`, etc. This creates an audit trail.

If all checks pass, the Checker publishes a `bento.cart.abandoned` message to Aligent's `event.trigger` queue. From this point, it enters the standard event pipeline: BentoNotifier -> AbandonedCartService -> BentoClient -> Bento API.

### Two Processing Methods: Queue vs. Cron

| Aspect | Queue Mode | Cron Mode |
|--------|-----------|-----------|
| **Speed** | Near real-time (if delayed messages supported) | Up to 5-minute polling interval |
| **Delay accuracy** | Exact (with plugin) or dropped (without plugin) | Within 5 minutes of target time |
| **Infrastructure** | Requires RabbitMQ | Works with any setup |
| **Reliability** | Can lose messages if consumer drops them | Database is durable |
| **Configuration** | `processing_method = queue` | `processing_method = cron` |

**The recommended approach**: Use cron mode unless you have RabbitMQ with the delayed message plugin installed. The database schedule table is the source of truth in both modes, and the cron job always respects the delay timing.

---

## Cart Recovery Links

**Controller:** `Controller/Cart/Recover.php`

When an abandoned cart email is sent, it can include a recovery link that takes the customer directly back to their cart. Here's how it works:

### Generating the Link

In `AbandonedCartService::generateRecoveryUrl()`, the `RecoveryToken` model creates an HMAC-signed, expiring JSON token:

```php
// RecoveryToken::generate()
$payload = [
    'v' => 'v1',                    // Token version
    'q' => $quoteId,                // Quote ID
    'e' => strtolower($email),      // Normalized email
    's' => $storeId,                // Store ID
    'x' => time() + 604800          // Expiry (7-day TTL)
];
$encodedPayload = base64url_encode(json_encode($payload));
$signature = hash_hmac('sha256', $encodedPayload, $secretKey);
$token = $encodedPayload . '.' . $signature;
```

The resulting URL looks like:
```
https://store.com/bento/cart/recover?recover=<base64url_payload>.<hmac_signature>
```

### Processing the Link

When the customer clicks the link, the `Recover` controller:

1. Splits the token at the `.` separator into payload and signature
2. Verifies the HMAC signature against the stored secret key (prevents token forgery)
3. Decodes the JSON payload and validates: version, non-zero quote ID, non-empty email, expiry
4. Checks the token has not expired (7-day TTL)
5. Loads the quote from the database
6. Verifies the email matches (case-insensitive)
7. Checks the quote is still active and has items
8. For customer carts: checks if the right customer is logged in. If not, redirects to login with return URL.
9. For guest carts: restores the cart directly by setting the quote ID in the checkout session
10. Redirects to the cart page with `?bento_recovered=1`

### Security

The token is HMAC-signed using the Bento secret key via `hash_hmac('sha256', ...)`, so tokens cannot be forged even if the quote ID and email are known. Tokens expire after 7 days (configurable via `DEFAULT_TTL_SECONDS`). If the secret key is rotated, all previously-issued recovery links become invalid. Expired or tampered tokens throw `InvalidArgumentException` and the user sees a generic error message.

---

## The Retry and Failure System

This is one of the most important architectural decisions in the plugin.

### The Problem

Aligent's async events framework has a simple retry model: if `NotifierResult::setSuccess(false)`, the message goes back to the queue and is retried up to 5 times (configurable). After 5 failures, it goes to the dead letter queue.

But not all failures should be retried:

| Error | Should Retry? | Why? |
|-------|--------------|------|
| 500 Server Error | Yes | Bento's server is temporarily down, might work next time |
| 429 Rate Limited | Yes | Too many requests right now, back off and try later |
| Network timeout | Yes | Temporary connectivity issue |
| 400 Bad Request | **No** | Our data is invalid, retrying won't fix it |
| 401 Unauthorized | **No** | Wrong API key, retrying won't fix it |
| 403 Forbidden | **No** | Wrong site UUID, retrying won't fix it |
| Missing email | **No** | The entity has no email, retrying won't find one |

Retrying permanent failures wastes queue resources and creates noise in the logs.

### The Solution: The success=true Hack

Aligent's `NotifierResult` has no `retryable` flag. It only has `setSuccess(true/false)`. So for permanent failures, BentoNotifier sets `success = true` to prevent retries, but embeds the actual failure details in `responseData`:

```php
// In BentoNotifier::createPermanentFailureResult()
$result->setSuccess(true);  // Tells Aligent "don't retry"
$result->setResponseData(json_encode([
    'success' => false,           // Actual outcome
    'permanent_failure' => true,  // Flag for audit
    'failure_code' => 'http_401', // What went wrong
    'message' => 'Unauthorized',  // Human-readable
    'retryable' => false,
    'note' => 'Marked as success to prevent retry - see failure_code for actual status'
]));
```

### How to Identify Permanent Failures

In the logs:
```bash
grep "PERMANENT FAILURE" var/log/bento.log
```

In Aligent's admin (System > Async Events > Logs): look for entries where `response_data` contains `"permanent_failure":true`. These will appear as "successful" deliveries in Aligent's interface (because `success=true`), but the response data reveals the truth.

### Retryable Error Classification

| HTTP Status | Retryable? | How Handled |
|-------------|-----------|-------------|
| 200 | N/A (success) | `setSuccess(true)` |
| 400 | No | `setSuccess(true)` + permanent failure data |
| 401 | No | `setSuccess(true)` + permanent failure data |
| 403 | No | `setSuccess(true)` + permanent failure data |
| 429 | Yes | `setSuccess(false)` -> Aligent retries |
| 500, 502, 503, 504 | Yes | `setSuccess(false)` -> Aligent retries |
| Network exception | Yes | `setSuccess(false)` -> Aligent retries |
| Missing email | No | `setSuccess(true)` + permanent failure data |

---

## Configuration Architecture

### How Configuration Flows

```
system.xml (defines the fields in admin UI)
    -> Admin saves values to core_config_data table
        -> Config.php reads values via ScopeConfigInterface
            -> Every observer, service, client checks Config before acting
```

### The Hierarchical Enable Pattern

Configuration is hierarchical. Here's the full chain for "should we track product views?":

```
artlounge_bento/general/enabled          = Yes?
  AND artlounge_bento/tracking/enabled   = Yes?
    AND artlounge_bento/tracking/track_views = Yes?
```

All three must be true. Turning off any level disables everything below it.

This same pattern applies to all event types:
- Master enable -> Order events enable -> Individual order event enable
- Master enable -> Customer events enable -> Individual customer event enable
- Master enable -> Newsletter events enable -> Individual newsletter event enable
- Master enable -> Abandoned cart enable
- Master enable -> Tracking enable -> Individual tracking event enable

### Important Default Values

| Setting | Default | Notes |
|---------|---------|-------|
| Currency multiplier | 100 | Converts to cents (paisa for INR) |
| Abandoned cart delay | 60 minutes | |
| Processing method | queue | |
| Add-to-cart selector | `#product-addtocart-button` | Magento's default button ID |
| Brand attribute | `manufacturer` | |
| Max retries | 5 | Aligent's retry limit |
| HTTP timeout | 30 seconds | |
| Log retention | 30 days | |

---

## How Money is Handled

All monetary values sent to Bento are in the **smallest currency unit** (cents for USD, paisa for INR).

The conversion uses a configurable multiplier (default: 100):

```
Store price: 250.00 INR
Multiplied: 250.00 * 100 = 25000
Sent to Bento: 25000
```

This is consistent across all services (OrderService, AbandonedCartService, etc.) and the client-side tracking (TrackingData ViewModel exposes the multiplier for JavaScript use).

**Why not just send the decimal amount?** Bento's API expects integers in cents. Using integers avoids floating-point precision issues (0.1 + 0.2 != 0.3 in floating-point math). This is standard practice in payment and financial systems.

**The multiplier is configurable** because not all currencies use 2 decimal places. Japanese Yen (JPY) has no decimal places, so the multiplier would be 1. Kuwaiti Dinar (KWD) has 3 decimal places, so the multiplier would be 1000.

**Discount handling**: Magento stores discounts as negative numbers (-20.00). All services use `abs()` to convert to positive before multiplication.

---

## Varnish and Caching Compatibility

Varnish (full page cache) is a critical consideration because it serves the same cached HTML to all users. Any user-specific content in the HTML would either break caching or show the wrong data.

### How Each Template Handles It

| Template | User-Specific Data? | Strategy |
|----------|-------------------|----------|
| `bento_script.phtml` | No | Publishable key is the same for all users. User identification via AJAX. |
| `product_tracking.phtml` | No | Product data is the same for all users viewing the same product. |
| `checkout_tracking.phtml` | No | Cart data loaded via `customerData` AJAX after page load. |
| `purchase_tracking.phtml` | Yes (but safe) | Success page is never cached (session-dependent, shown once). |

### The Private Content Pattern

Magento's private content system (`Magento_Customer/js/customer-data`) is the key to Varnish compatibility:

1. Varnish serves the cached HTML (identical for all users)
2. After page load, JavaScript calls `customerData.get('customer')` and `customerData.get('cart')`
3. Magento's backend responds with user-specific data via AJAX
4. JavaScript uses this data for `bento.identify()` and event tracking

This is why you'll see patterns like this in the templates:

```javascript
// BAD (would break Varnish):
// var email = "<?php echo $customer->getEmail() ?>";

// GOOD (Varnish-safe):
var customer = customerData.get('customer');
customer.subscribe(function(data) {
    if (data.email) {
        bento.identify(data.email);
    }
});
```

---

## Event Deduplication Strategy

Duplicate events are prevented at multiple levels:

### Server-Side

- **Order placed**: Uses `increment_id` as the unique key. Each order can only fire once.
- **Abandoned cart**: The `preventDuplicates` config enables checking via `Scheduler::isAlreadySent()`. Once a cart event is sent, the same quote won't trigger again.
- **Customer updated**: The `$processedIds` array in the observer prevents duplicates within a single HTTP request.

### Client-Side

| Event | Deduplication Method |
|-------|---------------------|
| `$view` | Date-based key (`productId_YYYY-MM-DD`). Same product on different days = different events. Same product multiple times same day = Bento deduplicates via `unique.key`. |
| `$cart_created` / `$cart_updated` | 2-second debounce on the button click + timestamp-based unique key |
| `$checkoutStarted` | Session storage with 30-minute window per cart ID |
| `$purchase` | Order `increment_id` as unique key. Matches the server-side key, so if both client and server events fire, Bento deduplicates them. |

### Cross-Channel (Client + Server)

The purchase event is the only one tracked by both client-side and server-side. Both use the order's `increment_id` as the `unique.key`, so Bento will keep only one even if both fire successfully. The client-side event marks itself as `source: client_fallback` for identification in analytics.

---

## Design Decisions and Trade-Offs

### 1. Queue-based vs. Synchronous Processing

**Decision**: All server-side events go through a message queue (RabbitMQ or database).

**Why**: Synchronous HTTP calls during order placement would slow down the checkout. If Bento's API is slow or down, it would directly impact the customer's experience. The queue ensures that Magento's operations complete instantly, and the Bento notification happens in the background.

**Trade-off**: Added complexity. You need queue consumers running (via Supervisor in production). Events are not instant -- there's a small delay between when something happens and when Bento receives it.

### 2. Entity ID in Queue vs. Full Data

**Decision**: Observers publish only the entity ID, and the service class reloads the full entity when processing.

**Why**: Small queue messages, fresh data at processing time.

**Trade-off**: The entity is loaded twice (once by Magento during the original operation, once by the service class). This is minor because the queue consumer runs in a separate process. The data could theoretically change between publish and consume time, but for our use cases this is actually desirable (we want the latest state).

### 3. The success=true Hack for Permanent Failures

**Decision**: Return `success=true` to Aligent's framework for permanent failures.

**Why**: Aligent has no `retryable` flag. Without this hack, 400 Bad Request errors would be retried 5 times before going to dead letter, wasting resources and creating noise.

**Trade-off**: Permanent failures appear as "successful" in Aligent's admin interface. You need to look at the `response_data` field to see the actual failure. This is well-documented in the codebase and in `bento.log`.

### 4. Two Abandoned Cart Processing Methods

**Decision**: Support both queue-based and cron-based abandoned cart processing.

**Why**: Queue-based is faster but requires RabbitMQ with delayed message support. Not all environments have this. Cron-based is universally available but less precise in timing.

**Trade-off**: More code to maintain. The dual-write pattern (database + queue) in queue mode means the database table is always up to date, allowing the cron to serve as a fallback.

### 5. HMAC-Signed Cart Recovery Tokens

**Decision**: Use HMAC-signed tokens (`base64(quote_id:email:hmac_signature)`) for cart recovery links.

**Why**: Stateless (no token storage needed) while cryptographically secure. The HMAC signature prevents forgery even if the quote ID and email are known.

**Trade-off**: Requires the Bento secret key to be available when generating and validating tokens. If the secret key is rotated, previously-issued recovery links become invalid.

### 6. Dynamic Customer Group Resolution in OrderService

**Decision**: `OrderService` uses `GroupRepositoryInterface` to resolve customer group names dynamically.

**Why**: Ensures custom customer groups always show the correct name in Bento event payloads. This is consistent with `CustomerService` which uses the same approach.

**Trade-off**: An extra (cached) repository call per order. Negligible cost since the repository uses Magento's built-in identity map.

### 7. Child Item Filtering

**Decision**: Configurable product children are filtered from order items (by checking `getParentItemId()`).

**Why**: For a configurable product (e.g., "Blue T-Shirt, Size M"), Magento creates both a parent item and a child item. Including both would double-count the item and confuse the data.

**Trade-off**: None significant. This is the standard approach in Magento integrations.

### 8. Registry for Current Product

**Decision**: `TrackingData` ViewModel uses `Magento\Framework\Registry` to access the current product.

**Why**: It's the only reliable way to get the current product in a layout-injected ViewModel. Magento deprecated the Registry in 2.4+ but hasn't provided a clean replacement for this use case.

**Trade-off**: The code uses a deprecated API. It works fine and won't be removed anytime soon, but be aware that a future Magento version might require a different approach.

### 9. No Interface for Service Classes

**Decision**: Service classes (OrderService, CustomerService, etc.) are concrete classes without interfaces.

**Why**: They're only used by one consumer (BentoNotifier via Aligent's framework). The overhead of maintaining interfaces for single-implementation classes wasn't justified.

**Trade-off**: Less flexible for testing and replacement. `ShipmentService` and `RefundService` depend directly on the concrete `OrderService` rather than an interface.

---

## Outbox Fallback System

When the AMQP broker (RabbitMQ) is unavailable, events would normally be lost. The outbox system catches these failures:

1. All 7 event observers (OrderPlaced, OrderShipped, OrderRefunded, OrderStatusChanged, CustomerCreated, CustomerUpdated, SubscriberChanged) have try/catch blocks around the AMQP publish call
2. On failure, `OutboxWriter` persists the event to the `artlounge_bento_event_outbox` database table
3. `OutboxProcessor` cron (every 2 minutes) atomically claims pending entries and replays them to AMQP with exponential backoff
4. `OutboxCleanup` cron removes processed entries after 7 days
5. CLI: `bento:outbox:process` (manual trigger), `bento:outbox:status` (check pending count)

Key classes:
- `BentoEvents/Model/Outbox/Writer.php` -- writes failed events to DB
- `BentoEvents/Model/Outbox/Processor.php` -- atomic claim + retry with backoff
- `BentoEvents/Cron/OutboxReplay.php` -- cron trigger for processor
- `BentoEvents/Cron/OutboxCleanup.php` -- clean old entries

---

## Dead-Letter Queue Improvements

When the Bento API repeatedly rejects an event, it goes to the dead-letter queue after exhausting retries:

- `max_deaths` increased from 5 to 20 (extends retry window from ~1 min to ~15 min with exponential backoff)
- `bento:deadletter:replay` CLI command for manual replay of dead-lettered messages
- `DeadLetterMonitor` cron (every 5 minutes) logs dead-letter queue depth for alerting
- Uses `DEAD_LETTER_ROUTING_KEY` constant for direct retry queue delivery

Key classes:
- `BentoEvents/Console/Command/ReplayDeadLetterCommand.php`
- `BentoEvents/Model/DeadLetter/Monitor.php`
- `BentoEvents/Cron/DeadLetterMonitor.php`

---

## Anonymous View Replay (Client-Side)

Product views by anonymous visitors must be attributed to an email after identification:

1. When anonymous: `product_tracking.phtml` sends `$view` to Bento AND queues the view in `sessionStorage` (`bento_pending_views`)
2. When identified (login, localStorage pre-identify, or checkout email): `bento_script.phtml` checks for pending views
3. Pending views are replayed via `bento.track('$view', data)` with the now-known email
4. Queue is cleared after replay

This ensures that browsing behavior before login is attributed to the customer profile.

---

## bento-identity Customer Data Section

Magento's built-in `customer` customer-data section does NOT include the email address. The plugin creates a custom `bento-identity` section:

- `BentoTracking/CustomerData/BentoSection.php` -- returns `{ email: "customer@example.com" }` from the customer session
- Registered in `BentoTracking/etc/frontend/di.xml` as a customer-data section
- `BentoTracking/etc/frontend/sections.xml` -- invalidates on login, logout, and cart actions
- `bento_script.phtml` reads this section via AJAX to identify the user

---

## ServiceDataResolver Pattern

Magento's `ServiceOutputProcessor::convertValue()` has a critical flaw: it iterates array values with `foreach ($data as $datum)`, discarding associative keys. This means all service method return arrays lose their key structure.

Fix: `BentoEvents/Model/ServiceDataResolver.php` intercepts the event processing pipeline. When the Aligent framework calls the service method, ServiceDataResolver re-invokes the method with the entity ID extracted from the flattened positional array, recovering the original keyed structure.

---

## Event Naming Convention

Cart events use Bento-native event names for integration with Bento's built-in ecommerce features:

| Event | Name | When |
|-------|------|------|
| First item added to cart | `$cart_created` | ATC click with empty cart |
| Subsequent cart changes | `$cart_updated` | ATC, qty change, item removal |

Previous names (`add_to_cart`, `$addToCart`) were custom events that did not integrate with Bento's ecommerce tracking.

---

## Value Format Requirement

The `value` field in Bento events MUST be an object: `{ "amount": N, "currency": "INR" }` -- never a flat number.

Sending `value: 82000` (flat integer) causes a **silent drop**: Bento returns HTTP 200 with `results: 1` but the event never appears in the dashboard or visitor timeline. This was discovered during E2E testing (order 000035729 was lost to this bug).

All monetary values are in cents (INR x 100): Rs. 82.00 = 8200.

---

## File-by-File Reference

### BentoCore

```
BentoCore/
  Api/
    ConfigInterface.php         Interface for all configuration access
    BentoClientInterface.php    Interface for the Bento HTTP client
  Block/Adminhtml/System/Config/
    TestConnection.php          Renders the "Test Connection" button in admin
  Controller/Adminhtml/Test/
    Connection.php              AJAX endpoint for the test connection button
  Model/
    Config.php                  Central configuration reader (reads from core_config_data)
    BentoClient.php             HTTP client that sends events to Bento's API
    MissingEmailException.php   Exception thrown when event data has no email
    Config/Source/
      ProcessingMethod.php      Source model for queue/cron dropdown in admin
  etc/
    module.xml                  Module declaration and dependencies
    di.xml                      DI preferences + custom bento.log logger
    adminhtml/
      system.xml                Admin configuration UI (all settings)
      routes.xml                Admin route for test connection
  Test/Unit/                    Unit tests
```

### BentoEvents

```
BentoEvents/
  Console/Command/
    TestConnectionCommand.php   CLI: bento:test - tests Bento API connection
    StatusCommand.php           CLI: bento:status - shows config & schedule summary
    ProcessAbandonedCartsCommand.php  CLI: bento:abandoned-cart:process
    CleanupAbandonedCartsCommand.php  CLI: bento:abandoned-cart:cleanup
  Controller/
    Adminhtml/AbandonedCart/
      Index.php                 Admin grid page controller
      MassDelete.php            Mass delete action for admin grid
      MassReset.php             Mass reset-to-pending action for admin grid
    Cart/
      Recover.php               Handles cart recovery links from Bento emails
  Cron/
    ProcessAbandonedCarts.php   Cron job for processing abandoned carts
  Model/
    AbandonedCartSchedule.php   Model for admin grid
    BentoNotifier.php           Bridge between Aligent framework and BentoClient
    EventTypeMapper.php         Maps internal event names to Bento event types
    RecoveryToken.php           HMAC-signed cart recovery token generation/validation
    AbandonedCart/
      Scheduler.php             Schedules abandoned cart checks (DB + queue)
      Checker.php               Validates carts are truly abandoned
      Consumer.php              Queue consumer for abandoned cart messages
    ResourceModel/
      AbandonedCartSchedule.php           Resource model for schedule table
      AbandonedCartSchedule/Collection.php  Collection for admin grid
  Observer/
    Sales/
      OrderPlaced.php           Watches order placement
      OrderShipped.php          Watches shipment creation
      OrderRefunded.php         Watches credit memo creation
      OrderStatusChanged.php    Watches order cancellation
    Customer/
      CustomerCreated.php       Watches new customer registration
      CustomerUpdated.php       Watches customer profile changes
    Newsletter/
      SubscriberChanged.php     Watches newsletter subscribe/unsubscribe
    Quote/
      QuoteSaved.php            Entry point for abandoned cart system
  Service/
    OrderService.php            Formats order data for Bento
    ShipmentService.php         Formats shipment + order data for Bento
    RefundService.php           Formats refund + order data for Bento
    CustomerService.php         Formats customer data for Bento
    NewsletterService.php       Formats newsletter subscriber data for Bento
    AbandonedCartService.php    Formats abandoned cart data for Bento
  Setup/Patch/Data/
    CreateBentoSubscriptions.php  Creates async event subscriptions on install
  etc/
    acl.xml                     ACL for admin grid access control
    module.xml                  Module declaration and dependencies
    di.xml                      Logger injection, notifier registration, grid data source, CLI commands
    events.xml                  Registers all 8 Magento event observers
    async_events.xml            Maps events to service classes for Aligent
    communication.xml           Declares abandoned cart queue topic
    queue_topology.xml          RabbitMQ exchange/queue for abandoned carts
    queue_consumer.xml          Consumer registration (AMQP + DB fallback)
    queue_publisher.xml         Publisher configuration for abandoned cart topic
    crontab.xml                 Cron schedule for abandoned cart processing
    db_schema.xml               Database table for abandoned cart scheduling
    adminhtml/
      routes.xml                Admin route for abandoned cart grid
      menu.xml                  Admin menu entry under Bento
    frontend/
      routes.xml                Frontend route for cart recovery URL
  view/adminhtml/
    layout/
      artlounge_bento_abandonedcart_index.xml  Layout for admin grid page
    ui_component/
      artlounge_bento_abandoned_cart_listing.xml  UI component for admin grid
  Test/Unit/                    Unit tests
  Test/Integration/              Integration tests (EventPipeline, AbandonedCart, CLI, Recovery)
```

### BentoTracking

```
BentoTracking/
  ViewModel/
    TrackingData.php            Provides data to all frontend templates
  view/frontend/
    layout/
      default.xml               Injects Bento script on every page
      catalog_product_view.xml  Injects product tracking on product pages
      checkout_index_index.xml  Injects checkout tracking on checkout page
      checkout_onepage_success.xml  Injects purchase tracking on success page
    templates/
      bento_script.phtml        Loads Bento JS, identifies users, browse history
      product_tracking.phtml    Product view + add-to-cart tracking
      checkout_tracking.phtml   Checkout started tracking
      purchase_tracking.phtml   Purchase tracking (client-side fallback)
  etc/
    module.xml                  Module declaration and dependencies
    csp_whitelist.xml           CSP whitelist for Bento domains
  Test/Unit/                    Unit tests
```

---

## Quick Reference: The Complete Event Lifecycle

Here's every event the system tracks, from trigger to delivery:

| What Happens | Magento Event | Observer | Queue Topic | Service | Bento Event |
|-------------|--------------|----------|-------------|---------|-------------|
| Order placed | `sales_order_place_after` | `OrderPlaced` | `bento.order.placed` | `OrderService` | `$purchase` |
| Order shipped | `sales_order_shipment_save_after` | `OrderShipped` | `bento.order.shipped` | `ShipmentService` | `$OrderShipped` |
| Order refunded | `sales_order_creditmemo_save_after` | `OrderRefunded` | `bento.order.refunded` | `RefundService` | `$OrderRefunded` |
| Order cancelled | `sales_order_save_after` (filtered) | `OrderStatusChanged` | `bento.order.cancelled` | `OrderService` | `$OrderCanceled` |
| Customer registered | `customer_register_success` | `CustomerCreated` | `bento.customer.created` | `CustomerService` | `$Subscriber` |
| Customer updated | `customer_save_after` | `CustomerUpdated` | `bento.customer.updated` | `CustomerService` | `$CustomerUpdated` |
| Newsletter subscribed | `newsletter_subscriber_save_after` | `SubscriberChanged` | `bento.newsletter.subscribed` | `NewsletterService` | `$subscribe` |
| Newsletter unsubscribed | `newsletter_subscriber_save_after` | `SubscriberChanged` | `bento.newsletter.unsubscribed` | `NewsletterService` | `$unsubscribe` |
| Cart abandoned | `sales_quote_save_after` | `QuoteSaved` -> Scheduler -> Checker | `bento.cart.abandoned` | `AbandonedCartService` | `$abandoned` |
| Product viewed | (client-side) | N/A | N/A | N/A | `$view` |
| Added to cart | (client-side) | N/A | N/A | N/A | `$cart_created` / `$cart_updated` |
| Checkout started | (client-side) | N/A | N/A | N/A | `$checkoutStarted` |
| Purchase (fallback) | (client-side) | N/A | N/A | N/A | `$purchase` |
