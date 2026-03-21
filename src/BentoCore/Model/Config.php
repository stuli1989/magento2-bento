<?php
/**
 * Configuration Model
 *
 * Provides access to all Bento integration configuration values.
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoCore
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Config implements ConfigInterface
{
    private const XML_PATH_PREFIX = 'artlounge_bento/';

    // General paths
    private const XML_PATH_ENABLED = 'general/enabled';
    private const XML_PATH_SITE_UUID = 'general/site_uuid';
    private const XML_PATH_PUBLISHABLE_KEY = 'general/publishable_key';
    private const XML_PATH_SECRET_KEY = 'general/secret_key';
    private const XML_PATH_SOURCE = 'general/source';
    private const XML_PATH_DEBUG = 'general/debug';

    // Order paths
    private const XML_PATH_ORDERS_ENABLED = 'orders/enabled';
    private const XML_PATH_TRACK_PLACED = 'orders/track_placed';
    private const XML_PATH_TRACK_SHIPPED = 'orders/track_shipped';
    private const XML_PATH_TRACK_CANCELLED = 'orders/track_cancelled';
    private const XML_PATH_TRACK_REFUNDED = 'orders/track_refunded';
    private const XML_PATH_INCLUDE_TAX = 'orders/include_tax';
    private const XML_PATH_CURRENCY_MULTIPLIER = 'orders/currency_multiplier';
    private const XML_PATH_INCLUDE_IMAGES = 'orders/include_images';
    private const XML_PATH_INCLUDE_CATEGORIES = 'orders/include_categories';

    // Customer paths
    private const XML_PATH_CUSTOMERS_ENABLED = 'customers/enabled';
    private const XML_PATH_TRACK_CUSTOMER_CREATED = 'customers/track_created';
    private const XML_PATH_TRACK_CUSTOMER_UPDATED = 'customers/track_updated';
    private const XML_PATH_DEFAULT_TAGS = 'customers/default_tags';
    private const XML_PATH_INCLUDE_ADDRESS = 'customers/include_address';

    // Newsletter paths
    private const XML_PATH_NEWSLETTER_ENABLED = 'newsletter/enabled';
    private const XML_PATH_TRACK_SUBSCRIBE = 'newsletter/track_subscribe';
    private const XML_PATH_TRACK_UNSUBSCRIBE = 'newsletter/track_unsubscribe';
    private const XML_PATH_SUBSCRIBE_TAGS = 'newsletter/subscribe_tags';

    // Abandoned cart paths
    private const XML_PATH_ABANDONED_ENABLED = 'abandoned_cart/enabled';
    private const XML_PATH_ABANDONED_DELAY = 'abandoned_cart/delay_minutes';
    private const XML_PATH_ABANDONED_MIN_VALUE = 'abandoned_cart/min_value';
    private const XML_PATH_ABANDONED_REQUIRE_EMAIL = 'abandoned_cart/require_email';
    private const XML_PATH_ABANDONED_EXCLUDE_GROUPS = 'abandoned_cart/exclude_groups';
    private const XML_PATH_ABANDONED_PROCESSING = 'abandoned_cart/processing_method';
    private const XML_PATH_ABANDONED_RECOVERY_URL = 'abandoned_cart/include_recovery_url';
    private const XML_PATH_ABANDONED_PREVENT_DUPLICATES = 'abandoned_cart/prevent_duplicates';
    private const XML_PATH_ABANDONED_DUPLICATE_WINDOW = 'abandoned_cart/duplicate_window';

    // Coupon paths
    private const XML_PATH_COUPON_ENABLED = 'abandoned_cart/coupon_enabled';
    private const XML_PATH_COUPON_RULE_ID = 'abandoned_cart/coupon_rule_id';
    private const XML_PATH_COUPON_PREFIX = 'abandoned_cart/coupon_prefix';
    private const XML_PATH_COUPON_LIFETIME = 'abandoned_cart/coupon_lifetime_days';

    // Tracking paths
    private const XML_PATH_TRACKING_ENABLED = 'tracking/enabled';
    private const XML_PATH_TRACK_VIEWS = 'tracking/track_views';
    private const XML_PATH_TRACK_ADD_TO_CART = 'tracking/track_add_to_cart';
    private const XML_PATH_TRACK_CHECKOUT = 'tracking/track_checkout';
    private const XML_PATH_ADD_TO_CART_SELECTOR = 'tracking/add_to_cart_selector';
    private const XML_PATH_INCLUDE_BRAND = 'tracking/include_brand';
    private const XML_PATH_BRAND_ATTRIBUTE = 'tracking/brand_attribute';

    // Advanced paths
    private const XML_PATH_MAX_RETRIES = 'advanced/max_retries';
    private const XML_PATH_LOG_RETENTION = 'advanced/log_retention';
    private const XML_PATH_ELASTICSEARCH = 'advanced/elasticsearch';
    private const XML_PATH_TIMEOUT = 'advanced/timeout';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    // ===================
    // General Settings
    // ===================

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_ENABLED, $storeId);
    }

    public function getSiteUuid(?int $storeId = null): ?string
    {
        return $this->getValue(self::XML_PATH_SITE_UUID, $storeId);
    }

    public function getPublishableKey(?int $storeId = null): ?string
    {
        return $this->getValue(self::XML_PATH_PUBLISHABLE_KEY, $storeId);
    }

    public function getSecretKey(?int $storeId = null): ?string
    {
        return $this->getValue(self::XML_PATH_SECRET_KEY, $storeId);
    }

    public function getSource(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_SOURCE, $storeId) ?: 'artlounge';
    }

    public function isDebugEnabled(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_DEBUG, $storeId);
    }

    // ===================
    // Order Settings
    // ===================

    public function isOrderEventsEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->getFlag(self::XML_PATH_ORDERS_ENABLED, $storeId);
    }

    public function isTrackOrderPlacedEnabled(?int $storeId = null): bool
    {
        return $this->isOrderEventsEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_PLACED, $storeId);
    }

    public function isTrackOrderShippedEnabled(?int $storeId = null): bool
    {
        return $this->isOrderEventsEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_SHIPPED, $storeId);
    }

    public function isTrackOrderCancelledEnabled(?int $storeId = null): bool
    {
        return $this->isOrderEventsEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_CANCELLED, $storeId);
    }

    public function isTrackOrderRefundedEnabled(?int $storeId = null): bool
    {
        return $this->isOrderEventsEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_REFUNDED, $storeId);
    }

    public function includeTaxInTotals(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_INCLUDE_TAX, $storeId);
    }

    public function getCurrencyMultiplier(?int $storeId = null): int
    {
        return (int)($this->getValue(self::XML_PATH_CURRENCY_MULTIPLIER, $storeId) ?: 100);
    }

    public function includeProductImages(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_INCLUDE_IMAGES, $storeId);
    }

    public function includeCategories(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_INCLUDE_CATEGORIES, $storeId);
    }

    // ===================
    // Customer Settings
    // ===================

    public function isCustomerEventsEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->getFlag(self::XML_PATH_CUSTOMERS_ENABLED, $storeId);
    }

    public function isTrackCustomerCreatedEnabled(?int $storeId = null): bool
    {
        return $this->isCustomerEventsEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_CUSTOMER_CREATED, $storeId);
    }

    public function isTrackCustomerUpdatedEnabled(?int $storeId = null): bool
    {
        return $this->isCustomerEventsEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_CUSTOMER_UPDATED, $storeId);
    }

    public function getDefaultTags(?int $storeId = null): array
    {
        $tags = $this->getValue(self::XML_PATH_DEFAULT_TAGS, $storeId);
        if (!$tags) {
            return [];
        }
        return array_map('trim', explode(',', $tags));
    }

    public function includeCustomerAddress(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_INCLUDE_ADDRESS, $storeId);
    }

    // ===================
    // Newsletter Settings
    // ===================

    public function isNewsletterEventsEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->getFlag(self::XML_PATH_NEWSLETTER_ENABLED, $storeId);
    }

    public function isTrackSubscribeEnabled(?int $storeId = null): bool
    {
        return $this->isNewsletterEventsEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_SUBSCRIBE, $storeId);
    }

    public function isTrackUnsubscribeEnabled(?int $storeId = null): bool
    {
        return $this->isNewsletterEventsEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_UNSUBSCRIBE, $storeId);
    }

    public function getSubscribeTags(?int $storeId = null): array
    {
        $tags = $this->getValue(self::XML_PATH_SUBSCRIBE_TAGS, $storeId);
        if (!$tags) {
            return [];
        }
        return array_map('trim', explode(',', $tags));
    }

    // ===================
    // Abandoned Cart Settings
    // ===================

    public function isAbandonedCartEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->getFlag(self::XML_PATH_ABANDONED_ENABLED, $storeId);
    }

    public function getAbandonedCartDelay(?int $storeId = null): int
    {
        return (int)($this->getValue(self::XML_PATH_ABANDONED_DELAY, $storeId) ?: 60);
    }

    public function getAbandonedCartMinValue(?int $storeId = null): float
    {
        return (float)($this->getValue(self::XML_PATH_ABANDONED_MIN_VALUE, $storeId) ?: 0);
    }

    public function isAbandonedCartEmailRequired(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_ABANDONED_REQUIRE_EMAIL, $storeId);
    }

    public function getExcludedCustomerGroups(?int $storeId = null): array
    {
        $groups = $this->getValue(self::XML_PATH_ABANDONED_EXCLUDE_GROUPS, $storeId);
        if (!$groups) {
            return [];
        }
        return array_map('intval', explode(',', $groups));
    }

    public function getAbandonedCartProcessingMethod(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_ABANDONED_PROCESSING, $storeId) ?: 'cron';
    }

    public function includeRecoveryUrl(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_ABANDONED_RECOVERY_URL, $storeId);
    }

    public function preventDuplicates(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_ABANDONED_PREVENT_DUPLICATES, $storeId);
    }

    public function getAbandonedCartDuplicateWindow(?int $storeId = null): int
    {
        return (int)($this->getValue(self::XML_PATH_ABANDONED_DUPLICATE_WINDOW, $storeId) ?: 24);
    }

    // ===================
    // Coupon Settings
    // ===================

    public function isCouponEnabled(?int $storeId = null): bool
    {
        return $this->isAbandonedCartEnabled($storeId)
            && $this->getFlag(self::XML_PATH_COUPON_ENABLED, $storeId);
    }

    public function getCouponRuleId(?int $storeId = null): ?int
    {
        $value = $this->getValue(self::XML_PATH_COUPON_RULE_ID, $storeId);
        return $value ? (int)$value : null;
    }

    public function getCouponPrefix(?int $storeId = null): string
    {
        $prefix = $this->getValue(self::XML_PATH_COUPON_PREFIX, $storeId);
        // Strip non-alphanumeric characters for URL safety
        return $prefix ? preg_replace('/[^A-Za-z0-9]/', '', $prefix) : 'BENTO';
    }

    public function getCouponLifetimeDays(?int $storeId = null): int
    {
        return (int)($this->getValue(self::XML_PATH_COUPON_LIFETIME, $storeId) ?: 7);
    }

    // ===================
    // Tracking Settings
    // ===================

    public function isTrackingEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACKING_ENABLED, $storeId);
    }

    public function isTrackViewsEnabled(?int $storeId = null): bool
    {
        return $this->isTrackingEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_VIEWS, $storeId);
    }

    public function isTrackAddToCartEnabled(?int $storeId = null): bool
    {
        return $this->isTrackingEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_ADD_TO_CART, $storeId);
    }

    public function isTrackCheckoutEnabled(?int $storeId = null): bool
    {
        return $this->isTrackingEnabled($storeId) && $this->getFlag(self::XML_PATH_TRACK_CHECKOUT, $storeId);
    }

    public function getAddToCartSelector(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_ADD_TO_CART_SELECTOR, $storeId) ?: '#product-addtocart-button';
    }

    public function includeBrand(?int $storeId = null): bool
    {
        return $this->getFlag(self::XML_PATH_INCLUDE_BRAND, $storeId);
    }

    public function getBrandAttribute(?int $storeId = null): string
    {
        return $this->getValue(self::XML_PATH_BRAND_ATTRIBUTE, $storeId) ?: 'manufacturer';
    }

    // ===================
    // Advanced Settings
    // ===================

    public function getMaxRetries(): int
    {
        return (int)($this->getValue(self::XML_PATH_MAX_RETRIES) ?: 5);
    }

    public function getLogRetention(): int
    {
        return (int)($this->getValue(self::XML_PATH_LOG_RETENTION) ?: 30);
    }

    public function isElasticsearchEnabled(): bool
    {
        return $this->getFlag(self::XML_PATH_ELASTICSEARCH);
    }

    public function getTimeout(): int
    {
        return (int)($this->getValue(self::XML_PATH_TIMEOUT) ?: 30);
    }

    // ===================
    // Helper Methods
    // ===================

    /**
     * Get configuration value
     */
    private function getValue(string $path, ?int $storeId = null): ?string
    {
        $fullPath = self::XML_PATH_PREFIX . $path;

        if ($storeId !== null) {
            return $this->scopeConfig->getValue($fullPath, ScopeInterface::SCOPE_STORE, $storeId);
        }

        return $this->scopeConfig->getValue($fullPath);
    }

    /**
     * Get configuration flag
     */
    private function getFlag(string $path, ?int $storeId = null): bool
    {
        $fullPath = self::XML_PATH_PREFIX . $path;

        if ($storeId !== null) {
            return $this->scopeConfig->isSetFlag($fullPath, ScopeInterface::SCOPE_STORE, $storeId);
        }

        return $this->scopeConfig->isSetFlag($fullPath);
    }

    /**
     * Log debug message if debug mode is enabled
     */
    public function debug(string $message, array $context = [], ?int $storeId = null): void
    {
        if ($this->isDebugEnabled($storeId)) {
            $this->logger->debug('[Bento] ' . $message, $context);
        }
    }
}
