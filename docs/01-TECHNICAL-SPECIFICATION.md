# Bento Integration with Async Events - Technical Specification

**Document Version:** 1.0
**Date:** January 24, 2026
**Prepared For:** Art Lounge Development Team
**Platform:** Magento 2.4.4+

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Why Async Events](#why-async-events)
3. [Architecture Overview](#architecture-overview)
4. [Module Structure](#module-structure)
5. [Event Flow Diagrams](#event-flow-diagrams)
6. [Data Models](#data-models)
7. [API Contracts](#api-contracts)

---

## Executive Summary

This specification defines a production-ready integration between Art Lounge's Magento 2 store and Bento email marketing platform using the Aligent Async Events framework.

### Key Deliverables

| Module | Purpose | Lines of Code |
|--------|---------|---------------|
| `ArtLounge_BentoCore` | Shared configuration, API client, utilities | ~800 |
| `ArtLounge_BentoEvents` | Server-side events (orders, customers, carts) | ~1,200 |
| `ArtLounge_BentoTracking` | Client-side tracking (views, add to cart) | ~400 |

### Events Covered

| Event | Type | Priority | Bento Event Name |
|-------|------|----------|------------------|
| Order Placed | Server | Critical | `$purchase` |
| Order Shipped | Server | High | `$OrderShipped` |
| Order Cancelled | Server | Medium | `$OrderCanceled` |
| Order Refunded | Server | Medium | `$OrderRefunded` |
| Customer Created | Server | High | `$Subscriber` |
| Customer Updated | Server | Medium | `$CustomerUpdated` |
| Newsletter Subscribe | Server | High | `$subscribe` |
| Newsletter Unsubscribe | Server | Medium | `$unsubscribe` |
| Abandoned Cart | Server | Critical | `$abandoned` |
| Product Viewed | Client | High | `$view` |
| Added to Cart | Client | High | `$addToCart` |
| Checkout Started | Client | Medium | `$checkoutStarted` |

---

## Why Async Events

### The Problem with Synchronous Webhooks

**Current Mageplaza Implementation Issues:**

```
User Action → Magento Event → HTTP POST to Bento → Wait for Response → Continue
                                    ↓
                              If Bento slow/down:
                              - User waits
                              - Order may timeout
                              - Silent failures
                              - No retry
                              - No visibility
```

**Real-World Impact:**
- Bento API latency (200-500ms) adds to every order
- API downtime causes lost events (no retry)
- No audit trail for debugging
- Template errors cause silent failures
- Peak traffic overwhelms synchronous processing

### The Async Events Solution

```
User Action → Magento Event → Queue Message → Immediate Continue
                                    ↓
                            Background Consumer
                                    ↓
                              HTTP POST to Bento
                                    ↓
                              Success? → Log & Complete
                              Failure? → Retry with Backoff
                                           ↓
                                    Max Retries? → Dead Letter Queue
                                                   (Admin notification)
```

### Feature Comparison

| Capability | Mageplaza Webhooks | Async Events |
|------------|-------------------|--------------|
| **Processing** | Synchronous (blocking) | Asynchronous (non-blocking) |
| **Retry on Failure** | None | Automatic (5x with exponential backoff) |
| **Visibility** | None | Full UUID tracing in admin |
| **Failure Recovery** | Manual | Automatic + manual replay |
| **Performance Impact** | High (blocks user) | None (background processing) |
| **Audit Trail** | None | Complete with Elasticsearch search |
| **Template Errors** | Silent failure | Logged with error details |
| **Peak Load Handling** | Degrades | Queue absorbs spikes |
| **Upgrade Safe** | Requires core file mods | Standard Magento module |
| **Admin Config** | Basic | Comprehensive |

### Reliability Metrics

**Exponential Backoff Schedule:**

| Attempt | Delay | Total Elapsed |
|---------|-------|---------------|
| 1 | Immediate | 0s |
| 2 | 1 second | 1s |
| 3 | 4 seconds | 5s |
| 4 | 9 seconds | 14s |
| 5 | 16 seconds | 30s |
| 6 | 25 seconds | 55s |

**Formula:** `delay = min(60, attempt²)` seconds

After max retries (configurable, default 5), events are moved to dead letter queue with admin notification.

### Business Value

**For 15,000 SKU Store with ~100 orders/day:**

| Metric | Synchronous | Async Events |
|--------|-------------|--------------|
| Order Completion Time | +200-500ms | +0ms |
| Events Lost to Downtime | ~5-10/month | 0 |
| Debug Time per Issue | 2-4 hours | 5-10 minutes |
| Recovery from API Outage | Manual resubmit | Automatic |
| Peak Hour Reliability | Degraded | Unchanged |

---

## Architecture Overview

### System Components

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           MAGENTO 2 STORE                                │
│                                                                          │
│  ┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐  │
│  │  ArtLounge_      │    │  ArtLounge_      │    │  ArtLounge_      │  │
│  │  BentoCore       │    │  BentoEvents     │    │  BentoTracking   │  │
│  │                  │    │                  │    │                  │  │
│  │  - Config        │    │  - Observers     │    │  - JS Tracking   │  │
│  │  - API Client    │    │  - Services      │    │  - Layout XML    │  │
│  │  - Utilities     │    │  - Queue Msgs    │    │  - Templates     │  │
│  └────────┬─────────┘    └────────┬─────────┘    └────────┬─────────┘  │
│           │                       │                       │             │
│           └───────────────────────┼───────────────────────┘             │
│                                   │                                      │
│                                   ▼                                      │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │                    Aligent_AsyncEvents                              │ │
│  │                                                                     │ │
│  │   ┌─────────────┐   ┌─────────────┐   ┌─────────────────────────┐  │ │
│  │   │ Event       │   │ Queue       │   │ Notifier                │  │ │
│  │   │ Registration│   │ Management  │   │ (HTTP/Custom)           │  │ │
│  │   └─────────────┘   └─────────────┘   └─────────────────────────┘  │ │
│  │                                                                     │ │
│  │   ┌─────────────┐   ┌─────────────┐   ┌─────────────────────────┐  │ │
│  │   │ Retry       │   │ Logging     │   │ Admin UI                │  │ │
│  │   │ Handler     │   │ + ES Index  │   │ (Events/Logs/Trace)     │  │ │
│  │   └─────────────┘   └─────────────┘   └─────────────────────────┘  │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                   │                                      │
└───────────────────────────────────┼──────────────────────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    │               │               │
                    ▼               ▼               ▼
            ┌─────────────┐ ┌─────────────┐ ┌─────────────────┐
            │  RabbitMQ   │ │Elasticsearch│ │     MySQL       │
            │  (Queue)    │ │  (Search)   │ │   (Logging)     │
            └─────────────┘ └─────────────┘ └─────────────────┘
                    │
                    │ Queue Consumer (Background Process)
                    │
                    ▼
            ┌─────────────────────────────────────────┐
            │              BENTO API                   │
            │                                          │
            │  POST /webhooks/{site-uuid}/track       │
            │  POST /api/v1/batch/subscribers         │
            │  POST /api/v1/batch/events              │
            └─────────────────────────────────────────┘
```

### Message Flow

```
1. ORDER PLACED FLOW
   ═══════════════════

   Customer         Magento              RabbitMQ           Consumer           Bento
      │                │                    │                   │                │
      │ Place Order    │                    │                   │                │
      │───────────────>│                    │                   │                │
      │                │                    │                   │                │
      │   Order Page   │ Publish Message    │                   │                │
      │<───────────────│───────────────────>│                   │                │
      │                │                    │                   │                │
      │   (Immediate   │                    │ Consume Message   │                │
      │    Response)   │                    │<──────────────────│                │
      │                │                    │                   │                │
      │                │                    │                   │ POST $purchase │
      │                │                    │                   │───────────────>│
      │                │                    │                   │                │
      │                │                    │                   │   200 OK       │
      │                │                    │                   │<───────────────│
      │                │                    │                   │                │
      │                │                    │   ACK (Remove)    │ Log Success    │
      │                │                    │<──────────────────│                │
```

```
2. RETRY FLOW (On Failure)
   ════════════════════════

   Consumer           RabbitMQ              Retry Queue         Consumer         Bento
      │                   │                     │                   │              │
      │ POST to Bento     │                     │                   │              │
      │ (500 Error)       │                     │                   │              │
      │                   │                     │                   │              │
      │ NACK + Retry Msg  │                     │                   │              │
      │──────────────────>│                     │                   │              │
      │                   │                     │                   │              │
      │                   │ Delay (1s)          │                   │              │
      │                   │────────────────────>│                   │              │
      │                   │                     │                   │              │
      │                   │                     │ After 1s          │              │
      │                   │                     │──────────────────>│              │
      │                   │                     │                   │              │
      │                   │                     │                   │ POST (Retry) │
      │                   │                     │                   │─────────────>│
      │                   │                     │                   │              │
      │                   │                     │                   │   200 OK     │
      │                   │                     │                   │<─────────────│
      │                   │                     │                   │              │
      │                   │                     │   ACK (Success)   │              │
      │                   │                     │<──────────────────│              │
```

### Infrastructure Requirements

| Component | Requirement | Purpose |
|-----------|-------------|---------|
| **RabbitMQ** | 3.8+ | Message queue for async processing |
| **Elasticsearch** | 7.x+ | Event log search (optional but recommended) |
| **PHP** | 8.1+ | Magento 2.4.4+ requirement |
| **Magento** | 2.4.4+ | Base platform |
| **Cron** | Running | Fallback queue processing |

**Note:** If RabbitMQ is not available, Magento's database queue can be used as fallback (with reduced performance).

---

## Module Structure

### ArtLounge_BentoCore

```
app/code/ArtLounge/BentoCore/
├── registration.php
├── composer.json
├── etc/
│   ├── module.xml
│   ├── di.xml
│   ├── config.xml
│   ├── acl.xml
│   └── adminhtml/
│       └── system.xml
├── Api/
│   ├── ConfigInterface.php
│   └── BentoClientInterface.php
├── Model/
│   ├── Config.php
│   └── BentoClient.php
├── Helper/
│   └── Data.php
└── Controller/
    └── Adminhtml/
        └── System/
            └── Config/
                └── TestConnection.php
```

### ArtLounge_BentoEvents

```
app/code/ArtLounge/BentoEvents/
├── registration.php
├── composer.json
├── etc/
│   ├── module.xml
│   ├── di.xml
│   ├── events.xml
│   ├── crontab.xml
│   ├── async_events.xml
│   ├── queue_topology.xml
│   ├── queue_consumer.xml
│   ├── queue_publisher.xml
│   └── communication.xml
├── Api/
│   └── Data/
│       ├── OrderDataInterface.php
│       ├── CustomerDataInterface.php
│       └── AbandonedCartDataInterface.php
├── Model/
│   ├── OrderDataProvider.php
│   ├── CustomerDataProvider.php
│   ├── AbandonedCartDataProvider.php
│   └── AbandonedCart/
│       ├── Scheduler.php
│       └── Checker.php
├── Observer/
│   ├── Sales/
│   │   ├── OrderPlaced.php
│   │   ├── OrderShipped.php
│   │   ├── OrderCancelled.php
│   │   └── OrderRefunded.php
│   ├── Customer/
│   │   ├── CustomerCreated.php
│   │   └── CustomerUpdated.php
│   ├── Newsletter/
│   │   └── SubscriberChanged.php
│   └── Quote/
│       └── QuoteSaved.php
├── Service/
│   ├── OrderService.php
│   ├── CustomerService.php
│   ├── NewsletterService.php
│   └── AbandonedCartService.php
├── Cron/
│   └── ProcessAbandonedCarts.php
└── Test/
    ├── Unit/
    │   ├── Model/
    │   │   └── OrderDataProviderTest.php
    │   └── Observer/
    │       └── OrderPlacedTest.php
    └── Integration/
        └── AbandonedCartTest.php
```

### ArtLounge_BentoTracking

```
app/code/ArtLounge/BentoTracking/
├── registration.php
├── composer.json
├── etc/
│   ├── module.xml
│   ├── di.xml
│   └── adminhtml/
│       └── system.xml
├── Block/
│   ├── ProductTracking.php
│   ├── CartTracking.php
│   └── CheckoutTracking.php
├── ViewModel/
│   └── TrackingData.php
└── view/
    └── frontend/
        ├── layout/
        │   ├── default.xml
        │   ├── catalog_product_view.xml
        │   └── checkout_index_index.xml
        ├── templates/
        │   ├── bento_script.phtml
        │   ├── product_tracking.phtml
        │   ├── cart_tracking.phtml
        │   └── checkout_tracking.phtml
        └── web/
            └── js/
                └── bento-tracking.js
```

---

## Event Flow Diagrams

### Order Placed Event

```
┌────────────────────────────────────────────────────────────────────────────┐
│                        ORDER PLACED EVENT FLOW                              │
└────────────────────────────────────────────────────────────────────────────┘

   ┌─────────────┐
   │   Customer  │
   │ Places Order│
   └──────┬──────┘
          │
          ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                         Magento Sales Module                             │
   │                                                                          │
   │   sales_order_place_after event dispatched                              │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                    ArtLounge_BentoEvents                                 │
   │                                                                          │
   │   Observer\Sales\OrderPlaced::execute()                                 │
   │   ├── Check if module enabled                                           │
   │   ├── Check if order events enabled                                     │
   │   ├── Get order ID                                                      │
   │   └── Publish to queue: 'bento.order.placed'                           │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                         RabbitMQ Queue                                   │
   │                                                                          │
   │   Queue: event.trigger                                                  │
   │   Message: ['bento.order.placed', '{"id": 12345}']                      │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                    Aligent_AsyncEvents                                   │
   │                                                                          │
   │   AsyncEventTriggerHandler::execute()                                   │
   │   ├── Lookup service from async_events.xml                              │
   │   ├── Call OrderService::get(12345)                                     │
   │   ├── Get all subscriptions for 'bento.order.placed'                    │
   │   └── For each subscription:                                            │
   │       └── NotifierFactory::create('http')->notify()                     │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                    OrderService::get()                                   │
   │                                                                          │
   │   Returns formatted order data:                                         │
   │   {                                                                      │
   │     "event_type": "$purchase",                                          │
   │     "email": "customer@example.com",                                    │
   │     "total_value": 250000,  // cents                                    │
   │     "items": [...],                                                     │
   │     "shipping_address": {...},                                          │
   │     ...                                                                  │
   │   }                                                                      │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                         HttpNotifier                                     │
   │                                                                          │
   │   POST https://track.bentonow.com/webhooks/{site-uuid}/artlounge/track  │
   │                                                                          │
   │   Headers:                                                               │
   │     X-Bento-Signature: HMAC-SHA256(payload, verification_token)         │
   │     Content-Type: application/json                                      │
   │                                                                          │
   │   Body: {order data from service}                                       │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                        ┌─────────────┴─────────────┐
                        │                           │
                        ▼                           ▼
               ┌───────────────┐           ┌───────────────┐
               │   SUCCESS     │           │   FAILURE     │
               │   (200 OK)    │           │   (5xx/Timeout)│
               └───────┬───────┘           └───────┬───────┘
                       │                           │
                       ▼                           ▼
               ┌───────────────┐           ┌───────────────┐
               │   Log Entry   │           │ RetryManager  │
               │   success=1   │           │ death_count+1 │
               │   uuid=xxx    │           │ → retry queue │
               └───────────────┘           └───────────────┘
```

### Abandoned Cart Flow

```
┌────────────────────────────────────────────────────────────────────────────┐
│                      ABANDONED CART EVENT FLOW                              │
└────────────────────────────────────────────────────────────────────────────┘

   ┌─────────────┐
   │   Customer  │
   │ Adds Items  │
   │  to Cart    │
   └──────┬──────┘
          │
          ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                         Magento Quote Module                             │
   │                                                                          │
   │   sales_quote_save_after event dispatched                               │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                    ArtLounge_BentoEvents                                 │
   │                                                                          │
   │   Observer\Quote\QuoteSaved::execute()                                  │
   │   ├── Check if module enabled                                           │
   │   ├── Check if abandoned cart enabled                                   │
   │   ├── Validate conditions:                                              │
   │   │   ├── Quote is active                                               │
   │   │   ├── Has items                                                     │
   │   │   ├── Has customer email (guest checkout email or logged in)       │
   │   │   ├── Grand total >= min_value (config)                             │
   │   │   └── Customer group not excluded                                   │
   │   └── Schedule check after delay (config: 60 min default)              │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                         TWO PROCESSING OPTIONS                           │
   └─────────────────────────┬───────────────────────┬───────────────────────┘
                             │                       │
              ┌──────────────┴─────┐      ┌─────────┴──────────────┐
              ▼                    │      │                        ▼
   ┌─────────────────────┐         │      │         ┌─────────────────────┐
   │  OPTION A: Queue    │         │      │         │  OPTION B: Cron     │
   │                     │         │      │         │                     │
   │  Message published  │         │      │         │  Quote ID stored    │
   │  to delayed queue   │         │      │         │  in scheduled table │
   │  with TTL = delay   │         │      │         │                     │
   └──────────┬──────────┘         │      │         └──────────┬──────────┘
              │                    │      │                    │
              │ After delay        │      │  Cron every 5 min  │
              ▼                    │      │                    ▼
   ┌─────────────────────┐         │      │         ┌─────────────────────┐
   │  Queue Consumer     │         │      │         │  Cron Job           │
   │  picks up message   │         │      │         │  ProcessAbandonedCarts│
   └──────────┬──────────┘         │      │         └──────────┬──────────┘
              │                    │      │                    │
              └────────────────────┴──────┴────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                    AbandonedCartChecker                                  │
   │                                                                          │
   │   Check if cart is STILL abandoned:                                     │
   │   ├── Quote still active (not converted to order)                       │
   │   ├── Quote not modified since scheduled (customer hasn't returned)    │
   │   ├── No order exists with this quote_id                                │
   │   └── Email not already sent for this quote (prevent duplicates)       │
   │                                                                          │
   │   If all conditions pass → Publish 'bento.cart.abandoned'               │
   └──────────────────────────────────┬──────────────────────────────────────┘
                                      │
                                      ▼
   ┌─────────────────────────────────────────────────────────────────────────┐
   │                    Async Events Processing                               │
   │                                                                          │
   │   Same flow as Order Placed:                                            │
   │   Queue → AsyncEventTriggerHandler → AbandonedCartService → HttpNotifier│
   └─────────────────────────────────────────────────────────────────────────┘
```

---

## Data Models

### Order Event Payload

```json
{
  "event_type": "$purchase",
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2026-01-24T10:30:00+05:30",

  "order": {
    "id": 12345,
    "increment_id": "#000012345",
    "created_at": "2026-01-24T10:30:00+05:30",
    "status": "processing",
    "state": "new"
  },

  "financials": {
    "total_value": 250000,
    "subtotal": 220000,
    "shipping_amount": 5000,
    "discount_amount": 15000,
    "tax_amount": 40000,
    "currency_code": "INR"
  },

  "flags": {
    "discounted": true,
    "is_virtual": false,
    "is_guest": false
  },

  "items": [
    {
      "item_id": 5432,
      "product_id": 1234,
      "sku": "WN-ACRY-SET-12",
      "name": "Winsor & Newton Acrylic Paint Set",
      "qty": 2,
      "price": 125000,
      "row_total": 250000,
      "product_url": "https://artlounge.in/winsor-newton-acrylic-set",
      "product_image_url": "https://cdn.artlounge.in/media/catalog/product/w/n/wn-acry.jpg",
      "categories": ["Paints", "Acrylics", "Professional Grade"]
    }
  ],

  "summary": {
    "ordered_products": ["Winsor & Newton Acrylic Paint Set"],
    "ordered_product_count": 1,
    "product_categories": ["Paints", "Acrylics", "Professional Grade"]
  },

  "customer": {
    "email": "artist@example.com",
    "firstname": "Priya",
    "lastname": "Sharma",
    "customer_id": 5678,
    "customer_group": "General"
  },

  "addresses": {
    "billing": {
      "firstname": "Priya",
      "lastname": "Sharma",
      "street": ["123 Art Street", "Apt 4B"],
      "city": "Mumbai",
      "region": "Maharashtra",
      "postcode": "400001",
      "country_id": "IN",
      "telephone": "+91-9876543210"
    },
    "shipping": {
      "firstname": "Priya",
      "lastname": "Sharma",
      "street": ["123 Art Street", "Apt 4B"],
      "city": "Mumbai",
      "region": "Maharashtra",
      "postcode": "400001",
      "country_id": "IN",
      "telephone": "+91-9876543210"
    }
  },

  "store": {
    "store_id": 1,
    "store_code": "default",
    "website_id": 1
  },

  "payment": {
    "method": "razorpay",
    "method_title": "Razorpay"
  },

  "shipping": {
    "method": "flatrate_flatrate",
    "method_title": "Flat Rate - Fixed"
  }
}
```

### Abandoned Cart Event Payload

```json
{
  "event_type": "$abandoned",
  "uuid": "550e8400-e29b-41d4-a716-446655440001",
  "timestamp": "2026-01-24T11:30:00+05:30",

  "cart": {
    "quote_id": 98765,
    "created_at": "2026-01-24T10:00:00+05:30",
    "updated_at": "2026-01-24T10:15:00+05:30",
    "abandoned_duration_minutes": 75
  },

  "financials": {
    "total_value": 150000,
    "subtotal": 140000,
    "discount_amount": 0,
    "currency_code": "INR"
  },

  "items": [
    {
      "item_id": 9876,
      "product_id": 2345,
      "sku": "FAB-CANVAS-24X36",
      "name": "Fabriano Canvas 24x36 inch",
      "qty": 1,
      "price": 150000,
      "product_url": "https://artlounge.in/fabriano-canvas-24x36",
      "product_image_url": "https://cdn.artlounge.in/media/catalog/product/f/a/fab-canvas.jpg",
      "categories": ["Canvas", "Stretched Canvas", "Large Format"]
    }
  ],

  "customer": {
    "email": "browsing.artist@example.com",
    "firstname": "Rahul",
    "lastname": "Verma",
    "is_guest": true
  },

  "recovery": {
    "cart_url": "https://artlounge.in/checkout/cart/restore/code/abc123xyz"
  }
}
```

### Customer Event Payload

```json
{
  "event_type": "$Subscriber",
  "uuid": "550e8400-e29b-41d4-a716-446655440002",
  "timestamp": "2026-01-24T09:00:00+05:30",

  "customer": {
    "customer_id": 5678,
    "email": "newartist@example.com",
    "firstname": "Anita",
    "lastname": "Desai",
    "created_at": "2026-01-24T09:00:00+05:30",
    "dob": "1990-05-15",
    "gender": "Female",
    "group_id": 1,
    "group_name": "General"
  },

  "addresses": {
    "default_billing": {
      "street": ["456 Creativity Lane"],
      "city": "Bangalore",
      "region": "Karnataka",
      "postcode": "560001",
      "country_id": "IN",
      "telephone": "+91-9876543211"
    }
  },

  "tags": ["lead", "mql", "website_registration"],

  "store": {
    "store_id": 1,
    "website_id": 1
  }
}
```

### Product View Event Payload (Client-Side)

```javascript
{
  "event_type": "$view",
  "unique_key": "1234",
  "timestamp": "2026-01-24T10:00:00+05:30",

  "details": {
    "product_id": 1234,
    "sku": "WN-ACRY-SET-12",
    "name": "Winsor & Newton Acrylic Paint Set",
    "price": 125000,
    "url": "https://artlounge.in/winsor-newton-acrylic-set",
    "image_url": "https://cdn.artlounge.in/media/catalog/product/w/n/wn-acry.jpg",
    "categories": ["Paints", "Acrylics", "Professional Grade"],
    "in_stock": true,
    "brand": "Winsor & Newton",
    "special_price": null,
    "currency_code": "INR"
  }
}
```

---

## API Contracts

### Bento Webhook Endpoint

**URL Pattern:**
```
POST https://track.bentonow.com/webhooks/{site-uuid}/{source}/track
```

**Headers:**
```
Content-Type: application/json
X-Bento-Signature: sha256=HMAC-SHA256(body, verification_token)
X-Bento-Event: $purchase | $abandoned | $Subscriber | etc.
X-Magento-Store: store_code
User-Agent: ArtLounge-Magento/1.0
```

**Authentication:**
HMAC-SHA256 signature in X-Bento-Signature header.

**Response Codes:**
| Code | Meaning | Action |
|------|---------|--------|
| 200 | Success | Log and complete |
| 201 | Created | Log and complete |
| 400 | Bad Request | Log error, do not retry |
| 401 | Unauthorized | Log error, do not retry |
| 429 | Rate Limited | Retry with backoff |
| 500 | Server Error | Retry with backoff |
| 502 | Bad Gateway | Retry with backoff |
| 503 | Unavailable | Retry with backoff |
| 504 | Timeout | Retry with backoff |

### Internal Magento REST API

**Test Connection:**
```
POST /V1/artlounge/bento/test-connection

Response:
{
  "success": true,
  "message": "Successfully connected to Bento API",
  "bento_site": "artlounge",
  "response_time_ms": 145
}
```

**Get Configuration:**
```
GET /V1/artlounge/bento/config

Response:
{
  "enabled": true,
  "events": {
    "order_placed": true,
    "order_shipped": true,
    "abandoned_cart": true,
    "product_views": true
  },
  "abandoned_cart": {
    "delay_minutes": 60,
    "min_value": 500,
    "excluded_groups": [4]
  }
}
```

---

## Next Steps

1. **Review** this specification with your development team
2. **Proceed** to `02-INSTALLATION-GUIDE.md` for step-by-step installation
3. **Reference** the module code in `modules/` directory
4. **Run** tests as documented in `05-TESTING-GUIDE.md`

---

*Document prepared for Art Lounge Magento 2 Integration Project*
