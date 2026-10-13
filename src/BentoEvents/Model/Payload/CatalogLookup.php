<?php
/**
 * Batched product facts for payloads: url, image, brand, category names.
 * One product collection + one category collection per payload (no N+1).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CatalogLookup
{
    /** @var array<int,string> */
    private array $categoryNames = [];

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int[] $productIds
     * @return array<int, array{url:?string, image:?string, brand:?string, categories:string[]}>
     */
    public function forProducts(array $productIds, int $storeId): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $out = [];
        try {
            $brandAttr = $this->config->getBrandAttribute($storeId);
            $includeBrand = $this->config->includeBrand($storeId);
            $mediaBase = $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId)
                ->addAttributeToSelect(['name', 'image', 'url_key', $brandAttr])
                ->addIdFilter($productIds)
                ->addUrlRewrite()
                ->addCategoryIds();

            $allCategoryIds = [];
            foreach ($collection as $product) {
                $allCategoryIds = array_merge($allCategoryIds, (array)$product->getCategoryIds());
            }
            $names = $this->categoryNames($allCategoryIds, $storeId);

            foreach ($collection as $product) {
                $image = $product->getData('image');
                $brand = null;
                if ($includeBrand && $product->getData($brandAttr)) {
                    $text = $product->getAttributeText($brandAttr);
                    $brand = is_array($text) ? implode(', ', $text) : ($text ?: (string)$product->getData($brandAttr));
                }
                $cats = [];
                foreach ((array)$product->getCategoryIds() as $cid) {
                    if (isset($names[(int)$cid])) {
                        $cats[] = $names[(int)$cid];
                    }
                }
                $out[(int)$product->getId()] = [
                    'url' => $product->getProductUrl(),
                    'image' => ($image && $image !== 'no_selection') ? $mediaBase . 'catalog/product' . $image : null,
                    'brand' => $brand ?: null,
                    'categories' => array_values(array_unique($cats)),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Bento catalog lookup failed; payload sent without product extras', [
                'error' => $e->getMessage(),
            ]);
        }

        return $out;
    }

    /** @return array<int,string> active, non-root category names */
    private function categoryNames(array $ids, int $storeId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $missing = array_diff($ids, array_keys($this->categoryNames));
        if ($missing) {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId($storeId)
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('entity_id', ['in' => $missing])
                ->addAttributeToFilter('level', ['gt' => 1])
                ->addIsActiveFilter();
            foreach ($collection as $category) {
                $this->categoryNames[(int)$category->getId()] = trim((string)$category->getName());
            }
            foreach ($missing as $id) {
                $this->categoryNames[$id] ??= '';
            }
        }

        return array_filter(array_intersect_key($this->categoryNames, array_flip($ids)), 'strlen');
    }
}
