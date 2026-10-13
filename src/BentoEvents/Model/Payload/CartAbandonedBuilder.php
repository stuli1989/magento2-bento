<?php
/**
 * $cart_abandoned — identified cart idle past the delay.
 *
 * coupon_eligible tells Bento whether the LAST recovery email may show the coupon (D6).
 * recovery_url never auto-applies a coupon; recovery_url_with_coupon (eligible carts only)
 * is for that last email — so earlier emails can't leak the discount.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\Coupon\CouponGate;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;

class CartAbandonedBuilder implements BuilderInterface
{
    public function __construct(
        private readonly QuoteSnapshotLoader $quotes,
        private readonly QuotePayloadMapper $mapper,
        private readonly RecoveryUrlBuilder $urls,
        private readonly CouponGate $couponGate,
        private readonly EventEnvelope $envelope
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

        $details = $this->mapper->details($quote);
        $details['recovery_url'] = $this->urls->build($row->entityId, $row->email, $row->storeId);

        $decision = $this->couponGate->evaluate((float)$quote['subtotal'], $row->entityId, $row->storeId);
        $details['coupon_eligible'] = $decision['eligible'] && $decision['coupon'] !== null;
        if ($details['coupon_eligible']) {
            $details['coupon'] = $decision['coupon'];
            $details['recovery_url_with_coupon'] = $this->urls->build(
                $row->entityId,
                $row->email,
                $row->storeId,
                $decision['coupon']['code']
            );
        }

        return BuildResult::event($this->envelope->wrap(
            $row,
            '$cart_abandoned',
            $details,
            null,
            $this->mapper->fields($quote)
        ));
    }
}
