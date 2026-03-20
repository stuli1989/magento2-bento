<?php
/**
 * Configuration Interface
 *
 * Provides access to all Bento integration configuration values.
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoCore
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Api;

interface ConfigInterface
{
    // General Settings
    public function isEnabled(?int $storeId = null): bool;
    public function getSiteUuid(?int $storeId = null): ?string;
    public function getPublishableKey(?int $storeId = null): ?string;
    public function getSecretKey(?int $storeId = null): ?string;
    public function getSource(?int $storeId = null): string;
    public function isDebugEnabled(?int $storeId = null): bool;

    // Order Settings
    public function isOrderEventsEnabled(?int $storeId = null): bool;
    public function isTrackOrderPlacedEnabled(?int $storeId = null): bool;
    public function isTrackOrderShippedEnabled(?int $storeId = null): bool;
    public function isTrackOrderCancelledEnabled(?int $storeId = null): bool;
    public function isTrackOrderRefundedEnabled(?int $storeId = null): bool;
    public function includeTaxInTotals(?int $storeId = null): bool;
    public function getCurrencyMultiplier(?int $storeId = null): int;
    public function includeProductImages(?int $storeId = null): bool;
    public function includeCategories(?int $storeId = null): bool;

    // Customer Settings
    public function isCustomerEventsEnabled(?int $storeId = null): bool;
    public function isTrackCustomerCreatedEnabled(?int $storeId = null): bool;
    public function isTrackCustomerUpdatedEnabled(?int $storeId = null): bool;
    public function getDefaultTags(?int $storeId = null): array;
    public function includeCustomerAddress(?int $storeId = null): bool;

    // Newsletter Settings
    public function isNewsletterEventsEnabled(?int $storeId = null): bool;
    public function isTrackSubscribeEnabled(?int $storeId = null): bool;
    public function isTrackUnsubscribeEnabled(?int $storeId = null): bool;
    public function getSubscribeTags(?int $storeId = null): array;

    // Abandoned Cart Settings
    public function isAbandonedCartEnabled(?int $storeId = null): bool;
    public function getAbandonedCartDelay(?int $storeId = null): int;
    public function getAbandonedCartMinValue(?int $storeId = null): float;
    public function isAbandonedCartEmailRequired(?int $storeId = null): bool;
    public function getExcludedCustomerGroups(?int $storeId = null): array;
    public function getAbandonedCartProcessingMethod(?int $storeId = null): string;
    public function includeRecoveryUrl(?int $storeId = null): bool;
    public function preventDuplicates(?int $storeId = null): bool;
    public function getAbandonedCartDuplicateWindow(?int $storeId = null): int;

    // Tracking Settings
    public function isTrackingEnabled(?int $storeId = null): bool;
    public function isTrackViewsEnabled(?int $storeId = null): bool;
    public function isTrackAddToCartEnabled(?int $storeId = null): bool;
    public function isTrackCheckoutEnabled(?int $storeId = null): bool;
    public function getAddToCartSelector(?int $storeId = null): string;
    public function includeBrand(?int $storeId = null): bool;
    public function getBrandAttribute(?int $storeId = null): string;

    // Advanced Settings
    public function getMaxRetries(): int;
    public function getLogRetention(): int;
    public function isElasticsearchEnabled(): bool;
    public function getTimeout(): int;

    // Debug
    public function debug(string $message, array $context = [], ?int $storeId = null): void;
}
