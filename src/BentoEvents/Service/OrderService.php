<?php
/**
 * Order Service
 *
 * Provides order data for Bento events.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class OrderService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Get order data for async event (by entity ID)
     *
     * Used by bento.order.cancelled and other events where entity_id is available.
     *
     * @param int $id Order entity ID
     * @return array
     */
    public function getOrderData(int $id): array
    {
        try {
            $order = $this->orderRepository->get($id);
            return $this->formatOrderData($order);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get order data for Bento', [
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get order data by increment_id
     *
     * Used by bento.order.placed because the observer fires before entity_id
     * is assigned (sales_order_place_after fires pre-save in some flows).
     *
     * @param string $increment_id Order increment ID
     * @return array
     */
    public function getOrderDataByIncrementId(string $increment_id): array
    {
        // Retry with delay: sales_order_place_after fires before the DB
        // transaction commits, so the order may not be queryable yet when
        // the queue consumer picks up the message.
        $maxRetries = 3;
        $delaySeconds = 5;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter('increment_id', $increment_id)
                    ->setPageSize(1)
                    ->create();

                $orders = $this->orderRepository->getList($searchCriteria)->getItems();
                $order = reset($orders);

                if (!$order) {
                    if ($attempt < $maxRetries) {
                        $this->logger->info('Order not yet in DB, retrying', [
                            'increment_id' => $increment_id,
                            'attempt' => $attempt,
                            'delay_seconds' => $delaySeconds
                        ]);
                        sleep($delaySeconds);
                        continue;
                    }
                    throw new \Magento\Framework\Exception\NoSuchEntityException(
                        __('Order with increment_id "%1" not found after %2 attempts.', $increment_id, $maxRetries)
                    );
                }

                if ($attempt > 1) {
                    $this->logger->info('Order found after retry', [
                        'increment_id' => $increment_id,
                        'attempt' => $attempt
                    ]);
                }

                return $this->formatOrderData($order);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                if ($attempt >= $maxRetries) {
                    $this->logger->error('Failed to get order data for Bento', [
                        'increment_id' => $increment_id,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to get order data for Bento', [
                    'increment_id' => $increment_id,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        // Should not reach here, but satisfy static analysis
        throw new \Magento\Framework\Exception\NoSuchEntityException(
            __('Order with increment_id "%1" not found.', $increment_id)
        );
    }

    /**
     * Format order data for Bento
     *
     * @param OrderInterface $order
     * @return array
     */
    public function formatOrderData(OrderInterface $order): array
    {
        $storeId = (int)$order->getStoreId();
        $multiplier = $this->config->getCurrencyMultiplier($storeId);
        $includeTax = $this->config->includeTaxInTotals($storeId);

        $grandTotal = $includeTax
            ? (float)$order->getGrandTotal()
            : (float)$order->getGrandTotal() - (float)$order->getTaxAmount();

        $items = $this->formatOrderItems($order, $storeId);
        $categories = $this->extractCategories($items);

        return [
            'event_type' => '$purchase',

            'order' => [
                'id' => (int)$order->getEntityId(),
                'increment_id' => $order->getIncrementId(), // Raw value for dedupe key
                'increment_id_display' => '#' . $order->getIncrementId(), // Display-friendly
                'quote_id' => (int)$order->getQuoteId(), // Links to cart lifecycle (cart_id)
                'created_at' => $order->getCreatedAt(),
                'status' => $order->getStatus(),
                'state' => $order->getState()
            ],

            'financials' => [
                'total_value' => (int)round($grandTotal * $multiplier),
                'subtotal' => (int)round((float)$order->getSubtotal() * $multiplier),
                'shipping_amount' => (int)round((float)$order->getShippingAmount() * $multiplier),
                'discount_amount' => (int)round(abs((float)$order->getDiscountAmount()) * $multiplier),
                'tax_amount' => (int)round((float)$order->getTaxAmount() * $multiplier),
                'currency_code' => $order->getOrderCurrencyCode()
            ],

            'flags' => [
                'discounted' => (float)$order->getDiscountAmount() < 0,
                'is_virtual' => (bool)$order->getIsVirtual(),
                'is_guest' => !$order->getCustomerId()
            ],

            'items' => $items,

            'summary' => [
                'ordered_products' => array_column($items, 'name'),
                'ordered_product_count' => count($items),
                'product_categories' => $categories
            ],

            'customer' => [
                'email' => $order->getCustomerEmail(),
                'firstname' => $order->getCustomerFirstname(),
                'lastname' => $order->getCustomerLastname(),
                'customer_id' => $order->getCustomerId(),
                'customer_group' => $this->getCustomerGroupName((int)$order->getCustomerGroupId())
            ],

            'addresses' => [
                'billing' => $this->formatAddress($order->getBillingAddress()),
                'shipping' => $order->getShippingAddress()
                    ? $this->formatAddress($order->getShippingAddress())
                    : null
            ],

            'store' => [
                'store_id' => $storeId,
                'store_code' => $order->getStore()->getCode(),
                'website_id' => (int)$order->getStore()->getWebsiteId()
            ],

            'payment' => [
                'method' => $order->getPayment()?->getMethod(),
                'method_title' => $order->getPayment()?->getMethodInstance()?->getTitle()
            ],

            'shipping' => [
                'method' => $order->getShippingMethod(),
                'method_title' => $order->getShippingDescription()
            ]
        ];
    }

    /**
     * Format order items
     */
    private function formatOrderItems(OrderInterface $order, int $storeId): array
    {
        $items = [];
        $multiplier = $this->config->getCurrencyMultiplier($storeId);
        $includeImages = $this->config->includeProductImages($storeId);
        $includeCategories = $this->config->includeCategories($storeId);
        $includeBrand = $this->config->includeBrand($storeId);

        // Batch pre-load all products to avoid N+1 queries
        $productCache = $this->preloadProducts($order->getItems(), $storeId);

        foreach ($order->getItems() as $item) {
            // Skip child items (configurable children, bundle children)
            if ($item->getParentItemId()) {
                continue;
            }

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
                'quantity' => (int)$item->getQtyOrdered(),
                // Shorthand aliases for Bento Liquid templates / custom usage
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (int)$item->getQtyOrdered(),
                'price' => $unitPrice,
                'row_total' => (int)round((float)$item->getRowTotal() * $multiplier),
                'product_url' => $product ? $product->getProductUrl() : null
            ];

            if ($includeImages && $product) {
                $itemData['product_image_url'] = $this->getProductImageUrl($product, $storeId);
            }

            if ($includeCategories && $product) {
                $itemData['categories'] = $this->getProductCategories($product, $storeId);
            }

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
     * Pre-load all products for a set of order items in one pass
     *
     * @param iterable $items Order items
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
     * Get product categories
     */
    private function getProductCategories($product, int $storeId): array
    {
        try {
            $categoryIds = $product->getCategoryIds();
            $categories = [];

            foreach ($categoryIds as $categoryId) {
                try {
                    $category = $this->categoryRepository->get($categoryId, $storeId);
                    $name = $category->getName();
                    if ($name === 'Default Category') {
                        continue;
                    }
                    $categories[] = $name;
                } catch (\Exception $e) {
                    continue;
                }
            }

            return $categories;
        } catch (\Exception $e) {
            return [];
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
     * Extract unique categories from items
     */
    private function extractCategories(array $items): array
    {
        $categories = [];
        foreach ($items as $item) {
            if (isset($item['categories']) && is_array($item['categories'])) {
                $categories = array_merge($categories, $item['categories']);
            }
        }
        return array_unique($categories);
    }

    /**
     * Format address data
     */
    private function formatAddress($address): ?array
    {
        if (!$address) {
            return null;
        }

        return [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone()
        ];
    }

    /**
     * Get customer group name
     */
    private function getCustomerGroupName(int $groupId): string
    {
        if ($groupId === 0) {
            return 'NOT LOGGED IN';
        }

        try {
            return $this->groupRepository->getById($groupId)->getCode();
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }
}
