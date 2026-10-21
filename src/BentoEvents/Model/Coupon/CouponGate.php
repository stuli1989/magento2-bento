<?php
/**
 * Coupon gating for $cart_abandoned and $checkout_abandoned: enabled AND cart subtotal ≥ threshold →
 * issue (or reuse) the quote's unique single-use code. One code per quote, whichever event asks first.
 * Small carts never get a coupon row.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Coupon;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Service\CouponService;

class CouponGate
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly CouponService $issuer
    ) {
    }

    /** @return array{eligible:bool, coupon:?array{code:string, expires_at:?string}} */
    public function evaluate(float $subtotal, int $quoteId, int $storeId): array
    {
        if (!$this->config->isCouponEnabled($storeId) || $subtotal < $this->config->getCouponThreshold($storeId)) {
            return ['eligible' => false, 'coupon' => null];
        }
        $coupon = $this->issuer->generateForCart($quoteId, $storeId);

        return $coupon === null
            ? ['eligible' => false, 'coupon' => null]
            : ['eligible' => true, 'coupon' => $coupon];
    }
}
