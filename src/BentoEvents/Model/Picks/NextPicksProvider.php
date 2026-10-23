<?php
/**
 * next_pick_* subscriber fields for a batch of customers (NextPicks is the rule; this is the data).
 *
 * Candidates per medium (admin "Add-on categories per medium", descendants included), rolled up to the
 * configurable parent: parent enabled + visible, and in stock with ≥ "Minimum stock" units (a
 * configurable: any enabled child). Ranked by units sold on non-canceled orders in the sales window.
 * Candidates are computed once per process; cron/CLI/outbox runs are short-lived, so they stay fresh.
 *
 * Never throws: on any failure it returns [] so the caller sends no pick fields at all, rather than
 * blanks that would wipe the picks Bento already has.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Picks;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Payload\CatalogLookup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class NextPicksProvider
{
    private const VISIBLE = [2, 3, 4]; // catalog, search, catalog+search

    /** @var array{0:array<string,int[]>,1:array<string,int[]>}|null [ranked, pinned] */
    private ?array $candidates = null;
    private ?int $storeId = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ConfigInterface $config,
        private readonly CatalogLookup $catalog,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,?string> $mediumByEmail lower-cased email => primary_medium
     * @return array<string,array> email => next_pick_* fields, or [] on failure
     */
    public function fieldsFor(array $mediumByEmail): array
    {
        if (!$mediumByEmail) {
            return [];
        }
        try {
            [$ranked, $pinned] = $this->candidates();
            $bought = $this->boughtParents(array_keys($mediumByEmail));
            $chosen = [];
            foreach ($mediumByEmail as $email => $medium) {
                $chosen[$email] = NextPicks::choose($medium, $ranked, $pinned, $bought[$email] ?? []);
            }
            $ids = array_values(array_unique(array_merge([], ...array_values($chosen))));
            $products = $ids ? $this->catalog->forProducts($ids, $this->storeId()) : [];
            if ($missing = array_diff($ids, array_keys($products))) {
                throw new \RuntimeException('catalog lookup missing products ' . implode(',', $missing));
            }
            $out = [];
            foreach ($chosen as $email => $picked) {
                $out[$email] = NextPicks::fields($picked, $products);
            }

            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('Bento next picks failed; pick fields not sent', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return array{0:array<string,int[]>,1:array<string,int[]>} [medium => ranked parent ids, medium => pinned parent ids] */
    public function candidates(): array
    {
        if ($this->candidates !== null) {
            return $this->candidates;
        }
        $units = $this->unitsSold($this->config->getPickSalesWindowDays());
        $minStock = $this->config->getPickMinStock();
        $ranked = [];
        foreach ($this->config->getPickCategoryMap() as $medium => $categoryIds) {
            $ranked[$medium] = NextPicks::rank($this->eligibleParents($categoryIds, $minStock), $units);
        }
        $pinned = [];
        $c = $this->conn();
        foreach ($this->config->getPinnedPicks() as $medium => $skus) {
            $idBySku = array_change_key_case($c->fetchPairs(
                $c->select()->from($this->t('catalog_product_entity'), ['sku', 'entity_id'])->where('sku IN (?)', $skus)
            ));
            $ids = array_values(array_filter(array_map(static fn ($s) => (int)($idBySku[strtolower($s)] ?? 0), $skus)));
            $parentOf = $this->parentsOf($ids);
            $pinned[$medium] = array_values(array_unique(array_map(static fn ($id) => $parentOf[$id] ?? $id, $ids)));
        }

        return $this->candidates = [$ranked, $pinned];
    }

    /** @return array<int,float> parent id => units sold in the last $days days on non-canceled orders */
    private function unitsSold(int $days): array
    {
        $c = $this->conn();
        $qty = $c->fetchPairs(
            $c->select()
                ->from(['soi' => $this->t('sales_order_item')], ['product_id', 'units' => new \Zend_Db_Expr('SUM(soi.qty_ordered)')])
                ->join(['so' => $this->t('sales_order')], 'so.entity_id = soi.order_id', [])
                ->where('so.state <> ?', 'canceled')
                ->where('so.created_at >= ?', gmdate('Y-m-d H:i:s', time() - $days * 86400))
                ->where('soi.parent_item_id IS NULL') // configurable lines are counted once, on the parent
                ->group('soi.product_id')
        );

        return NextPicks::rollUp($qty, $this->parentsOf(array_keys($qty)));
    }

    /** @return int[] eligible parent ids in these categories (and their descendants) */
    private function eligibleParents(array $categoryIds, float $minStock): array
    {
        if (!$categoryIds) {
            return [];
        }
        $c = $this->conn();
        $paths = $c->select()->from($this->t('catalog_category_entity'), ['entity_id']);
        foreach ($categoryIds as $id) {
            $paths->orWhere('path LIKE ?', '%/' . (int)$id)->orWhere('path LIKE ?', '%/' . (int)$id . '/%');
        }
        $productIds = array_map('intval', $c->fetchCol(
            $c->select()->distinct()->from($this->t('catalog_category_product'), ['product_id'])
                ->where('category_id IN (?)', array_map('intval', $c->fetchCol($paths)) ?: [0])
        ));
        if (!$productIds) {
            return [];
        }
        $parentOf = $this->parentsOf($productIds);
        $parents = array_values(array_unique(array_map(static fn ($id) => $parentOf[$id] ?? $id, $productIds)));

        $types = $c->fetchPairs(
            $c->select()->from($this->t('catalog_product_entity'), ['entity_id', 'type_id'])->where('entity_id IN (?)', $parents)
        );
        $children = [];
        foreach ($c->fetchAll(
            $c->select()->from($this->t('catalog_product_super_link'), ['parent_id', 'product_id'])
                ->where('parent_id IN (?)', $parents)
        ) as $row) {
            $children[(int)$row['parent_id']][] = (int)$row['product_id'];
        }
        $stockIds = array_merge($parents, ...array_values($children));
        $enabled = array_flip($this->withAttribute($stockIds, 'status', [1]));
        $visible = array_flip($this->withAttribute($parents, 'visibility', self::VISIBLE));
        $inStock = array_flip(array_map('intval', $c->fetchCol(
            $c->select()->from($this->t('cataloginventory_stock_item'), ['product_id'])
                ->where('product_id IN (?)', $stockIds)
                ->where('is_in_stock = 1')
                ->where('qty >= ?', $minStock)
        )));

        return NextPicks::eligible($parents, $types, $children, $enabled, $visible, $inStock);
    }

    /**
     * Product ids whose int attribute (default store value, overridden by the default store view's) is in $values.
     * @return int[]
     */
    private function withAttribute(array $ids, string $code, array $values): array
    {
        if (!$ids) {
            return [];
        }
        $c = $this->conn();
        $attributeId = (int)$c->fetchOne(
            $c->select()->from(['a' => $this->t('eav_attribute')], ['attribute_id'])
                ->join(['t' => $this->t('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
                ->where('t.entity_type_code = ?', 'catalog_product')
                ->where('a.attribute_code = ?', $code)
        );
        $table = $this->t('catalog_product_entity_int');

        return array_map('intval', $c->fetchCol(
            $c->select()
                ->from(['d' => $table], ['entity_id'])
                ->joinLeft(
                    ['s' => $table],
                    $c->quoteInto('s.entity_id = d.entity_id AND s.attribute_id = d.attribute_id AND s.store_id = ?', $this->storeId()),
                    []
                )
                ->where('d.attribute_id = ?', $attributeId)
                ->where('d.store_id = 0')
                ->where('d.entity_id IN (?)', $ids)
                ->where('COALESCE(s.value, d.value) IN (?)', $values)
        ));
    }

    /** @return array<string,int[]> email => parent ids bought on any non-canceled order */
    private function boughtParents(array $emails): array
    {
        $c = $this->conn();
        $rows = $c->fetchAll(
            $c->select()
                ->distinct()
                ->from(['so' => $this->t('sales_order')], ['email' => new \Zend_Db_Expr('LOWER(so.customer_email)')])
                ->join(['soi' => $this->t('sales_order_item')], 'soi.order_id = so.entity_id', ['product_id'])
                ->where('so.customer_email IN (?)', $emails)
                ->where('so.state <> ?', 'canceled')
        );
        $parentOf = $this->parentsOf(array_map(static fn ($r) => (int)$r['product_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['product_id'];
            $out[$r['email']][] = $parentOf[$id] ?? $id;
        }

        return $out;
    }

    /** @return array<int,int> child id => one configurable parent id */
    private function parentsOf(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $c = $this->conn();

        return array_map('intval', $c->fetchPairs(
            $c->select()
                ->from($this->t('catalog_product_super_link'), ['product_id', 'parent_id' => 'MIN(parent_id)'])
                ->where('product_id IN (?)', $ids)
                ->group('product_id')
        ));
    }

    private function storeId(): int
    {
        return $this->storeId ??= (int)$this->storeManager->getDefaultStoreView()->getId();
    }

    private function conn(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function t(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
