<?php
/**
 * Tracking Data ViewModel
 *
 * Provides product and tracking data for frontend templates.
 */

declare(strict_types=1);

namespace ArtLounge\BentoTracking\ViewModel;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

class TrackingData implements ArgumentInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Registry $registry,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $json,
        private readonly CheckoutSession $checkoutSession,
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    /**
     * Check if tracking is enabled
     */
    public function isEnabled(): bool
    {
        return $this->config->isTrackingEnabled($this->getStoreId());
    }

    /**
     * Check if product view tracking is enabled
     */
    public function isProductViewEnabled(): bool
    {
        return $this->config->isTrackViewsEnabled($this->getStoreId());
    }

    /**
     * Check if add to cart tracking is enabled
     */
    public function isAddToCartEnabled(): bool
    {
        return $this->config->isTrackAddToCartEnabled($this->getStoreId());
    }

    /**
     * Check if checkout tracking is enabled
     */
    public function isCheckoutEnabled(): bool
    {
        return $this->config->isTrackCheckoutEnabled($this->getStoreId());
    }

    /**
     * Get Bento publishable key
     */
    public function getPublishableKey(): ?string
    {
        return $this->config->getPublishableKey($this->getStoreId());
    }

    /**
     * Get Bento site UUID (used in tracking script URL)
     */
    public function getSiteUuid(): ?string
    {
        return $this->config->getSiteUuid($this->getStoreId());
    }

    /**
     * Get add to cart button selector
     */
    public function getAddToCartSelector(): string
    {
        return $this->config->getAddToCartSelector($this->getStoreId());
    }

    /**
     * Get current product data as JSON
     */
    public function getProductDataJson(): string
    {
        $product = $this->getCurrentProduct();

        if (!$product) {
            return '{}';
        }

        $storeId = $this->getStoreId();
        $multiplier = $this->config->getCurrencyMultiplier($storeId);

        $data = [
            'product_id' => (int)$product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => (int)round($this->resolveProductPrice($product) * $multiplier),
            'url' => $product->getProductUrl(),
            'image_url' => $this->getProductImageUrl($product),
            'categories' => $this->getProductCategories($product),
            'in_stock' => $this->isProductInStock($product),
            'currency_code' => $this->getCurrencyCode()
        ];

        // Add brand if configured
        if ($this->config->includeBrand($storeId)) {
            $brand = $this->resolveProductBrand($product, $storeId);
            if ($brand !== null) {
                $data['brand'] = $brand;
            }
        }

        // Add special price if available (check special_price attr, then msrp as fallback)
        $specialPrice = $product->getSpecialPrice();
        $regularPrice = (float)$product->getPrice();
        $msrp = (float)$product->getData('msrp');

        if ($specialPrice && (float)$specialPrice < $regularPrice) {
            $data['special_price'] = (int)round((float)$specialPrice * $multiplier);
            $data['original_price'] = (int)round($regularPrice * $multiplier);
        } elseif ($msrp > 0 && $msrp > $regularPrice) {
            $data['special_price'] = (int)round($regularPrice * $multiplier);
            $data['original_price'] = (int)round($msrp * $multiplier);
        }

        return $this->json->serialize($data);
    }

    /**
     * Get current product from registry
     */
    public function getCurrentProduct()
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Get product image URL — direct media path (no resize cache)
     */
    private function getProductImageUrl($product): string
    {
        try {
            $image = $product->getImage();
            if ($image && $image !== 'no_selection') {
                $baseUrl = $this->storeManager->getStore()->getBaseUrl(
                    \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
                );
                return $baseUrl . 'catalog/product' . $image;
            }
            return '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Resolve product brand using configured brand attribute, falling back to
     * 'brand' or 'manufacturer' if the primary attribute is empty.
     */
    private function resolveProductBrand($product, int $storeId): ?string
    {
        $configuredAttr = $this->config->getBrandAttribute($storeId);
        $fallbackAttrs = array_unique(array_filter([$configuredAttr, 'brand', 'manufacturer']));

        foreach ($fallbackAttrs as $attrCode) {
            $result = $this->resolveAttributeText($product, $attrCode);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Resolve a single attribute to its text value.
     */
    private function resolveAttributeText($product, string $attrCode): ?string
    {
        $rawValue = $product->getData($attrCode);
        if (empty($rawValue)) {
            return null;
        }

        $text = null;
        if (method_exists($product, 'getAttributeText')) {
            try {
                $text = $product->getAttributeText($attrCode);
            } catch (\Throwable $e) {
                $text = null;
            }
        }
        if (is_array($text)) {
            $text = implode(', ', array_filter($text));
        }

        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        return is_scalar($rawValue) ? (string)$rawValue : null;
    }

    /**
     * Resolve product price, falling back to price index for configurables
     * where getFinalPrice() returns 0 (e.g. out-of-stock configurable parents).
     */
    private function resolveProductPrice($product): float
    {
        $price = (float)$product->getFinalPrice();
        if ($price > 0) {
            return $price;
        }

        // For configurables with price=0, query the price index for min_price
        if ($product->getTypeId() === 'configurable') {
            try {
                $resource = $product->getResource();
                $connection = $resource->getConnection();
                $tableName = $resource->getTable('catalog_product_index_price');
                $websiteId = (int)$this->storeManager->getStore()->getWebsiteId();
                $minPrice = (float)$connection->fetchOne(
                    "SELECT min_price FROM {$tableName} WHERE entity_id = ? AND website_id = ? AND min_price > 0",
                    [(int)$product->getId(), $websiteId]
                );
                if ($minPrice > 0) {
                    return $minPrice;
                }
            } catch (\Throwable $e) {
                // Fall through to raw price
            }
        }

        return (float)$product->getPrice();
    }

    /**
     * Load product and resolve brand safely for order payload enrichment.
     */
    private function getProductBrandById(int $productId, int $storeId): ?string
    {
        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
            return $this->resolveProductBrand($product, $storeId);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get product categories as " > " delimited string
     */
    private function getProductCategories($product): string
    {
        $categories = [];
        $categoryIds = $product->getCategoryIds();

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get($categoryId, $this->getStoreId());
                $name = $category->getName();
                if ($name !== 'Default Category') {
                    $categories[] = $name;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return implode(' > ', array_unique($categories));
    }

    /**
     * Get product categories as array (for order items)
     */
    private function getProductCategoryArray($product, int $storeId): array
    {
        $categories = [];
        try {
            $categoryIds = $product->getCategoryIds();
            foreach ($categoryIds as $categoryId) {
                try {
                    $category = $this->categoryRepository->get($categoryId, $storeId);
                    $name = $category->getName();
                    if ($name !== 'Default Category') {
                        $categories[] = $name;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
        return array_values(array_unique($categories));
    }

    /**
     * Check if product is in stock
     */
    private function isProductInStock($product): bool
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem($product->getId());
            return $stockItem->getIsInStock();
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Get current store ID
     */
    private function getStoreId(): int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get currency code for current store
     *
     * Used by templates for tracking data.
     */
    public function getCurrencyCode(): string
    {
        try {
            return $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            return 'USD';
        }
    }

    /**
     * Get currency multiplier for current store
     *
     * Used to convert currency amounts to cents for Bento.
     */
    public function getCurrencyMultiplier(): int
    {
        return $this->config->getCurrencyMultiplier($this->getStoreId());
    }

    /**
     * Check if debug logging is enabled
     */
    public function isDebugEnabled(): bool
    {
        return $this->config->isDebugEnabled($this->getStoreId());
    }

    /**
     * Check if purchase tracking on success page is enabled
     *
     * Enabled when order tracking is enabled (serves as fallback for server-side)
     */
    public function isPurchaseTrackingEnabled(): bool
    {
        return $this->config->isTrackOrderPlacedEnabled($this->getStoreId());
    }

    /**
     * Get last placed order from checkout session
     *
     * @return \Magento\Sales\Model\Order|null
     */
    public function getLastOrder()
    {
        try {
            $order = $this->checkoutSession->getLastRealOrder();
            if ($order && $order->getId()) {
                return $order;
            }
        } catch (\Exception $e) {
            // Session not available or no order
        }
        return null;
    }

    /**
     * Get last order data as JSON for purchase tracking
     *
     * Rich payload matching server-side OrderService structure.
     * Client-side $purchase wins the dedup race (fires instantly via SDK)
     * so it must carry the full data set for Bento's Sales tab, Liquid
     * templates, and flow triggers.
     *
     * NOT Varnish-cached: checkout success page is session-dependent.
     * All data comes from the already-loaded $order object (no extra queries
     * except product loads for URL/image/brand/categories).
     */
    public function getLastOrderDataJson(): string
    {
        $order = $this->getLastOrder();

        if (!$order) {
            return '{}';
        }

        $storeId = $this->getStoreId();
        $multiplier = $this->config->getCurrencyMultiplier($storeId);
        $includeTax = $this->config->includeTaxInTotals($storeId);
        $includeBrand = $this->config->includeBrand($storeId);

        $grandTotal = $includeTax
            ? (float)$order->getGrandTotal()
            : (float)$order->getGrandTotal() - (float)$order->getTaxAmount();

        // Build rich item data (matching server-side OrderService format)
        $items = [];
        $allCategories = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $productId = (int)$item->getProductId();
            $itemData = [
                'item_id' => (int)$item->getItemId(),
                'product_id' => $productId,
                // Canonical Bento Sales tab keys
                'product_sku' => $item->getSku(),
                'product_name' => $item->getName(),
                'quantity' => (int)$item->getQtyOrdered(),
                // Short aliases for Liquid templates
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (int)$item->getQtyOrdered(),
                'price' => (int)round((float)$item->getPrice() * $multiplier),
                'row_total' => (int)round((float)$item->getRowTotal() * $multiplier)
            ];

            // Load product for URL, image, brand, categories
            try {
                $product = $this->productRepository->getById($productId, false, $storeId);
                $itemData['url'] = $product->getProductUrl();
                $itemData['product_url'] = $product->getProductUrl();
                $itemData['image_url'] = $this->getProductImageUrl($product);
                $itemData['product_image_url'] = $this->getProductImageUrl($product);

                if ($includeBrand) {
                    $brand = $this->resolveProductBrand($product, $storeId);
                    if ($brand !== null) {
                        $itemData['brand'] = $brand;
                    }
                }

                $itemCategories = $this->getProductCategoryArray($product, $storeId);
                if (!empty($itemCategories)) {
                    $itemData['categories'] = $itemCategories;
                    $allCategories = array_merge($allCategories, $itemCategories);
                }
            } catch (\Exception $e) {
                // Product may have been deleted — still include the item without enrichment
                if ($includeBrand) {
                    $brand = $this->getProductBrandById($productId, $storeId);
                    if ($brand !== null) {
                        $itemData['brand'] = $brand;
                    }
                }
            }

            $items[] = $itemData;
        }

        $allCategories = array_values(array_unique($allCategories));

        $data = [
            // --- Order identification ---
            'order' => [
                'id' => (int)$order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'increment_id_display' => '#' . $order->getIncrementId(),
                'quote_id' => (int)$order->getQuoteId(),
                'created_at' => $order->getCreatedAt(),
                'status' => $order->getStatus(),
                'state' => $order->getState()
            ],

            // --- Flat keys for backward compat with purchase_tracking.phtml ---
            'order_id' => (int)$order->getEntityId(),
            'increment_id' => $order->getIncrementId(),
            'increment_id_display' => '#' . $order->getIncrementId(),
            'cart_id' => (int)$order->getQuoteId(),

            // --- Financials ---
            'financials' => [
                'total_value' => (int)round($grandTotal * $multiplier),
                'subtotal' => (int)round((float)$order->getSubtotal() * $multiplier),
                'shipping_amount' => (int)round((float)$order->getShippingAmount() * $multiplier),
                'discount_amount' => (int)round(abs((float)$order->getDiscountAmount()) * $multiplier),
                'tax_amount' => (int)round((float)$order->getTaxAmount() * $multiplier),
                'currency_code' => $order->getOrderCurrencyCode()
            ],
            // Flat aliases
            'total' => (int)round($grandTotal * $multiplier),
            'subtotal' => (int)round((float)$order->getSubtotal() * $multiplier),
            'tax' => (int)round((float)$order->getTaxAmount() * $multiplier),
            'shipping' => (int)round((float)$order->getShippingAmount() * $multiplier),
            'discount' => (int)round(abs((float)$order->getDiscountAmount()) * $multiplier),
            'currency_code' => $order->getOrderCurrencyCode(),

            // --- Flags ---
            'flags' => [
                'discounted' => (float)$order->getDiscountAmount() < 0,
                'is_virtual' => (bool)$order->getIsVirtual(),
                'is_guest' => !$order->getCustomerId()
            ],

            // --- Items ---
            'items' => $items,
            'item_count' => count($items),

            // --- Summary ---
            'summary' => [
                'ordered_products' => array_column($items, 'name'),
                'ordered_product_count' => count($items),
                'product_categories' => $allCategories
            ],

            // --- Customer ---
            'customer' => [
                'email' => $order->getCustomerEmail(),
                'firstname' => $order->getCustomerFirstname(),
                'lastname' => $order->getCustomerLastname(),
                'customer_id' => $order->getCustomerId(),
                'customer_group' => $this->getCustomerGroupName((int)$order->getCustomerGroupId())
            ],
            'email' => $order->getCustomerEmail(),

            // --- Addresses ---
            'addresses' => [
                'billing' => $this->formatAddress($order->getBillingAddress()),
                'shipping' => $order->getShippingAddress()
                    ? $this->formatAddress($order->getShippingAddress())
                    : null
            ],

            // --- Payment ---
            'payment' => [
                'method' => $order->getPayment()?->getMethod(),
                'method_title' => $order->getPayment()?->getMethodInstance()?->getTitle()
            ],

            // --- Shipping method ---
            'shipping_method' => [
                'method' => $order->getShippingMethod(),
                'method_title' => $order->getShippingDescription()
            ],

            // --- Store ---
            'store' => [
                'store_id' => $storeId,
                'store_code' => $order->getStore()->getCode(),
                'website_id' => (int)$order->getStore()->getWebsiteId()
            ]
        ];

        return $this->json->serialize($data);
    }

    /**
     * Format address data for JSON payload
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
     * Get customer group name by ID
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
