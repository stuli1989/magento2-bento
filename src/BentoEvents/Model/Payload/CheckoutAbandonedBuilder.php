<?php
/**
 * $checkout_abandoned from either
 *  - entity "order": popup dismissed → Razorpay's pending_payment order (has email/phone/address), or
 *  - entity "contact": contact captured in the popup, then no payment/dismiss within the delay.
 * details.abandon_source = dismissed | contact_captured (details.source stays the generic "artlounge").
 *
 * Coupon: same gate as $cart_abandoned (CouponGate: enabled AND subtotal ≥ threshold). Eligible
 * checkouts get coupon_eligible + coupon{code, expires_at, expires_at_display, recovery_url}, where
 * coupon.recovery_url auto-applies the code and details.recovery_url never does. The code is keyed
 * on the quote, so a quote that already got one from $cart_abandoned reuses it — never a second code.
 *
 * Writes the cart snapshot + coupon fields onto the subscriber (SnapshotFields) for the templates.
 *
 * WhatsApp layer (v2.3.0, 00g §2): details.phone (E.164 or null) on both sources — captured contact →
 * order/quote billing then shipping telephone → the customer's default billing telephone; and
 * details.order_increment_id (dismissed only: Razorpay's pending_payment order, the one WISMO makes payable).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Coupon\CouponGate;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Pricing\Ladder;
use ArtLounge\BentoEvents\Model\Purchase\PurchaseDetector;

class CheckoutAbandonedBuilder implements BuilderInterface
{
    public function __construct(
        private readonly OrderSnapshotLoader $orders,
        private readonly QuoteSnapshotLoader $quotes,
        private readonly ContactStore $contacts,
        private readonly QuotePayloadMapper $mapper,
        private readonly RecoveryUrlBuilder $urls,
        private readonly CouponGate $couponGate,
        private readonly EventEnvelope $envelope,
        private readonly CustomerSnapshotLoader $customers,
        private readonly Ladder $ladder
    ) {
    }

    public function build(OutboxRow $row): BuildResult
    {
        if ($row->entityType === 'order') {
            $snapshot = $this->orders->load($row->entityId);
            if ($snapshot === null) {
                return BuildResult::skip('order ' . $row->entityId . ' not found');
            }
            if (in_array($snapshot['state'] ?? '', PurchaseDetector::EVER_PAID_STATES, true)
                || PurchaseDetector::isPurchased((string)($snapshot['state'] ?? ''), (string)($snapshot['status'] ?? ''))) {
                return BuildResult::skip('paid since dismissal');
            }
            // Closing the popup restores this quote with its items, so active and empty means the customer emptied it.
            $quote = $this->quotes->load((int)($snapshot['quote_id'] ?? 0));
            if ($quote !== null && !empty($quote['is_active']) && (int)$quote['items_count'] < 1) {
                return BuildResult::skip(CartAbandonedBuilder::SKIP_EMPTY);
            }
            // The customer may have edited the restored cart: the emails show the cart, not the closed attempt.
            if ($quote !== null && !empty($quote['is_active']) && !empty($quote['items'])) {
                $snapshot = ['items' => $quote['items'], 'subtotal' => $quote['subtotal'],
                    'grand_total' => $quote['grand_total'], 'discount_amount' => $quote['discount_amount']] + $snapshot;
            }
            $source = 'dismissed';
        } else {
            $contact = $this->contacts->getById($row->entityId);
            if ($contact === null) {
                return BuildResult::skip('contact ' . $row->entityId . ' not found');
            }
            $snapshot = $this->quotes->load((int)$contact['quote_id']);
            if ($snapshot === null) {
                return BuildResult::skip('quote ' . $contact['quote_id'] . ' not found');
            }
            if (!empty($snapshot['has_order']) && empty($snapshot['is_active'])) {
                return BuildResult::skip('converted to an order');
            }
            $snapshot['email'] = $contact['email'];
            $snapshot['phone'] = $contact['phone'] ?: ($snapshot['phone'] ?? null);
            $source = 'contact_captured';
        }

        if (empty($snapshot['phone']) && !empty($snapshot['customer_id'])) {
            $snapshot['phone'] = $this->customers->defaultPhone((int)$snapshot['customer_id']);
        }

        $details = $this->mapper->details($snapshot);
        $details['abandon_source'] = (string)$row->contextValue('source', $source);
        $details['phone'] = $details['customer']['phone'];
        if ($source === 'dismissed') {
            $details['order_increment_id'] = (string)($snapshot['increment_id'] ?? '');
        }
        $quoteId = (int)$snapshot['quote_id'];
        $subtotal = (float)($snapshot['subtotal'] ?? 0);
        // details.recovery_url feeds WhatsApp: it signs in only on the customer's own number. Email always may.
        $ownNumber = !empty($snapshot['customer_id']) && $this->customers->ownsPhone((int)$snapshot['customer_id'], $details['phone']);
        $details['recovery_url'] = $this->urls->build($quoteId, $row->email, $row->storeId, null, $ownNumber);

        $decision = $this->couponGate->evaluate($subtotal, $quoteId, $row->storeId);
        $details['coupon_eligible'] = $decision['eligible'] && $decision['coupon'] !== null;
        if ($details['coupon_eligible']) {
            $coupon = $decision['coupon'];
            $details['coupon'] = [
                'code' => $coupon['code'],
                'expires_at' => $coupon['expires_at'] ?? null,
                'expires_at_display' => IstDate::dayMonthOrNull($coupon['expires_at'] ?? null),
                'value_display' => $coupon['value_display'] ?? null,
                'recovery_url' => $this->urls->build($quoteId, $row->email, $row->storeId, $coupon['code']),
            ];
        }

        return BuildResult::event($this->envelope->wrap(
            $row,
            '$checkout_abandoned',
            $details,
            null,
            // A customer's checkout can carry a gift recipient's number: keep it off their profile.
            empty($snapshot['customer_id']) || $ownNumber ? $this->mapper->fields($snapshot) : array_diff_key($this->mapper->fields($snapshot), ['phone' => 1]),
            SnapshotFields::cart($details['cart']['items'], $subtotal, $this->urls->build($quoteId, $row->email, $row->storeId))
                + SnapshotFields::cartCoupon($details['coupon'] ?? null)
            + $this->ladder->cartFields($row->storeId, $subtotal, (float)($snapshot['discount_amount'] ?? 0))
        ));
    }
}
