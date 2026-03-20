# Admin Configuration Reference

**Document Version:** 1.0
**Date:** January 24, 2026

---

## Configuration Location

**Path:** Stores > Configuration > ArtLounge > Bento Integration

---

## Configuration Sections

### 1. General Settings

| Field | Config Path | Type | Default | Description |
|-------|-------------|------|---------|-------------|
| Enable Integration | `artlounge_bento/general/enabled` | Yes/No | No | Master switch for all Bento integration features |
| Site UUID | `artlounge_bento/general/site_uuid` | Text | - | Your Bento site identifier (found in Bento Settings > Site Settings) |
| Publishable Key | `artlounge_bento/general/publishable_key` | Text | - | API key for client-side tracking (safe to expose in frontend) |
| Secret Key | `artlounge_bento/general/secret_key` | Password | - | API key for server-side events (encrypted in database) |
| Source Identifier | `artlounge_bento/general/source` | Text | `artlounge` | Source label sent with Bento events |
| Debug Mode | `artlounge_bento/general/debug` | Yes/No | No | Enable verbose logging to var/log/bento.log |
| Test Connection | - | Button | - | Tests API connectivity (requires saved credentials) |

---

### 2. Order Events

| Field | Config Path | Type | Default | Description |
|-------|-------------|------|---------|-------------|
| Enable Order Events | `artlounge_bento/orders/enabled` | Yes/No | Yes | Enable all order-related events |
| Track Order Placed | `artlounge_bento/orders/track_placed` | Yes/No | Yes | Send `$purchase` event when order is placed |
| Track Order Shipped | `artlounge_bento/orders/track_shipped` | Yes/No | Yes | Send `$OrderShipped` event when shipment created |
| Track Order Cancelled | `artlounge_bento/orders/track_cancelled` | Yes/No | Yes | Send `$OrderCanceled` event |
| Track Order Refunded | `artlounge_bento/orders/track_refunded` | Yes/No | Yes | Send `$OrderRefunded` event |
| Include Tax in Totals | `artlounge_bento/orders/include_tax` | Yes/No | Yes | Include tax amount in total_value field |
| Currency Multiplier | `artlounge_bento/orders/currency_multiplier` | Integer | 100 | Multiply amounts (100 = cents, 1 = whole currency) |
| Include Product Images | `artlounge_bento/orders/include_images` | Yes/No | Yes | Include product image URLs in payload |
| Include Categories | `artlounge_bento/orders/include_categories` | Yes/No | Yes | Include product category names |

**Order Event Mapping:**

| Magento Event | Bento Event | When Triggered |
|---------------|-------------|----------------|
| `sales_order_place_after` | `$purchase` | Order placed successfully |
| `sales_order_shipment_save_after` | `$OrderShipped` | Shipment created |
| `sales_order_save_after` (status=canceled) | `$OrderCanceled` | Order canceled |
| `sales_order_creditmemo_save_after` | `$OrderRefunded` | Credit memo created |

---

### 3. Customer Events

| Field | Config Path | Type | Default | Description |
|-------|-------------|------|---------|-------------|
| Enable Customer Events | `artlounge_bento/customers/enabled` | Yes/No | Yes | Enable customer-related events |
| Track Customer Registration | `artlounge_bento/customers/track_created` | Yes/No | Yes | Send `$Subscriber` on registration |
| Track Customer Updates | `artlounge_bento/customers/track_updated` | Yes/No | No | Send `$CustomerUpdated` on profile changes |
| Default Tags | `artlounge_bento/customers/default_tags` | Text | `lead,mql` | Comma-separated tags for new subscribers |
| Include Address | `artlounge_bento/customers/include_address` | Yes/No | Yes | Include default address in payload |

**Customer Event Mapping:**

| Magento Event | Bento Event | When Triggered |
|---------------|-------------|----------------|
| `customer_register_success` | `$Subscriber` | New customer account created |
| `customer_save_after` | `$CustomerUpdated` | Customer data modified |

**Tag Format:**
- Tags are comma-separated
- Spaces are trimmed
- Example: `lead, mql, website_registration` becomes `["lead", "mql", "website_registration"]`

---

### 4. Newsletter Events

| Field | Config Path | Type | Default | Description |
|-------|-------------|------|---------|-------------|
| Enable Newsletter Events | `artlounge_bento/newsletter/enabled` | Yes/No | Yes | Enable newsletter subscription events |
| Track Subscribe | `artlounge_bento/newsletter/track_subscribe` | Yes/No | Yes | Send `$subscribe` event |
| Track Unsubscribe | `artlounge_bento/newsletter/track_unsubscribe` | Yes/No | Yes | Send `$unsubscribe` event |
| Apply Tags on Subscribe | `artlounge_bento/newsletter/subscribe_tags` | Text | `newsletter` | Tags added when subscribing |

**Newsletter Event Mapping:**

| Magento Event | Bento Event | Condition |
|---------------|-------------|-----------|
| `newsletter_subscriber_save_after` | `$subscribe` | Status = Subscribed |
| `newsletter_subscriber_save_after` | `$unsubscribe` | Status = Unsubscribed |

---

### 5. Abandoned Cart

| Field | Config Path | Type | Default | Description |
|-------|-------------|------|---------|-------------|
| Enable Abandoned Cart | `artlounge_bento/abandoned_cart/enabled` | Yes/No | Yes | Enable abandoned cart tracking |
| Delay (Minutes) | `artlounge_bento/abandoned_cart/delay_minutes` | Integer | 60 | Wait time before considering cart abandoned |
| Minimum Cart Value | `artlounge_bento/abandoned_cart/min_value` | Decimal | 500 | Only trigger for carts above this value |
| Require Email | `artlounge_bento/abandoned_cart/require_email` | Yes/No | Yes | Only trigger if customer email is known |
| Exclude Customer Groups | `artlounge_bento/abandoned_cart/exclude_groups` | Multi-select | - | Customer groups to exclude |
| Processing Method | `artlounge_bento/abandoned_cart/processing_method` | Select | cron | `cron` (recommended) or `queue` (requires delayed-message infrastructure) |
| Include Recovery URL | `artlounge_bento/abandoned_cart/include_recovery_url` | Yes/No | Yes | Include cart recovery link in payload |
| Prevent Duplicates | `artlounge_bento/abandoned_cart/prevent_duplicates` | Yes/No | Yes | Only send one abandoned cart per quote |
| Duplicate Window (Hours) | `artlounge_bento/abandoned_cart/duplicate_window` | Integer | 24 | Time window for duplicate prevention |

**Abandoned Cart Logic:**

```
Quote Saved Event
       │
       ▼
┌──────────────────────────────────┐
│ Check: Module enabled?           │ → No → Exit
└──────────────────────────────────┘
       │ Yes
       ▼
┌──────────────────────────────────┐
│ Check: Quote is active?          │ → No → Exit
└──────────────────────────────────┘
       │ Yes
       ▼
┌──────────────────────────────────┐
│ Check: Has items?                │ → No → Exit
└──────────────────────────────────┘
       │ Yes
       ▼
┌──────────────────────────────────┐
│ Check: Has email?                │ → No → Exit (if require_email=yes)
└──────────────────────────────────┘
       │ Yes/Not Required
       ▼
┌──────────────────────────────────┐
│ Check: Grand total >= min_value? │ → No → Exit
└──────────────────────────────────┘
       │ Yes
       ▼
┌──────────────────────────────────┐
│ Check: Customer group excluded?  │ → Yes → Exit
└──────────────────────────────────┘
       │ No
       ▼
┌──────────────────────────────────┐
│ Schedule check after delay       │
└──────────────────────────────────┘
       │
       ▼ (After delay)
┌──────────────────────────────────┐
│ Check: Quote still active?       │ → No → Exit (converted to order)
└──────────────────────────────────┘
       │ Yes
       ▼
┌──────────────────────────────────┐
│ Check: Not modified since?       │ → No → Reschedule (customer active)
└──────────────────────────────────┘
       │ Yes
       ▼
┌──────────────────────────────────┐
│ Check: Not already sent?         │ → Already sent → Exit
└──────────────────────────────────┘
       │ Not sent
       ▼
┌──────────────────────────────────┐
│ Send $abandoned event            │
└──────────────────────────────────┘
```

---

### 6. Client-Side Tracking

| Field | Config Path | Type | Default | Description |
|-------|-------------|------|---------|-------------|
| Enable Tracking | `artlounge_bento/tracking/enabled` | Yes/No | Yes | Master switch for client-side tracking |
| Track Product Views | `artlounge_bento/tracking/track_views` | Yes/No | Yes | Send `$view` on product pages |
| Track Add to Cart | `artlounge_bento/tracking/track_add_to_cart` | Yes/No | Yes | Send `$addToCart` events |
| Track Checkout Started | `artlounge_bento/tracking/track_checkout` | Yes/No | Yes | Send `$checkoutStarted` event |
| Add to Cart Selector | `artlounge_bento/tracking/add_to_cart_selector` | Text | `#product-addtocart-button` | CSS selector for add to cart button |
| Include Brand | `artlounge_bento/tracking/include_brand` | Yes/No | Yes | Include brand/manufacturer |
| Brand Attribute | `artlounge_bento/tracking/brand_attribute` | Select | `manufacturer` | Attribute code for brand |

**Tracking Script:**

The module injects Bento's tracking script on all pages when enabled, and renders scripts through Magento's `SecureHtmlRenderer` for CSP compatibility:

```html
<script src="https://app.bentonow.com/{publishable_key}.js" async></script>
```

**Product View Tracking:**

Automatically injects on `catalog_product_view` layout:

```javascript
bento.track('$view', {
    unique_key: productId,
    details: {
        product_id: 1234,
        sku: "ABC-123",
        name: "Product Name",
        price: 125000,  // cents
        url: "https://...",
        image_url: "https://...",
        categories: ["Cat1", "Cat2"],
        in_stock: true,
        brand: "Brand Name"
    }
});
```

---

### 7. Advanced Settings

| Field | Config Path | Type | Default | Description |
|-------|-------------|------|---------|-------------|
| Max Retry Attempts | `artlounge_bento/advanced/max_retries` | Integer | 5 | Maximum retry attempts before dead-lettering |
| Log Retention (Days) | `artlounge_bento/advanced/log_retention` | Integer | 30 | Days to keep event logs |
| Enable Elasticsearch Indexing | `artlounge_bento/advanced/elasticsearch` | Yes/No | Yes | Index logs in Elasticsearch |
| Request Timeout (Seconds) | `artlounge_bento/advanced/timeout` | Integer | 30 | HTTP timeout for Bento API requests |

---

## ACL Permissions

### Permission Structure

```
Stores > Configuration
└── ArtLounge
    └── Bento Integration
        └── ArtLounge_BentoCore::config
            ├── ArtLounge_BentoCore::config_general
            ├── ArtLounge_BentoCore::config_orders
            ├── ArtLounge_BentoCore::config_customers
            ├── ArtLounge_BentoCore::config_newsletter
            ├── ArtLounge_BentoCore::config_abandoned_cart
            ├── ArtLounge_BentoCore::config_tracking
            └── ArtLounge_BentoCore::config_advanced

System > Async Events
└── Aligent_AsyncEvents::manage
    ├── View Subscribers
    ├── Create/Edit Subscribers
    └── View Logs
        ├── View Trace
        └── Replay Events
```

### Role Configuration

**Marketing Manager Role:**
```
Allow:
- ArtLounge_BentoCore::config_general (view only)
- ArtLounge_BentoCore::config_abandoned_cart
- ArtLounge_BentoCore::config_tracking
- Aligent_AsyncEvents::async_events_logs_list
```

**Developer Role:**
```
Allow:
- ArtLounge_BentoCore::config (all)
- Aligent_AsyncEvents::manage (all)
```

---

## Configuration via CLI

### View Configuration

```bash
# View all Bento configuration
bin/magento config:show artlounge_bento

# View specific setting
bin/magento config:show artlounge_bento/general/enabled

# View encrypted value (shows encrypted string)
bin/magento config:show artlounge_bento/general/secret_key
```

### Set Configuration

```bash
# Enable integration
bin/magento config:set artlounge_bento/general/enabled 1

# Set Site UUID
bin/magento config:set artlounge_bento/general/site_uuid "your-site-uuid"

# Set abandoned cart delay
bin/magento config:set artlounge_bento/abandoned_cart/delay_minutes 45

# Set for specific store
bin/magento config:set artlounge_bento/general/enabled 1 --scope=stores --scope-code=default
```

### Sensitive Data (Encrypted)

```bash
# Set secret key (will be encrypted)
bin/magento config:sensitive:set artlounge_bento/general/secret_key "your_secret_key"
```

---

## Environment-Specific Configuration

### Development Environment

```
artlounge_bento/general/debug = 1
artlounge_bento/abandoned_cart/delay_minutes = 1  (quick testing)
```

### Staging Environment

```
artlounge_bento/general/debug = 1
artlounge_bento/abandoned_cart/delay_minutes = 5
artlounge_bento/general/site_uuid = staging-site-uuid
```

### Production Environment

```
artlounge_bento/general/debug = 0
artlounge_bento/abandoned_cart/delay_minutes = 60
artlounge_bento/general/site_uuid = production-site-uuid
artlounge_bento/advanced/elasticsearch = 1
```

---

## Configuration Validation

### Required Fields

The following fields are required for the integration to function:

1. `artlounge_bento/general/enabled` = Yes
2. `artlounge_bento/general/site_uuid` = (non-empty)
3. `artlounge_bento/general/secret_key` = (non-empty)

### Validation Rules

| Field | Validation |
|-------|------------|
| Site UUID | Non-empty string, alphanumeric with hyphens |
| Delay Minutes | Positive integer, 1-1440 (max 24 hours) |
| Min Cart Value | Non-negative decimal |
| Currency Multiplier | Positive integer (1, 10, 100, 1000) |
| Max Retries | Integer 1-10 |
| Timeout | Integer 5-120 seconds |
| CSS Selector | Non-empty string starting with # or . |

---

## Configuration Change Logging

All configuration changes are logged to `var/log/bento_config.log`:

```
2026-01-24 10:30:00 | admin@artlounge.in | artlounge_bento/general/enabled | 0 -> 1
2026-01-24 10:30:00 | admin@artlounge.in | artlounge_bento/abandoned_cart/delay_minutes | 60 -> 45
```

---

*Document prepared for Art Lounge Magento 2 Integration Project*
