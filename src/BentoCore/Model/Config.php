<?php
/**
 * Configuration Model (v2)
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Config implements ConfigInterface
{
    private const PREFIX = 'artlounge_bento/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    // ---------- General ----------

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag('general/enabled', $storeId);
    }

    public function getSiteUuid(?int $storeId = null): ?string
    {
        return $this->value('general/site_uuid', $storeId);
    }

    public function getPublishableKey(?int $storeId = null): ?string
    {
        return $this->value('general/publishable_key', $storeId);
    }

    public function getSecretKey(?int $storeId = null): ?string
    {
        $raw = $this->value('general/secret_key', $storeId);
        if ($raw === null) {
            return null;
        }
        // Stored via the Encrypted backend model as "<keyVersion>:<cipherVersion>:<data>".
        if (preg_match('/^\d+:\d+:/', $raw) === 1) {
            $decrypted = (string)$this->encryptor->decrypt($raw);
            return $decrypted !== '' ? $decrypted : null;
        }

        return $raw;
    }

    public function isConfigured(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->getSiteUuid($storeId) !== null
            && $this->getPublishableKey($storeId) !== null
            && $this->getSecretKey($storeId) !== null;
    }

    public function getSource(?int $storeId = null): string
    {
        return $this->value('general/source', $storeId) ?: 'artlounge';
    }

    public function isDebugEnabled(?int $storeId = null): bool
    {
        return $this->flag('general/debug', $storeId);
    }

    public function debug(string $message, array $context = [], ?int $storeId = null): void
    {
        if ($this->isDebugEnabled($storeId)) {
            $this->logger->debug('[Bento] ' . $message, $context);
        }
    }

    public function getTestModeEmailPatterns(): array
    {
        return $this->getTestMode()->getPatterns();
    }

    public function getTestMode(): TestMode
    {
        return TestMode::fromString($this->value('general/test_mode_emails'));
    }

    public function getTimeout(): int
    {
        return (int)($this->value('advanced/timeout') ?: 10);
    }

    // ---------- Orders / payload ----------

    public function includeTaxInTotals(?int $storeId = null): bool
    {
        $v = $this->value('orders/include_tax', $storeId);
        return $v === null ? true : (bool)(int)$v;
    }

    public function getBrandAttribute(?int $storeId = null): string
    {
        return $this->value('tracking/brand_attribute', $storeId) ?: 'manufacturer';
    }

    // ---------- Browser tracking ----------

    public function isTrackingEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->flag('tracking/enabled', $storeId);
    }

    public function isTrackViewsEnabled(?int $storeId = null): bool
    {
        return $this->isTrackingEnabled($storeId) && $this->flag('tracking/track_views', $storeId);
    }

    public function isTrackCartEnabled(?int $storeId = null): bool
    {
        return $this->isTrackingEnabled($storeId) && $this->flag('tracking/track_cart', $storeId);
    }

    public function isContactCaptureEnabled(?int $storeId = null): bool
    {
        return $this->isTrackingEnabled($storeId) && $this->flag('tracking/contact_capture', $storeId);
    }

    public function includeBrand(?int $storeId = null): bool
    {
        return $this->flag('tracking/include_brand', $storeId);
    }

    // ---------- Reviews ----------

    public function isReviewEventsEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->flag('reviews/enabled', $storeId);
    }

    // ---------- Abandonment ----------

    public function isAbandonedCartEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->flag('abandoned_cart/enabled', $storeId);
    }

    public function getCartDelayMinutes(?int $storeId = null): int
    {
        return max(1, (int)($this->value('abandoned_cart/delay_minutes', $storeId) ?: 60));
    }

    public function getCartMinValue(?int $storeId = null): float
    {
        return (float)($this->value('abandoned_cart/min_value', $storeId) ?: 0);
    }

    public function getContactCaptureDelayMinutes(?int $storeId = null): int
    {
        return max(1, (int)($this->value('abandoned_cart/contact_capture_delay_minutes', $storeId) ?: 30));
    }

    // ---------- Coupons ----------

    public function isCouponEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->flag('abandoned_cart/coupon_enabled', $storeId);
    }

    public function getCouponThreshold(?int $storeId = null): float
    {
        return (float)($this->value('abandoned_cart/coupon_min_subtotal', $storeId) ?: 0);
    }

    public function getCouponDiscountType(?int $storeId = null): string
    {
        return $this->value('abandoned_cart/coupon_discount_type', $storeId) ?: 'by_percent';
    }

    public function getCouponDiscountAmount(?int $storeId = null): float
    {
        return (float)($this->value('abandoned_cart/coupon_discount_amount', $storeId) ?: 10);
    }

    public function getCouponLifetimeDays(?int $storeId = null): int
    {
        return max(1, (int)($this->value('abandoned_cart/coupon_lifetime_days', $storeId) ?: 7));
    }

    public function getCouponPrefix(?int $storeId = null): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string)$this->value('abandoned_cart/coupon_prefix', $storeId));
        return $prefix !== '' ? $prefix : 'BENTO';
    }

    public function getCouponManagedRuleId(?int $storeId = null): ?int
    {
        $v = $this->value('abandoned_cart/coupon_managed_rule_id', $storeId);
        return $v ? (int)$v : null;
    }

    // ---------- Profile ----------

    public function getCategoryMediumMap(): array
    {
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$this->value('profile/category_medium_map')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^(\d+)\s*=\s*([A-Za-z_][A-Za-z0-9_\-]*)$/', $line, $m) !== 1) {
                continue;
            }
            $id = (int)$m[1];
            if ($id > 0) {
                $map[$id] = strtolower($m[2]);
            }
        }

        return $map;
    }

    // ---------- helpers ----------

    private function value(string $path, ?int $storeId = null): ?string
    {
        $v = $storeId !== null
            ? $this->scopeConfig->getValue(self::PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId)
            : $this->scopeConfig->getValue(self::PREFIX . $path);

        return ($v === null || $v === '') ? null : (string)$v;
    }

    private function flag(string $path, ?int $storeId = null): bool
    {
        return $storeId !== null
            ? $this->scopeConfig->isSetFlag(self::PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId)
            : $this->scopeConfig->isSetFlag(self::PREFIX . $path);
    }
}
