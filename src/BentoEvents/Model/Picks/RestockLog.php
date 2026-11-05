<?php
/**
 * Remembers when products come back in stock, which Magento does not record. Artists count a restock as
 * "new in", so new_in_* treats a restock like a newly added product (NextPicks::arrivals).
 *
 * Nightly (before the product-list refresh) it compares each simple/variant's stock with what it saw last
 * night, in artlounge_bento_stock_seen:
 *  - gone: not in stock, or qty <= 0                          → seen 0
 *  - back: in stock with qty >= "Minimum stock" (the picks rule) → seen 1; if it was 0, back_in_stock_at = now
 *  - in between (1–2 units) changes nothing, so a return trickling in is not a restock.
 * The first run only records what it sees (no history). A sell-out and restock inside one day is missed.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Picks;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\App\ResourceConnection;

class RestockLog
{
    public const TABLE = 'artlounge_bento_stock_seen';
    private const CHUNK = 1000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * Pure. $seen: product id => 0|1 from last night. $back / $gone: ids in stock (≥ min) / out of stock now.
     * @param array<int,int> $seen
     * @param int[] $back
     * @param int[] $gone
     * @return array{0:int[],1:array<int,int>} [restocked ids, id => new seen flag (changes and first sightings)]
     */
    public static function diff(array $seen, array $back, array $gone): array
    {
        $restocked = $flags = [];
        foreach ($back as $id) {
            $id = (int)$id;
            if (!isset($seen[$id])) {
                $flags[$id] = 1;
            } elseif ((int)$seen[$id] === 0) {
                $flags[$id] = 1;
                $restocked[] = $id;
            }
        }
        foreach ($gone as $id) {
            $id = (int)$id;
            if (!isset($seen[$id]) || (int)$seen[$id] === 1) {
                $flags[$id] = 0;
            }
        }

        return [$restocked, $flags];
    }

    /** @return array{restocked:int,changed:int} */
    public function update(): array
    {
        $c = $this->resource->getConnection();
        $stock = $c->select()
            ->from(['si' => $this->resource->getTableName('cataloginventory_stock_item')], ['product_id'])
            ->join(['e' => $this->resource->getTableName('catalog_product_entity')], 'e.entity_id = si.product_id', [])
            ->where('e.type_id IN (?)', ['simple', 'virtual']); // a configurable's own stock row means nothing
        $back = array_map('intval', $c->fetchCol((clone $stock)
            ->where('si.is_in_stock = 1')->where('si.qty >= ?', $this->config->getPickMinStock())));
        $gone = array_map('intval', $c->fetchCol((clone $stock)->where('si.is_in_stock = 0 OR si.qty <= 0')));
        $table = $this->resource->getTableName(self::TABLE);
        $seen = array_map('intval', $c->fetchPairs($c->select()->from($table, ['product_id', 'in_stock'])));

        [$restocked, $flags] = self::diff($seen, $back, $gone);
        $now = gmdate('Y-m-d H:i:s');
        $restockedSet = array_flip($restocked);
        $rows = [];
        foreach ($flags as $id => $flag) {
            $rows[] = ['product_id' => $id, 'in_stock' => $flag, 'back_in_stock_at' => isset($restockedSet[$id]) ? $now : null];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // back_in_stock_at only moves forward: a sell-out keeps the last restock date
            $c->insertOnDuplicate($table, $chunk, ['in_stock', 'back_in_stock_at' => new \Zend_Db_Expr(
                'IF(VALUES(back_in_stock_at) IS NULL, back_in_stock_at, VALUES(back_in_stock_at))'
            )]);
        }

        return ['restocked' => count($restocked), 'changed' => count($flags)];
    }

    /** @return array<int,string> product id => back_in_stock_at, for restocks at/after $since */
    public function backInStockSince(string $since): array
    {
        $c = $this->resource->getConnection();

        return $c->fetchPairs($c->select()
            ->from($this->resource->getTableName(self::TABLE), ['product_id', 'back_in_stock_at'])
            ->where('back_in_stock_at >= ?', $since));
    }
}
