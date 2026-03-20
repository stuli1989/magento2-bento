# Changelog — Bento Integration Plugin

All changes since initial release (baseline: commit `5d3d19c`).

---

## 1. Outbox Fallback System (New Feature)

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

## 2. Dead-Letter Improvements

| Commit | Description |
|--------|-------------|
| `f0758f2` | Add dead-letter replay CLI, queue monitor, and increase `max_deaths` to 20 |
| `2f0265d` | Use `DEAD_LETTER_ROUTING_KEY` for direct retry queue delivery |

**Why:** Events that repeatedly fail processing (e.g., due to a transient Bento API outage) land in the dead-letter queue. The original setup had a low `max_deaths` threshold (events were discarded too quickly) and no way to inspect or replay dead-lettered messages. These commits added a CLI replay command, a queue monitor for observability, raised the retry ceiling to 20 attempts, and fixed the routing key so retried messages go back to the correct queue instead of being misrouted.

---

## 3. Server-Side Pipeline Fixes

| Commit | Description |
|--------|-------------|
| `b76f6e5` | Complete server-side pipeline and fix client-side tracking gaps |
| `16ca22b` | Fix server-side event delivery — `ServiceOutputProcessor` key stripping |
| `98087fb` | P6 purchase event fixes — dedup, value format, item URLs, retry |
| `9676eb7` | Complete `$purchase` payload — add canonical fields + pass-through financials/customer/payment/shipping |

**Why:** Magento's `ServiceOutputProcessor::convertValue()` iterates array values with `foreach ($array as $datum)`, silently destroying all associative keys. This meant every server-side event payload arrived at Bento as a flat positional array with no field names — completely unusable. The fix (`ServiceDataResolver`) re-invokes the service method to reconstruct keyed data. Subsequent commits enriched the `$purchase` payload with the full set of fields Bento expects (line items with URLs, `value` as `{ amount, currency }` not a flat number, shipping/payment/customer details) and added deduplication guards so the same order isn't reported twice.

---

## 4. Client-Side Tracking Fixes

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

## 5. Subscriber & Customer Fixes

| Commit | Description |
|--------|-------------|
| `c12ab4d` | Fix newsletter-to-Bento subscriber pipeline and footer form bugs |
| `3a3493f` | D6 subscriber event fixes — subscriber data in details, guest-linking guard, customer update event |

**Why:** Newsletter signups from the footer form were not reaching Bento at all — the observer was wired to the wrong event, and the form itself had frontend bugs preventing submission. Once the pipeline was fixed, a second round addressed data quality: subscriber details (email, status, store) were moved into the event's `details` object where Bento expects them, a guard was added to prevent ghost-linking guest subscribers to unrelated customer accounts, and a missing customer-update event was added so profile changes (name, email) propagate to Bento.

---

## 6. Cart Lifecycle & Abandoned Cart

| Commit | Description |
|--------|-------------|
| `8dc85ec` | Full cart snapshots, `cart_id` lifecycle, and `$cart_abandoned` rename |
| `a62814c` | Invalidate `bento-identity` section on `cart/add` for guest `quote_id` |
| `6ed060c` | Detect minicart qty/remove changes and send `$cart_updated` events |

**Why:** Bento's abandoned-cart recovery requires a complete cart snapshot (all items, quantities, prices, and a `cart_id` that persists across the session). The initial implementation only sent the most recently added item and used an event name Bento didn't recognise for abandonment. These commits rebuilt cart tracking to send full snapshots on every change, introduced a stable `cart_id` tied to Magento's `quote_id`, renamed the abandonment event to `$cart_abandoned` (Bento's expected name), and added detection for minicart quantity edits and item removals — interactions that previously went untracked. The `bento-identity` section invalidation ensures guest users get a fresh `quote_id` association immediately after adding to cart, so the very first cart event is already attributed.
