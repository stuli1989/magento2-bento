<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Abandonment;

use ArtLounge\BentoEvents\Model\Purchase\PurchaseDetector;
use Magento\Framework\App\ResourceConnection;

class AbandonmentQueries implements AbandonmentQueriesInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function quoteHasPaidOrder(int $quoteId): bool
    {
        $c = $this->resource->getConnection();

        return (bool)$c->fetchOne(
            $c->select()->from($this->resource->getTableName('sales_order'), ['entity_id'])
                ->where('quote_id = ?', $quoteId)
                ->where('state IN (?)', PurchaseDetector::PAID_STATES)
                ->limit(1)
        );
    }

    public function idleCarts(string $idleBeforeUtc, float $minValue, int $limit = 500): array
    {
        $c = $this->resource->getConnection();
        $rows = $c->fetchAll(
            $c->select()
                ->from(['q' => $this->resource->getTableName('quote')], [
                    'quote_id' => 'q.entity_id',
                    'email' => 'q.customer_email',
                    'store_id' => 'q.store_id',
                    'updated_at' => 'q.updated_at',
                ])
                ->joinLeft(['so' => $this->resource->getTableName('sales_order')], 'so.quote_id = q.entity_id', [])
                ->where('q.is_active = 1')
                ->where('q.items_count > 0')
                ->where("q.customer_email IS NOT NULL AND q.customer_email <> ''")
                ->where('q.updated_at <= ?', $idleBeforeUtc)
                ->where('q.updated_at >= ?', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                ->where('q.base_grand_total >= ?', $minValue)
                ->where('so.entity_id IS NULL')
                ->order('q.updated_at ASC')
                ->limit($limit)
        );

        return array_map(static fn ($r) => [
            'quote_id' => (int)$r['quote_id'],
            'email' => (string)$r['email'],
            'store_id' => (int)$r['store_id'],
            'updated_at' => (string)$r['updated_at'],
        ], $rows);
    }
}
