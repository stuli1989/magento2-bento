<?php
/**
 * Bento configuration (Stores > Configuration > ArtLounge > Bento Integration).
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Api;

use ArtLounge\BentoCore\Model\TestMode;

interface ConfigInterface
{
    // General
    public function isEnabled(?int $storeId = null): bool;
    public function getSiteUuid(?int $storeId = null): ?string;
    public function getPublishableKey(?int $storeId = null): ?string;
    public function getSecretKey(?int $storeId = null): ?string;
    /** Enabled AND site uuid + both keys present. */
    public function isConfigured(?int $storeId = null): bool;
    public function getSource(?int $storeId = null): string;
    public function isDebugEnabled(?int $storeId = null): bool;
    public function debug(string $message, array $context = [], ?int $storeId = null): void;
    /** @return string[] glob patterns; empty = test mode off */
    public function getTestModeEmailPatterns(): array;
    public function getTestMode(): TestMode;
    public function getTimeout(): int;

    // Orders / payload
    public function includeTaxInTotals(?int $storeId = null): bool;
    public function getBrandAttribute(?int $storeId = null): string;

    // Browser tracking
    public function isTrackingEnabled(?int $storeId = null): bool;
    public function isTrackViewsEnabled(?int $storeId = null): bool;
    public function isTrackCartEnabled(?int $storeId = null): bool;
    public function isContactCaptureEnabled(?int $storeId = null): bool;
    public function includeBrand(?int $storeId = null): bool;

    // Reviews
    /** $review_submitted: master switch AND reviews/enabled. */
    public function isReviewEventsEnabled(?int $storeId = null): bool;

    // Abandonment
    public function isAbandonedCartEnabled(?int $storeId = null): bool;
    public function getCartDelayMinutes(?int $storeId = null): int;
    public function getCartMinValue(?int $storeId = null): float;
    public function getContactCaptureDelayMinutes(?int $storeId = null): int;

    // Coupons (only on $cart_abandoned)
    public function isCouponEnabled(?int $storeId = null): bool;
    /** Minimum cart subtotal (₹) for a coupon; also enforced as the managed rule's condition. */
    public function getCouponThreshold(?int $storeId = null): float;
    public function getCouponDiscountType(?int $storeId = null): string;
    public function getCouponDiscountAmount(?int $storeId = null): float;
    public function getCouponLifetimeDays(?int $storeId = null): int;
    public function getCouponPrefix(?int $storeId = null): string;
    public function getCouponManagedRuleId(?int $storeId = null): ?int;

    // Profile
    /** @return array<int,string> category id => medium slug */
    public function getCategoryMediumMap(): array;

    // Next picks
    /** @return array<string,int[]> medium slug (or "any") => add-on category ids */
    public function getPickCategoryMap(): array;
    /** @return array<string,string[]> medium slug (or "any") => up to 3 pinned SKUs */
    public function getPinnedPicks(): array;
    public function getPickSalesWindowDays(): int;
    public function getPickMinStock(): float;
    /** new_in_*: products created within this many days */
    public function getNewArrivalsWindowDays(): int;
    /** Nightly product-list refresh: buyers with a counted order in this many days (plus every VIP) */
    public function getRefreshWindowDays(): int;
    /** VIP (nightly refresh audience): lifetime_spend ≥ this (₹) OR order_count ≥ getVipOrderCount() */
    public function getVipLifetimeSpend(): float;
    public function getVipOrderCount(): int;
}
