<?php
/**
 * Coupon gating for $cart_abandoned and $checkout_abandoned: enabled AND cart subtotal ≥ threshold →
 * issue (or reuse) the quote's unique single-use code. One code per quote, whichever event asks first.
 * Small carts never get a coupon row. No coupon either while a sale would make it invalid (SaleGuard).
 * The coupon carries value_display ("₹200" / "10%") from the same config the managed rule is synced from.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Coupon;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\Money;
use ArtLounge\BentoEvents\Service\CouponService;

class CouponGate
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly CouponService $issuer,
        private readonly SaleGuard $sales
    ) {
    }

    /** @return array{eligible:bool, coupon:?array{code:string, expires_at:?string, value_display:string}} */
    public function evaluate(float $subtotal, int $quoteId, int $storeId): array
    {
        if (!$this->config->isCouponEnabled($storeId) || $subtotal < $this->config->getCouponThreshold($storeId)
            || $this->sales->blockingRuleId($storeId) !== null) {
            return ['eligible' => false, 'coupon' => null];
        }
        $coupon = $this->issuer->generateForCart($quoteId, $storeId);

        return $coupon === null
            ? ['eligible' => false, 'coupon' => null]
            : ['eligible' => true, 'coupon' => $coupon + ['value_display' => $this->valueDisplay($storeId)]];
    }

    /** "₹200" (cart_fixed) or "10%" (by_percent). */
    public function valueDisplay(int $storeId): string
    {
        $amount = $this->config->getCouponDiscountAmount($storeId);
        $whole = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');

        return $this->config->getCouponDiscountType($storeId) === 'cart_fixed'
            ? Money::displayWhole((int)round($amount)) : $whole . '%';
    }
}
