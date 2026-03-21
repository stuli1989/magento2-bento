<?php
/**
 * Abandoned Cart Service
 *
 * Provides abandoned cart data for Bento events.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\RecoveryToken;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use ArtLounge\BentoEvents\Service\CouponService;

class AbandonedCartService
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConfigInterface $config,
        private readonly RecoveryToken $recoveryToken,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
        private readonly CouponService $couponService
    ) {
    }

    /**
     * Get abandoned cart data for async event
     *
     * @param int $id Quote ID
     * @return array
     */
    public function getAbandonedCartData(int $id): array
    {
        try {
            $quote = $this->cartRepository->get($id);
            return $this->formatAbandonedCartData($quote);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get abandoned cart data for Bento', [
                'quote_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Format abandoned cart data for Bento
     *
     * @param CartInterface $quote
     * @return array
     */
    public function formatAbandonedCartData(CartInterface $quote): array
    {
        $storeId = (int)$quote->getStoreId();
        $multiplier = $this->config->getCurrencyMultiplier($storeId);

        $items = $this->formatCartItems($quote, $storeId);
        $abandonedDuration = $this->calculateAbandonedDuration($quote->getUpdatedAt());

        $quoteId = (int)$quote->getId();

        // Generate coupon if enabled (called once, result reused for payload + URL)
        $couponData = $this->couponService->generateForCart($quoteId, $storeId);

        $data = [
            'event_type' => '$cart_abandoned',
            'cart_id' => $quoteId, // Top-level for Bento flow matching

            'cart' => [
                'quote_id' => $quoteId,
                'cart_id' => $quoteId, // Duplicate for nested access in Bento templates
                'created_at' => $quote->getCreatedAt(),
                'updated_at' => $quote->getUpdatedAt(),
                'abandoned_duration_minutes' => $abandonedDuration
            ],

            'financials' => [
                'total_value' => (int)round((float)$quote->getGrandTotal() * $multiplier),
                'subtotal' => (int)round((float)$quote->getSubtotal() * $multiplier),
                'discount_amount' => (int)round(abs((float)$quote->getSubtotal() - (float)$quote->getSubtotalWithDiscount()) * $multiplier),
                'currency_code' => $quote->getQuoteCurrencyCode()
            ],

            'items' => $items,

            'customer' => [
                'email' => $quote->getCustomerEmail(),
                'firstname' => $quote->getCustomerFirstname(),
                'lastname' => $quote->getCustomerLastname(),
                'is_guest' => $quote->getCustomerIsGuest()
            ]
        ];

        // Include recovery URL if configured
        if ($this->config->includeRecoveryUrl($storeId)) {
            $recoveryUrl = $this->generateRecoveryUrl($quote, $couponData['code'] ?? null);
            if ($recoveryUrl !== null) {
                // Canonical payload key consumed by BentoClient
                $data['recovery_url'] = $recoveryUrl;
                // Backward-compatible nested key for any existing internal consumers
                $data['recovery'] = [
                    'cart_url' => $recoveryUrl
                ];
            }
        }

        // Include coupon data in payload
        $data['coupon'] = [
            'code' => $couponData['code'] ?? null,
            'expires_at' => $couponData['expires_at'] ?? null,
        ];

        return $data;
    }

    /**
     * Format cart items
     */
    private function formatCartItems(CartInterface $quote, int $storeId): array
    {
        $items = [];
        $multiplier = $this->config->getCurrencyMultiplier($storeId);
        $includeBrand = $this->config->includeBrand($storeId);

        // Batch pre-load all products to avoid N+1 queries
        $productCache = $this->preloadProducts($quote->getAllVisibleItems(), $storeId);

        foreach ($quote->getAllVisibleItems() as $item) {
            $productId = (int)$item->getProductId();
            $product = $productCache[$productId] ?? null;

            $unitPrice = (int)round((float)$item->getPrice() * $multiplier);
            $itemData = [
                'item_id' => (int)$item->getItemId(),
                'product_id' => (string)$productId,
                // Canonical Bento Sales tab keys (matches Node SDK PurchaseItem type)
                'product_sku' => $item->getSku(),
                'product_name' => $item->getName(),
                'product_price' => $unitPrice,
                'product_permalink' => $product ? $product->getProductUrl() : null,
                'quantity' => (int)$item->getQty(),
                // Shorthand aliases for Bento Liquid templates / custom usage
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (int)$item->getQty(),
                'price' => $unitPrice,
                'row_total' => (int)round((float)$item->getRowTotal() * $multiplier),
                'product_url' => $product ? $product->getProductUrl() : null,
                'product_image_url' => $product ? $this->getProductImageUrl($product, $storeId) : null
            ];

            if ($includeBrand && $product) {
                $brand = $this->getProductBrand($product, $storeId);
                if ($brand !== null) {
                    $itemData['brand'] = $brand;
                }
            }

            $items[] = $itemData;
        }

        return $items;
    }

    /**
     * Pre-load all products for a set of cart items in one pass
     *
     * @param iterable $items Cart items
     * @param int $storeId
     * @return array<int, mixed> Keyed by product ID
     */
    private function preloadProducts(iterable $items, int $storeId): array
    {
        $cache = [];
        foreach ($items as $item) {
            $productId = (int)$item->getProductId();
            if ($productId > 0 && !isset($cache[$productId])) {
                try {
                    $cache[$productId] = $this->productRepository->getById($productId, false, $storeId);
                } catch (\Exception $e) {
                    $cache[$productId] = null;
                }
            }
        }
        return $cache;
    }

    /**
     * Get product image URL — direct media path (no resize cache)
     */
    private function getProductImageUrl($product, int $storeId): ?string
    {
        try {
            $image = $product->getImage();
            if ($image && $image !== 'no_selection') {
                $baseUrl = $this->storeManager->getStore($storeId)->getBaseUrl(
                    \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
                );
                return $baseUrl . 'catalog/product' . $image;
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get product brand from configured brand attribute.
     */
    private function getProductBrand($product, int $storeId): ?string
    {
        try {
            $brandAttribute = $this->config->getBrandAttribute($storeId);
            $brandValue = $product->getData($brandAttribute);

            if (empty($brandValue)) {
                return null;
            }

            $brandText = null;
            if (method_exists($product, 'getAttributeText')) {
                try {
                    $brandText = $product->getAttributeText($brandAttribute);
                } catch (\Throwable $e) {
                    $brandText = null;
                }
            }

            if (is_array($brandText)) {
                $brandText = implode(', ', array_filter($brandText));
            }

            if (is_string($brandText) && trim($brandText) !== '') {
                return trim($brandText);
            }

            return is_scalar($brandValue) ? (string)$brandValue : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Calculate abandoned duration in minutes
     */
    private function calculateAbandonedDuration(?string $updatedAt): int
    {
        if (!$updatedAt) {
            return 0;
        }

        $updatedTime = strtotime($updatedAt);
        $currentTime = time();

        return (int)round(($currentTime - $updatedTime) / 60);
    }

    /**
     * Generate cart recovery URL
     *
     * Creates a URL that links to the BentoEvents recovery controller.
     * Token format: signed, expiring token generated by RecoveryToken.
     */
    private function generateRecoveryUrl(CartInterface $quote, ?string $couponCode = null): ?string
    {
        try {
            $email = (string)$quote->getCustomerEmail();
            if ($email === '') {
                return null;
            }

            $token = $this->recoveryToken->generate(
                (int)$quote->getId(),
                $email,
                (int)$quote->getStoreId()
            );

            if ($token === null) {
                return null;
            }

            $params = [
                '_scope' => $quote->getStoreId(),
                'recover' => $token,
                'autopay' => 1,
                '_nosid' => true
            ];

            if ($couponCode !== null) {
                $params['coupon'] = $couponCode;
            }

            return $this->urlBuilder->getUrl('bento/cart/recover', $params);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to generate Bento cart recovery URL', [
                'quote_id' => (int)$quote->getId(),
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}
