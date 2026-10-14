<?php
/**
 * Batched product facts for payloads: sku, name, url, image (email thumbnail URL), brand, category names.
 * One product collection + one category collection per payload (no N+1). Products with no image of
 * their own add one super-link query + one parent collection.
 *
 * image = ThumbnailUrlBuilder URL of ProductImage's pick (thumbnail → small_image → image → the
 * configurable parent's), or null.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class CatalogLookup
{
    /** @var array<int,string> */
    private array $categoryNames = [];

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ThumbnailUrlBuilder $thumbnails,
        private readonly ResourceConnection $resource,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int[] $productIds
     * @return array<int, array{sku:?string, name:?string, url:?string, image:?string, brand:?string, categories:string[]}>
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

            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId)
                ->addAttributeToSelect(array_merge(['name', 'url_key', $brandAttr], ProductImage::ROLES))
                ->addIdFilter($productIds)
                ->addUrlRewrite()
                ->addCategoryIds();

            $allCategoryIds = [];
            foreach ($collection as $product) {
                $allCategoryIds = array_merge($allCategoryIds, (array)$product->getCategoryIds());
            }
            $names = $this->categoryNames($allCategoryIds, $storeId);
            $imageUrls = $this->imageUrls($collection, $storeId);

            foreach ($collection as $product) {
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
                    'sku' => $product->getSku() !== null ? (string)$product->getSku() : null,
                    'name' => $product->getName() !== null ? (string)$product->getName() : null,
                    'url' => $product->getProductUrl(),
                    'image' => $imageUrls[(int)$product->getId()] ?? null,
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

    /** @return array<int,?string> product id => email thumbnail URL */
    private function imageUrls(iterable $collection, int $storeId): array
    {
        $own = [];
        foreach ($collection as $product) {
            $own[(int)$product->getId()] = $this->roles($product);
        }
        $missing = array_keys(array_filter($own, static fn ($a) => ProductImage::pickFile($a) === null));
        $parentOf = [];
        $parents = [];
        if ($missing) {
            $parentOf = $this->configurableParents($missing);
            if ($parentOf) {
                $parentCollection = $this->productCollectionFactory->create();
                $parentCollection->setStoreId($storeId)
                    ->addAttributeToSelect(ProductImage::ROLES)
                    ->addIdFilter(array_values(array_unique($parentOf)));
                foreach ($parentCollection as $parent) {
                    $parents[(int)$parent->getId()] = $this->roles($parent);
                }
            }
        }

        return $this->thumbnails->urls(ProductImage::resolve($own, $parentOf, $parents), $storeId);
    }

    private function roles($product): array
    {
        $roles = [];
        foreach (ProductImage::ROLES as $role) {
            $roles[$role] = $product->getData($role);
        }

        return $roles;
    }

    /**
     * child id => one configurable parent id (catalog_product_super_link; Open Source entity ids).
     * @param int[] $childIds
     * @return array<int,int>
     */
    private function configurableParents(array $childIds): array
    {
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchPairs(
            $conn->select()
                ->from($this->resource->getTableName('catalog_product_super_link'), ['product_id', 'parent_id' => 'MIN(parent_id)'])
                ->where('product_id IN (?)', $childIds)
                ->group('product_id')
        );
        $out = [];
        foreach ($rows as $child => $parent) {
            $out[(int)$child] = (int)$parent;
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
