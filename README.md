# Bento Integration for Magento 2 (v2)

Sends Art Lounge's store activity to [Bento](https://bentonow.com) — reliably, without RabbitMQ,
and without ever slowing or breaking checkout. Design spec: `docs/superpowers/specs/2026-10-13-bento-plugin-redesign-design.md`
(BentoAutomationsToBuild repo). v1 architecture notes: `docs/architecture-v1.md` (historical).

## How it works

```
observers (after commit) / Razorpay controller plugins / POST /bento/checkout/contact
      │  one INSERT IGNORE into artlounge_bento_outbox (event, entity id, email, dedup_key) — never throws
      ▼
cron artlounge_bento_outbox_send (every minute, DB lock, ≤100 rows, ≤30 s)
      │  test-mode filter → payload builder → POST /batch/subscribers (profile fields) → POST /batch/events
      ▼
2xx sent · 4xx parked (body kept) · 5xx/timeout retry 1m, 5m, 30m, 2h, 12h → parked
```

## Events

| Event | Source | When |
|---|---|---|
| `$view` | browser | PDP view, once per product per day |
| `$cart_updated` | browser | any cart change anywhere (customer-data diff, 3 s debounce) |
| `$checkout_started` | server | Magic Checkout popup opened by a logged-in customer (once per quote) |
| `$checkout_abandoned` | server | popup dismissed (Razorpay `pending_payment` order) or contact captured and no payment within the delay; same coupon gate as `$cart_abandoned`, reusing the quote's code if it already has one |
| `$cart_abandoned` | server cron | identified cart idle ≥ delay, ≥ min value; `coupon_eligible` + coupon only above the threshold |
| `$purchase` | server | order first reaches processing/complete (COD or captured prepaid); `unique.key` = increment id |
| `$OrderCanceled` | server | order canceled after a `$purchase` |
| `$OrderRefunded` | server | credit memo |
| `$subscribe` / `$unsubscribe` | server | newsletter status change |
| `$account_created` | server | customer registration |
| `$review_submitted` | server | product review submitted on the storefront (logged-in → account email; guest → email-click `bento_uuid`, else not sent) |

The plugin sends **no tags**. Every amount has a paise integer and a `*_display` twin (`₹1,488.60`).
Every date is ISO-8601 IST (`2026-10-13T15:30:00+05:30`) from one formatter (`BentoCore\Model\IstDate`).

**Snapshot fields** (event `fields`, applied to the subscriber; templates read `visitor.*`). Always 5 slots,
unused slots `''`:
- `$cart_abandoned`, `$checkout_abandoned`: `last_cart_item_{1..5}_{name,qty,price_display,url,image_url}`,
  `last_cart_item_count`, `last_cart_subtotal` (paise), `last_cart_subtotal_display`, `last_cart_recovery_url`;
  both: `last_cart_coupon_eligible` (`yes`/`no`), `last_cart_coupon_code`, `last_cart_coupon_url`,
  `last_cart_coupon_expires`.
- `$purchase`: `last_order_item_{1..5}_{name,qty,url,image_url}`, `last_order_item_count`.

`*_image_url` / `items[].product_image_url` = thumbnail → small_image → image → configurable parent, as a
113 px cached URL on the store's media host (`ThumbnailUrlBuilder`, image id in `di.xml`); `''` when none.

Profile fields pushed with purchase/cancel/refund/account events and by `bento:backfill`:
`order_count, lifetime_spend, aov, first_order_date, last_purchase_date, last_order_number,
customer_since, is_registered, top_categories, primary_medium, first_name, last_name, phone`.

Product lists (same pushes, plus a nightly refresh at 03:45 of every buyer with an order in the Refresh
Window — 130 days, so the day 91–112 win-back emails read fresh lists — and every VIP: lifetime spend
≥ ₹15,000 or ≥ 5 orders; all three admin-configurable, de-duplicated):
`next_pick_{1..3}_{name,url,image_url}` — name, parent storefront URL and email thumbnail (a configurable shows its best-selling in-stock variant); `''` when
fewer than 3 qualify. Rule: pinned SKUs of the customer's `primary_medium`, then that medium's add-on
categories ranked by units sold in the last 90 days, enabled + visible + stock ≥ 3 only, minus anything the
customer has bought; filled from `any`. Admin: Customer Profile Fields › Next Picks.

`new_in_{1..3}_*` — products created in the last 120 days whose medium (Category → Medium Map, deepest
category wins) is the customer's, newest first, same stock filter, minus anything bought or already a
`next_pick_*`; no fill (a blank medium gets the newest across all mediums).

`usual_{1..3}_*` — the customer's own products bought in ≥ 2 separate non-canceled orders, most orders
first then most recent; same stock filter; a configurable shows the variant they ordered most if it is in
stock, else the best-selling in-stock one; URL = the parent page.
## Operating it

```bash
bin/magento bento:outbox:status                 # counts, oldest pending, parked rows, contact-capture health
bin/magento bento:outbox:preview --id=123       # exact JSON a row would send (no send)
bin/magento bento:outbox:run                    # one send pass now
bin/magento bento:outbox:replay --parked        # or --id=123 (repeatable)
bin/magento bento:backfill --dry-run [--email-pattern='*+bentotest*@*'] [--resume]
bin/magento bento:next-picks --medium=oil              # lists a no-history oil customer would get (no send)
bin/magento bento:next-picks [--email=a@b.com | --recent [--days=130] | --vips] [--dry-run]   # no flag = recent + VIPs
```

**Test mode:** Stores › Configuration › ArtLounge › Bento Integration › General › *Test Mode: Email
Allow-list*. When set (e.g. `*+bentotest*@*`), only matching emails are sent; others are marked
`skipped`. Leave empty on production.

## Development

```bash
composer install -d tests/tools          # PHPUnit + Mockery (no repo.magento.com needed)
php tests/tools/vendor/bin/phpunit -c phpunit.xml
node tests/js/cart-diff.test.js
node tests/js/bento-uuid.test.js
```

Unit tests run against stubs in `tests/Stubs` — no Magento bootstrap.
