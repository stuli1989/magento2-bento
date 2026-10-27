<?php
/**
 * $cart_abandoned — identified cart idle past the delay.
 *
 * coupon_eligible tells Bento whether the LAST recovery email may show the coupon (D6).
 * recovery_url never auto-applies a coupon. Eligible carts also get
 * coupon{code, expires_at, expires_at_display, recovery_url}; coupon.recovery_url auto-applies the
 * code and is used only by that last email — so earlier emails can't leak the discount.
 *
 * Also writes the cart snapshot + coupon fields onto the subscriber (SnapshotFields) for the templates.
 *
 * details.phone (v2.3.0, WhatsApp layer): quote billing then shipping telephone, else the customer's
 * default billing telephone; E.164 or null.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoEvents\Model\Coupon\CouponGate;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Pricing\Ladder;

class CartAbandonedBuilder implements BuilderInterface
{
    public function __construct(
        private readonly QuoteSnapshotLoader $quotes,
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
        $quote = $this->quotes->load($row->entityId);
        if ($quote === null) {
            return BuildResult::skip('quote ' . $row->entityId . ' not found');
        }
        if (!empty($quote['has_order'])) {
            return BuildResult::skip('converted to an order');
        }
        if (empty($quote['is_active']) || (int)$quote['items_count'] < 1) {
            return BuildResult::skip('cart no longer active or empty');
        }

        if (empty($quote['phone']) && !empty($quote['customer_id'])) {
            $quote['phone'] = $this->customers->defaultPhone((int)$quote['customer_id']);
        }

        $details = $this->mapper->details($quote);
        $details['phone'] = $details['customer']['phone'];
        $details['recovery_url'] = $this->urls->build($row->entityId, $row->email, $row->storeId);

        $decision = $this->couponGate->evaluate((float)$quote['subtotal'], $row->entityId, $row->storeId);
        $details['coupon_eligible'] = $decision['eligible'] && $decision['coupon'] !== null;
        if ($details['coupon_eligible']) {
            $coupon = $decision['coupon'];
            $details['coupon'] = [
                'code' => $coupon['code'],
                'expires_at' => $coupon['expires_at'] ?? null,
                'expires_at_display' => IstDate::dayMonthOrNull($coupon['expires_at'] ?? null),
                'value_display' => $coupon['value_display'] ?? null,
                'recovery_url' => $this->urls->build($row->entityId, $row->email, $row->storeId, $coupon['code']),
            ];
        }

        $snapshot = SnapshotFields::cart($details['cart']['items'], (float)$quote['subtotal'], $details['recovery_url'])
            + SnapshotFields::cartCoupon($details['coupon'] ?? null)
            + $this->ladder->cartFields($row->storeId, (float)$quote['subtotal'], (float)($quote['discount_amount'] ?? 0));

        return BuildResult::event($this->envelope->wrap(
            $row,
            '$cart_abandoned',
            $details,
            null,
            $this->mapper->fields($quote),
            $snapshot
        ));
    }
}
