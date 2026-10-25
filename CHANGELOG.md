# Changelog — Bento Integration Plugin

All notable changes to this project are documented below. This project follows [Semantic Versioning](https://semver.org/).

---

## [2.2.0] — 2026-10-24

Two more product lists on the subscriber for SP-02 "Just in", RP-02 and the monthly VIP-02 ("Your usuals" + new arrivals), refreshed like next picks — and the nightly refresh now covers VIPs, who often haven't ordered in 30 days.

### Added
- **`new_in_{1..3}_{name,url,image_url}`**: products created in the *New-arrivals Window* (120 days) whose medium — by the existing Category → Medium Map, deepest mapped category wins, the same rule as `primary_medium` — is the customer's; newest first (`created_at`). A parent counts only when it is new itself (a new variant on an old product is not a new arrival). Same enabled / visible / minimum-stock filter and variant display as next picks. Anything bought and any current `next_pick_*` are skipped; a short list stays short (blank medium → newest across all mapped mediums).
- **`usual_{1..3}_{name,url,image_url}`**: the customer's own products bought in ≥ 2 separate non-canceled orders (orders, not units, so one bulk order is not a habit), most orders first, then most recent purchase; parent level; only if currently enabled, visible and in stock. A configurable shows the variant the customer ordered most when it is sellable, otherwise the best-selling sellable variant; the URL is always the parent page.
- Both are sent wherever `next_pick_*` is (every profile push, `bento:backfill`, the nightly cron, the CLI); unused slots are `''`; if the lists can't be computed none of the three lists' fields are sent.
- **Nightly audience = buyers with an order in the *Refresh Window* (130 days; was 30 in v2.1.0) + VIPs, de-duplicated.** The win-back emails at days 91–112 read `new_in_*` and `usual_*`, so those customers must stay fresh. VIP = StatsCalculator `lifetime_spend` ≥ *VIP Lifetime Spend Threshold* (₹15,000) OR `order_count` ≥ *VIP Order-count Threshold* (5). The cron log carries `recent`, `vips` and `audience` counts.
- **CLI** `bento:next-picks`: `--recent`, `--vips` (neither = both, like the cron), `--email`, `--days` (default: the Refresh Window), `--dry-run`, `--medium`.
- **Admin** (Customer Profile Fields): *New-arrivals Window (days)* 120, *Refresh Window (days)* 130, *VIP Lifetime Spend Threshold (₹)* 15000, *VIP Order-count Threshold* 5.

### Changed
- `Refresh::run()` takes the audience (`$email`, `$recent`, `$vips`, `?int $days` — null = Refresh Window, …) instead of `($days, $email, …)`. `RefreshNextPicks::DAYS` is gone.
- `NextPicksProvider` gained a `ProfileLoader` constructor argument: clear `generated/code` (or `setup:di:compile` on production).

---

## [2.1.0] — 2026-10-23

Next picks for PP-03 (and later the second-purchase, replenishment and win-back emails): up to three in-stock add-ons for the customer's medium, kept current by Magento because Bento can't query it at send time.

### Added
- **Subscriber fields `next_pick_{1..3}_{name,url,image_url}`** on every profile push ($purchase, $OrderCanceled, $OrderRefunded, $account_created, `bento:backfill`). A configurable is shown as its best-selling in-stock variant (variant name and thumbnail, falling back to the parent's image) and links to the parent's page; anything else shows its own name, URL and thumbnail (the order/cart snapshot thumbnail rule). Unused slots are sent as `''` so a shorter list clears older picks. If picks can't be computed, no pick fields are sent and Bento keeps the last ones.
- **Pick rule** (`Model/Picks/NextPicks`, `NextPicksProvider`): pinned SKUs of the customer's `primary_medium` first, then the medium's add-on categories (subcategories included) ranked by units sold on non-canceled orders in the sales window, parent level. Only enabled, visible products with ≥ minimum stock (a configurable: any enabled variant) qualify; anything the customer ever bought is excluded. A blank or unmapped medium, or fewer than 3 picks, fills from `any`.
- **Admin** (Customer Profile Fields): *Add-on Categories per Medium* (`medium=id,id,…`, mediums watercolour, acrylic, oil, drawing, pastel, ink, any), *Pinned Picks per Medium* (`medium=SKU,SKU,SKU`), *Recent-sales Window* (90 days), *Minimum Stock to Recommend* (3). Data patch `DefaultNextPicksConfig` writes the defaults, resolved from the live category tree, unless an admin already saved them.
- **Cron `artlounge_bento_next_picks`** (03:45 daily): profile fields incl. picks for every buyer with a counted order in the last 30 days, `/batch/subscribers` in batches of 1000, test-mode allow-list obeyed, skipped when Bento isn't configured. Logs eligible/sent/batch counts; a failed batch is logged and retried the next night.
- **CLI `bento:next-picks`**: `--email` (one customer), `--days`, `--dry-run`, `--batch-size`, and `--medium=oil` to print what a customer with no purchases would get.

### Upgrade
- `ProfileFieldsProvider` gained a constructor argument: run `setup:upgrade` and clear `generated/code` (or `setup:di:compile` on production).

---

## [2.0.1] — 2026-10-21

The recovery emails promise the ₹200 code comes "on top of your automatic discount", so the code must always stack with the discount ladder (rules 680–682, priority 100+).

### Fixed
- **Managed coupon rule priority 1000 → 1** (`RuleManager`), `stop_rules_processing` stays off. At 1000 the code ran after the ladder, so one ladder rule set to "discard subsequent rules" would silently kill it. At 1 it runs after rule 744 (priority 0, "discounted items get no other discount") and before the ladder, and both apply. An existing rule is moved on its next use (`ensureRule`, called before every coupon is issued) and on admin config save (`syncRuleFromConfig`), so no manual admin edit is needed.

### Added
- **`$checkout_abandoned` carries the coupon**, through the same `CouponGate` as `$cart_abandoned` (same threshold, single-use code, expiry): `coupon_eligible`, `coupon{code, expires_at, expires_at_display, recovery_url}` (only `coupon.recovery_url` auto-applies the code) and the subscriber fields `last_cart_coupon_{eligible,code,url,expires}` for CO-03C. The code is keyed on the quote, so a quote that already got one from `$cart_abandoned` reuses it and never gets a second code. Below the threshold the coupon fields are blanked, as on `$cart_abandoned`.

---

## [2.0.0] — 2026-10-13

Redesign per `docs/superpowers/specs/2026-10-13-bento-plugin-redesign-design.md` (BentoAutomationsToBuild repo).

### Breaking
- **Transport is now a transactional outbox + cron.** `Aligent_AsyncEvents`, RabbitMQ topics/consumers, `BentoNotifier`, `ServiceDataResolver`, dead-letter monitor, abandoned-cart scheduler/consumer/admin grid and the `CreateBentoSubscriptions` patch are removed. Tables `artlounge_bento_event_outbox`, `artlounge_bento_event_sent`, `artlounge_bento_abandoned_cart_schedule` are dropped.
- Events removed: `$OrderShipped` (WISMO owns shipping), `$CustomerUpdated`, `$cart_created`, client-side `$purchase` fallback, `optin`. The plugin sends **no tags**.
- `$purchase` fires when an order **first reaches processing/complete** (COD or captured prepaid), never for `pending_payment`.

### Added
- `artlounge_bento_outbox` (dedup key unique, backoff 1m/5m/30m/2h/12h → parked), `artlounge_bento_purchase` markers (seeded from history), `artlounge_bento_checkout_contact`.
- Crons: `artlounge_bento_outbox_send` (every minute), `artlounge_bento_abandonment` (5 min), `artlounge_bento_reconcile` (nightly).
- `$checkout_started` / `$checkout_abandoned` via Razorpay Magic Checkout controller plugins + browser contact capture (`POST /bento/checkout/contact`).
- Customer profile fields via `/batch/subscribers`: order_count, lifetime_spend, aov, first_order_date, last_purchase_date, last_order_number, customer_since, is_registered, top_categories, primary_medium (+ admin category→medium map).
- All amounts carry paise integers **and** `*_display` strings (₹1,488.60, Indian grouping). All dates ISO-8601 IST (`2026-10-13T15:30:00+05:30`) from one formatter.
- Site-wide browser `$cart_updated` (customer-data cart diff, 3s debounce).
- Test mode: email allow-list (globs); CLI `bento:outbox:status|replay|run|preview`, `bento:backfill`.
- Coupons gated by cart threshold; `recovery_url` never carries the coupon. Eligible carts get `coupon{code, expires_at, expires_at_display, recovery_url}` — `coupon.recovery_url` auto-applies the code.
- Flow contract names (Bento build specs): `financials.total_display`, `cart.items[].product_price_display`, `$checkout_abandoned` `abandon_source` (`dismissed` / `contact_captured`).

### Added — email design (The Order Sheet) support, 2026-10-14
- **Snapshot fields on the subscriber** (event `fields`, so the flows no longer need per-slot Update Field nodes):
  `$cart_abandoned` / `$checkout_abandoned` → `last_cart_item_{1..5}_{name,qty,price_display,url,image_url}`,
  `last_cart_item_count`, `last_cart_subtotal` (paise), `last_cart_subtotal_display`, `last_cart_recovery_url`;
  `$cart_abandoned` also `last_cart_coupon_{eligible,code,url,expires}`. `$purchase` →
  `last_order_item_{1..5}_{name,qty,url,image_url}`, `last_order_item_count`. Unused slots are sent as `''`.
- **Email thumbnails**: every `items[].product_image_url` (and the `*_image_url` fields) is the product's
  thumbnail → small_image → image → configurable parent's image, as a resized cache URL built by Magento's
  image UrlBuilder under frontend store emulation (image id `wishlist_small_image`, 113×113, set in di.xml;
  host = the store's media base URL). Configurable lines prefer the chosen variant's picture.
- **`$review_submitted`** (storefront review saves): `product{sku,name,url,image_url}`, `rating` 1–5 / null,
  `review_id`, `status`, `nickname`. Guests are attributed via the email-click `bento_uuid` (browser module
  `bento-uuid.js` → first-party cookie `al_bento_uuid`), resolved to an email at send time with
  `GET /fetch/subscribers?uuid=`; no identity → not sent. Admin: Product Reviews › Send $review_submitted.

### Fixed
- Secret key is decrypted before use (v1 sent the encrypted config value).
- `summary.product_categories` is always a JSON list.

---

## [1.3.1] — 2026-03-24

### Fixed
- **Abandoned cart scheduler**: `scheduleViaCron` no longer overwrites `sent` status back to `pending` — records that have already been delivered are left untouched, preserving proof-of-delivery in the admin grid
- Removed `status` from the `ON DUPLICATE KEY UPDATE` column list so only the Checker (via `markProcessed`) can transition status

---

## [1.3.0] — 2026-03-22

### Added
- **Bento-managed Cart Price Rule** — the plugin now auto-creates and manages its own Cart Price Rule for abandoned cart recovery coupons, eliminating manual admin setup
- `RuleManager` service handles rule lifecycle: create, sync, verify, deactivate
- Admin config fields for coupon discount type (percentage/fixed), discount amount, and minimum cart subtotal
- `ManagedRuleInfo` admin block showing managed rule name, ID, status, and edit link
- `AdminConfigSaveObserver` syncs managed rule when admin saves Bento config
- Rule auto-deactivates when coupon feature is disabled

### Changed
- `CouponService` now uses `RuleManager::ensureRule()` instead of manual rule ID config
- `CouponCleanup` cron uses `RuleManager::getManagedRuleId()` for rule lookup
- Coupon dedup uses `description` field only (survives rule recreation)
- Removed manual Cart Price Rule dropdown from admin config (`CartPriceRule` source model deleted)

### Fixed
- **R-013**: Coupons silently rejected when parent rule had `use_auto_generation=0` — managed rule guarantees `use_auto_generation=1`

---

## [1.2.6] — 2026-03-22

### Fixed
- **R-012 follow-up**: Use `Magento\SalesRule\Helper\Coupon::COUPON_TYPE_SPECIFIC_AUTOGENERATED` constant (the `Coupon` model class does not define this constant)

---

## [1.2.5] — 2026-03-22

### Fixed
- **R-012**: Add missing `description` column to `salesrule_coupon` table via `db_schema.xml` — coupon generation failed with `Column not found: 1054 Unknown column 'description'`

---

## [1.2.2] — 2026-03-21

### Fixed
- Enforce abandoned cart eligibility at every stage of the lifecycle
- Use `processed_at` for abandoned cart stuck-processing detection
- JSON-decode serialized arguments in `ServiceDataResolver` before extracting entity ID
- Use correct Magento interface methods in Refund, Shipment, and Order services
- Enable database persistence for Bento event subscriptions
- Use `updated_at` for outbox stuck-processing detection to prevent race condition
- Use correct Magento APIs for discount and currency in abandoned cart data

---

## [1.2.1] — 2026-03-21

### Security
- Prevent cross-store cart recovery IDOR vulnerability — recovery links are now validated against the originating store to block unauthorized access

---

## [1.2.0] — 2026-03-21

### Added
- Server-side `$checkoutStarted` event via `CheckoutService` and `QuoteSaved` observer
- `EventDeduplicator` service and `artlounge_bento_event_dedup` table to prevent duplicate event processing
- Event dedup table cleanup integrated into `ProcessAbandonedCarts` cron

### Changed
- Checkout template stripped to identification only — event tracking moved entirely server-side

---

## [1.1.0] — 2026-03-20

### Added
- `CouponService` for generating unique coupon codes in abandoned cart recovery emails
- Coupon admin configuration fields and cart price rule source model
- Coupon data forwarded through `BentoClient` event details
- Auto-apply coupon code from recovery link URL
- Daily cron to cleanup expired Bento coupons

---

## [1.0.0] — 2026-03-20

Initial release. See feature history below for pre-release development details.

---

## Pre-release Feature History

### Outbox Fallback System

| Commit | Description |
|--------|-------------|
| `1338821` | Add `artlounge_bento_event_outbox` table schema |
| `4ffca66` | Add OutboxWriter for persisting failed AMQP publishes |
| `c1db9fa` | Add OutboxProcessor with atomic claims and exponential backoff |
| `9c0656a` | Add OutboxReplay and OutboxCleanup cron classes |
| `bc12688` | Add `bento:outbox:process` and `bento:outbox:status` CLI commands |
| `c4b1d85` | Register DI, cron jobs, and CLI commands |
| `f14c3d3` | Add outbox fallback to all 7 event observers |
| `a6ac203` | Extract `STATUS_*` constants and remove magic numbers |
| `00dbaa2` | Audit fixes — outbox fallback, config retry, store-scoped images, atomic cart claims |

**Why:** When RabbitMQ is unavailable — whether due to a service restart, network blip, or misconfiguration — every event publish would silently fail and the event data would be lost forever. The outbox catches failed AMQP publishes in a database table (`artlounge_bento_event_outbox`) and retries them automatically via cron with exponential backoff. CLI commands (`bento:outbox:process`, `bento:outbox:status`) give operators visibility into pending and failed events. The audit pass in `00dbaa2` hardened the system with atomic row claims (preventing duplicate processing by concurrent cron runs), store-scoped image URLs, and config retry logic.

---

### Dead-Letter Improvements

| Commit | Description |
|--------|-------------|
| `f0758f2` | Add dead-letter replay CLI, queue monitor, and increase `max_deaths` to 20 |
| `2f0265d` | Use `DEAD_LETTER_ROUTING_KEY` for direct retry queue delivery |

**Why:** Events that repeatedly fail processing (e.g., due to a transient Bento API outage) land in the dead-letter queue. The original setup had a low `max_deaths` threshold (events were discarded too quickly) and no way to inspect or replay dead-lettered messages. These commits added a CLI replay command, a queue monitor for observability, raised the retry ceiling to 20 attempts, and fixed the routing key so retried messages go back to the correct queue instead of being misrouted.

---

### Server-Side Pipeline Fixes

| Commit | Description |
|--------|-------------|
| `b76f6e5` | Complete server-side pipeline and fix client-side tracking gaps |
| `16ca22b` | Fix server-side event delivery — `ServiceOutputProcessor` key stripping |
| `98087fb` | P6 purchase event fixes — dedup, value format, item URLs, retry |
| `9676eb7` | Complete `$purchase` payload — add canonical fields + pass-through financials/customer/payment/shipping |

**Why:** Magento's `ServiceOutputProcessor::convertValue()` iterates array values with `foreach ($array as $datum)`, silently destroying all associative keys. This meant every server-side event payload arrived at Bento as a flat positional array with no field names — completely unusable. The fix (`ServiceDataResolver`) re-invokes the service method to reconstruct keyed data. Subsequent commits enriched the `$purchase` payload with the full set of fields Bento expects (line items with URLs, `value` as `{ amount, currency }` not a flat number, shipping/payment/customer details) and added deduplication guards so the same order isn't reported twice.

---

### Client-Side Tracking Fixes

| Commit | Description |
|--------|-------------|
| `442b658` | Resolve tracking script, API auth, and double-fire issues |
| `742efe7` | Add manual `bento.view()` call and window-level dedup guards |
| `b08c709` | Send rich product data and fix user identification in tracking events |
| `5b2dfbc` | Use correct event type names for add-to-cart and purchase tracking |
| `f316638` | Eliminate duplicate `$view` events and fix attribution race condition |
| `cc6441a` | Eliminate duplicate `$view` and fix first-page attribution race |
| `69d0853` | Use window-level dedup for same-page, allow cross-page attribution |
| `0c0e8c5` | Remove redundant browse history sync — Bento's native visitor merge handles attribution |
| `a1688c0` | Fix anonymous-to-identified attribution and enrich product view data |

**Why:** The initial client-side integration had several compounding issues. The tracking script fired twice per page load (once from the template, once from RequireJS), event names used custom strings (`add_to_cart`) instead of Bento's native `$`-prefixed names (`$cart_created`), and product `$view` events carried no product data. Attribution was also broken: on first page load the `bento.identify()` AJAX call hadn't completed before `bento.track()` fired, so events were recorded against an anonymous visitor instead of the logged-in customer. The fix pre-identifies from `localStorage` and uses window-level flags (`_bentoProductTrackingDone`) to prevent duplicate events. A redundant browse-history sync was removed after confirming Bento's native visitor merge already handles cross-session attribution.

---

### Subscriber & Customer Fixes

| Commit | Description |
|--------|-------------|
| `c12ab4d` | Fix newsletter-to-Bento subscriber pipeline and footer form bugs |
| `3a3493f` | D6 subscriber event fixes — subscriber data in details, guest-linking guard, customer update event |

**Why:** Newsletter signups from the footer form were not reaching Bento at all — the observer was wired to the wrong event, and the form itself had frontend bugs preventing submission. Once the pipeline was fixed, a second round addressed data quality: subscriber details (email, status, store) were moved into the event's `details` object where Bento expects them, a guard was added to prevent ghost-linking guest subscribers to unrelated customer accounts, and a missing customer-update event was added so profile changes (name, email) propagate to Bento.

---

### Cart Lifecycle & Abandoned Cart

| Commit | Description |
|--------|-------------|
| `8dc85ec` | Full cart snapshots, `cart_id` lifecycle, and `$cart_abandoned` rename |
| `a62814c` | Invalidate `bento-identity` section on `cart/add` for guest `quote_id` |
| `6ed060c` | Detect minicart qty/remove changes and send `$cart_updated` events |

**Why:** Bento's abandoned-cart recovery requires a complete cart snapshot (all items, quantities, prices, and a `cart_id` that persists across the session). The initial implementation only sent the most recently added item and used an event name Bento didn't recognise for abandonment. These commits rebuilt cart tracking to send full snapshots on every change, introduced a stable `cart_id` tied to Magento's `quote_id`, renamed the abandonment event to `$cart_abandoned` (Bento's expected name), and added detection for minicart quantity edits and item removals — interactions that previously went untracked. The `bento-identity` section invalidation ensures guest users get a fresh `quote_id` association immediately after adding to cart, so the very first cart event is already attributed.
