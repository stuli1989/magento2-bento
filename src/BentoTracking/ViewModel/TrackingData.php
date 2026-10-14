<?php
/**
 * Tracking Data ViewModel
 *
 * Provides product and tracking data for frontend templates.
 */

declare(strict_types=1);

namespace ArtLounge\BentoTracking\ViewModel;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\Money;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

class TrackingData implements ArgumentInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Registry $registry,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $json
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

    public function isCartTrackingEnabled(): bool
    {
        return $this->config->isTrackCartEnabled($this->getStoreId());
    }

    public function isContactCaptureEnabled(): bool
    {
        return $this->config->isContactCaptureEnabled($this->getStoreId());
    }

    /** Remember the email-click bento_uuid so guest reviews can be attributed ($review_submitted). */
    public function isReviewAttributionEnabled(): bool
    {
        return $this->config->isReviewEventsEnabled($this->getStoreId());
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
     * Get current product data as JSON
     */
    public function getProductDataJson(): string
    {
        $product = $this->getCurrentProduct();

        if (!$product) {
            return '{}';
        }

        $storeId = $this->getStoreId();

        $data = [
            'product_id' => (int)$product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => Money::paise($this->resolveProductPrice($product)),
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
            $data['special_price'] = Money::paise((float)$specialPrice);
            $data['original_price'] = Money::paise($regularPrice);
        } elseif ($msrp > 0 && $msrp > $regularPrice) {
            $data['special_price'] = Money::paise($regularPrice);
            $data['original_price'] = Money::paise($msrp);
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
     * Check if debug logging is enabled
     */
    public function isDebugEnabled(): bool
    {
        return $this->config->isDebugEnabled($this->getStoreId());
    }
}
