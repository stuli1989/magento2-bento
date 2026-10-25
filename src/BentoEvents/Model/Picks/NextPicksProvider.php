<?php
/**
 * Product-list subscriber fields for a batch of customers (NextPicks is the rule; this is the data):
 *  - next_pick_*: admin "Add-on categories per medium" (descendants included), ranked by units sold on
 *    non-canceled orders in the sales window, pins first.
 *  - new_in_*: products created in the new-arrivals window AND after the customer's last order, whose medium
 *    (Category → Medium Map, deepest mapped category wins, as for primary_medium) is the customer's; newest
 *    first; never a current next pick.
 *  - usual_*: what the customer has bought in ≥ 2 separate orders.
 * Everything is parent level (rolled up from configurable children); bought products are excluded from
 * picks and new arrivals. Only enabled + visible parents with ≥ "Minimum stock" units (a configurable:
 * any enabled child) are shown. A configurable is shown as a variant (name + image), linked to the
 * parent page. Candidates are computed once per process; cron/CLI/outbox runs are short-lived.
 *
 * Never throws: on any failure it returns [] so the caller sends none of these fields, rather than
 * blanks that would wipe the lists Bento already has.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Picks;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Payload\CatalogLookup;
use ArtLounge\BentoEvents\Model\Profile\ProfileLoader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class NextPicksProvider
{
    private const VISIBLE = [2, 3, 4]; // catalog, search, catalog+search

    /** @var array{0:array<string,int[]>,1:array<string,int[]>,2:array<int,int>,3:array<string,int[]>,4:array<int,float>,5:array<int,string>}|null */
    private ?array $candidates = null;
    private ?int $storeId = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ConfigInterface $config,
        private readonly CatalogLookup $catalog,
        private readonly ProfileLoader $profileLoader,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,?string> $mediumByEmail lower-cased email => primary_medium
     * @return array<string,array> email => next_pick_*, new_in_* and usual_* fields, or [] on failure
     */
    public function fieldsFor(array $mediumByEmail): array
    {
        if (!$mediumByEmail) {
            return [];
        }
        try {
            [$ranked, $pinned, $variantOf, $newest, $productUnits, $createdAt] = $this->candidates();
            $lines = $this->purchaseLines(array_keys($mediumByEmail));
            $picks = $newIn = $usualShow = [];
            foreach ($mediumByEmail as $email => $medium) {
                $bought = array_values(array_unique(array_column($lines[$email] ?? [], 'parent_id')));
                $lastOrderAt = ($lines[$email] ?? []) ? max(array_column($lines[$email], 'created_at')) : null;
                [$picks[$email], $newIn[$email]] = NextPicks::picksAndNewIn(
                    $medium, $bought, $ranked, $pinned, $newest, $lastOrderAt, $createdAt
                );
            }
            [$eligible, $bestVariant, $sellable] = $this->filter($this->repeatParents($lines), $productUnits);
            $eligible = array_flip($eligible);
            foreach ($mediumByEmail as $email => $medium) {
                $usualShow[$email] = NextPicks::usuals($lines[$email] ?? [], $eligible, $sellable, $bestVariant);
            }

            $parents = array_merge([], ...array_values($picks), ...array_values($newIn));
            $ids = array_merge($parents, array_values(array_intersect_key($variantOf, array_flip($parents))));
            foreach ($usualShow as $show) {
                $ids = array_merge($ids, array_keys($show), array_values($show));
            }
            $ids = array_values(array_unique($ids));
            $products = $ids ? $this->catalog->forProducts($ids, $this->storeId()) : [];
            if ($missing = array_diff($ids, array_keys($products))) {
                throw new \RuntimeException('catalog lookup missing products ' . implode(',', $missing));
            }
            $display = NextPicks::display(array_values(array_unique($parents)), $variantOf, $products);
            $out = [];
            foreach ($mediumByEmail as $email => $medium) {
                $usuals = NextPicks::display(array_keys($usualShow[$email]), $usualShow[$email], $products);
                $out[$email] = NextPicks::fields($picks[$email], $display, 'next_pick_')
                    + NextPicks::fields($newIn[$email], $display, 'new_in_')
                    + NextPicks::fields(array_keys($usualShow[$email]), $usuals, 'usual_');
            }

            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('Bento product lists failed; next_pick/new_in/usual fields not sent', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array{0:array<string,int[]>,1:array<string,int[]>,2:array<int,int>,3:array<string,int[]>,4:array<int,float>,5:array<int,string>}
     *         [medium => ranked pick parents, medium => pinned parents, configurable => variant shown,
     *          medium (and "any") => new-arrival parents newest first, product id => units sold,
     *          new-arrival parent => created_at]
     */
    public function candidates(): array
    {
        if ($this->candidates !== null) {
            return $this->candidates;
        }
        [$units, $productUnits] = $this->unitsSold($this->config->getPickSalesWindowDays());
        $ranked = [];
        $variantOf = [];
        foreach ($this->config->getPickCategoryMap() as $medium => $categoryIds) {
            [$eligible, $variants] = $this->filter($this->parentsInCategories($categoryIds), $productUnits);
            $ranked[$medium] = NextPicks::rank($eligible, $units);
            $variantOf += $variants;
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
        [$newest, $newVariants, $createdAt] = $this->newArrivals($productUnits);

        return $this->candidates = [$ranked, $pinned, $variantOf + $newVariants, $newest, $productUnits, $createdAt];
    }

    /**
     * Eligible parents created in the new-arrivals window, by medium; "any" = every mapped medium.
     * @return array{0:array<string,int[]>,1:array<int,int>,2:array<int,string>}
     *         [medium => newest-first parent ids, configurable => variant, eligible parent => created_at]
     */
    private function newArrivals(array $productUnits): array
    {
        $c = $this->conn();
        $since = gmdate('Y-m-d H:i:s', time() - $this->config->getNewArrivalsWindowDays() * 86400);
        $createdAt = $c->fetchPairs($c->select()->from($this->t('catalog_product_entity'), ['entity_id', 'created_at']));
        $recent = array_keys(array_filter($createdAt, static fn ($at) => (string)$at >= $since));
        $parents = NextPicks::newArrivals($createdAt, $this->parentsOf($recent), $since);
        [$eligible, $variants] = $this->filter($parents, $productUnits);
        if (!$eligible) {
            return [[], [], []];
        }
        $categoryIds = [];
        foreach ($c->fetchAll(
            $c->select()->from($this->t('catalog_category_product'), ['product_id', 'category_id'])->where('product_id IN (?)', $eligible)
        ) as $row) {
            $categoryIds[(int)$row['product_id']][] = (int)$row['category_id'];
        }
        $tree = $this->profileLoader->categoryTree();
        $map = $this->config->getCategoryMediumMap();
        $byMedium = [];
        foreach ($eligible as $id) {
            $medium = $tree->mediumFor($categoryIds[$id] ?? [], $map);
            if ($medium !== null) {
                $byMedium[$medium][] = $id;
                $byMedium[NextPicks::ANY][] = $id;
            }
        }

        return [
            array_map(static fn (array $ids) => NextPicks::newestFirst($ids, $createdAt), $byMedium),
            $variants,
            array_map('strval', array_intersect_key($createdAt, array_flip($eligible))),
        ];
    }

    /**
     * Units sold in the last $days days on non-canceled orders.
     * @return array{0:array<int,float>,1:array<int,float>} [parent id => units, product id => units (variants too)]
     */
    private function unitsSold(int $days): array
    {
        $c = $this->conn();
        $select = $c->select()
            ->from(['soi' => $this->t('sales_order_item')], ['product_id', 'units' => new \Zend_Db_Expr('SUM(soi.qty_ordered)')])
            ->join(['so' => $this->t('sales_order')], 'so.entity_id = soi.order_id', [])
            ->where('so.state <> ?', 'canceled')
            ->where('so.created_at >= ?', gmdate('Y-m-d H:i:s', time() - $days * 86400))
            ->group('soi.product_id');
        // Variant lines repeat their configurable line's qty: count only top-level lines for the parent.
        $qty = $c->fetchPairs((clone $select)->where('soi.parent_item_id IS NULL'));

        return [
            NextPicks::rollUp($qty, $this->parentsOf(array_keys($qty))),
            array_map('floatval', $c->fetchPairs($select)),
        ];
    }

    /** @return int[] parents of the products in these categories and their descendants */
    private function parentsInCategories(array $categoryIds): array
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
        $parentOf = $this->parentsOf($productIds);

        return array_values(array_unique(array_map(static fn ($id) => $parentOf[$id] ?? $id, $productIds)));
    }

    /**
     * The shared stock / visibility filter.
     * @param int[] $parents
     * @param array<int,float> $productUnits product id => units sold (picks each configurable's variant)
     * @return array{0:int[],1:array<int,int>,2:array<int,true>}
     *         [eligible parent ids, configurable => best-selling sellable variant, sellable variant id set]
     */
    private function filter(array $parents, array $productUnits): array
    {
        if (!$parents) {
            return [[], [], []];
        }
        $c = $this->conn();
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
                ->where('qty >= ?', $this->config->getPickMinStock())
        )));

        $eligible = NextPicks::eligible($parents, $types, $children, $enabled, $visible, $inStock);
        $sellable = array_fill_keys(array_keys(array_intersect_key($enabled, $inStock)), true);

        return [$eligible, NextPicks::variants($eligible, $children, $enabled, $inStock, $productUnits), $sellable];
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

    /**
     * Every line of every non-canceled order, rolled up to the parent.
     * @return array<string,array> email => [{order_id, created_at, parent_id, product_id}]
     */
    private function purchaseLines(array $emails): array
    {
        $c = $this->conn();
        $rows = $c->fetchAll(
            $c->select()
                ->from(['so' => $this->t('sales_order')], [
                    'email' => new \Zend_Db_Expr('LOWER(so.customer_email)'),
                    'order_id' => 'so.entity_id',
                    'created_at' => 'so.created_at',
                ])
                ->join(['soi' => $this->t('sales_order_item')], 'soi.order_id = so.entity_id', ['product_id'])
                ->where('so.customer_email IN (?)', $emails)
                ->where('so.state <> ?', 'canceled')
        );
        $parentOf = $this->parentsOf(array_map(static fn ($r) => (int)$r['product_id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['product_id'];
            $out[$r['email']][] = [
                'order_id' => (int)$r['order_id'],
                'created_at' => (string)$r['created_at'],
                'parent_id' => $parentOf[$id] ?? $id,
                'product_id' => $id,
            ];
        }

        return $out;
    }

    /** @return int[] parents some customer in the batch bought in ≥ 2 orders (the only possible usuals) */
    private function repeatParents(array $lines): array
    {
        $out = [];
        foreach ($lines as $customerLines) {
            $orders = [];
            foreach ($customerLines as $l) {
                $orders[$l['parent_id']][$l['order_id']] = true;
            }
            foreach ($orders as $parent => $o) {
                if (count($o) >= 2) {
                    $out[$parent] = (int)$parent;
                }
            }
        }

        return array_values($out);
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
