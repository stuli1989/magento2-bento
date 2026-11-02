<?php
/**
 * Raw SQL reads for profile fields, batched by email (guest + registered merged — the
 * sales_order.customer_email collation is case-insensitive, emails are passed lower-cased).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Profile;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class ProfileLoader
{
    private ?CategoryTree $tree = null;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param string[] $emails lower-cased
     * @return array<string, array> email => [{increment_id, state, created_at, grand_total, total_refunded, purchased_at}]
     */
    public function orders(array $emails): array
    {
        if (!$emails) {
            return [];
        }
        $c = $this->conn();
        $select = $c->select()
            ->from(['so' => $this->t('sales_order')], [
                'email' => new \Zend_Db_Expr('LOWER(so.customer_email)'),
                'increment_id', 'state', 'status', 'created_at',
                'grand_total' => 'so.base_grand_total',
                'total_refunded' => new \Zend_Db_Expr('COALESCE(so.base_total_refunded, 0)'),
            ])
            ->joinLeft(['p' => $this->t('artlounge_bento_purchase')], 'p.order_id = so.entity_id', ['purchased_at'])
            ->where('so.customer_email IN (?)', $emails);

        $out = [];
        foreach ($c->fetchAll($select) as $row) {
            $out[$row['email']][] = $row;
        }

        return $out;
    }

    /**
     * Net item spend of counted orders with each product's category ids.
     * @return array<string, array> email => [{category_ids:int[], spend:float}]
     */
    public function items(array $emails): array
    {
        if (!$emails) {
            return [];
        }
        $c = $this->conn();
        $rows = $c->fetchAll(
            $c->select()
                ->from(['so' => $this->t('sales_order')], ['email' => new \Zend_Db_Expr('LOWER(so.customer_email)')])
                ->join(['soi' => $this->t('sales_order_item')], 'soi.order_id = so.entity_id', [
                    'product_id',
                    'spend' => new \Zend_Db_Expr(
                        'GREATEST(0, COALESCE(soi.base_row_total,0) - COALESCE(soi.base_discount_amount,0) - COALESCE(soi.base_amount_refunded,0))'
                    ),
                ])
                ->where('so.customer_email IN (?)', $emails)
                ->where("so.state IN (?) OR (so.state = 'new' AND so.status = 'processing')", StatsCalculator::COUNTED_STATES)
                ->where('soi.parent_item_id IS NULL')
        );
        $productIds = array_values(array_unique(array_map(static fn ($r) => (int)$r['product_id'], $rows)));
        $categoryIds = [];
        if ($productIds) {
            foreach ($c->fetchAll(
                $c->select()->from($this->t('catalog_category_product'), ['product_id', 'category_id'])
                    ->where('product_id IN (?)', $productIds)
            ) as $link) {
                $categoryIds[(int)$link['product_id']][] = (int)$link['category_id'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $out[$r['email']][] = [
                'category_ids' => $categoryIds[(int)$r['product_id']] ?? [],
                'spend' => (float)$r['spend'],
            ];
        }

        return $out;
    }

    /** @return array<string, array{is_registered:bool, created_at:?string}> */
    public function accounts(array $emails): array
    {
        if (!$emails) {
            return [];
        }
        $c = $this->conn();
        $out = [];
        foreach ($c->fetchAll(
            $c->select()->from($this->t('customer_entity'), [
                'email' => new \Zend_Db_Expr('LOWER(email)'),
                'created_at' => new \Zend_Db_Expr('MIN(created_at)'),
            ])->where('email IN (?)', $emails)->group(new \Zend_Db_Expr('LOWER(email)'))
        ) as $row) {
            $out[$row['email']] = ['is_registered' => true, 'created_at' => $row['created_at']];
        }

        return $out;
    }

    public function categoryTree(): CategoryTree
    {
        if ($this->tree === null) {
            $c = $this->conn();
            $paths = $c->fetchPairs($c->select()->from($this->t('catalog_category_entity'), ['entity_id', 'path']));
            $nameAttr = (int)$c->fetchOne(
                $c->select()->from(['a' => $this->t('eav_attribute')], ['attribute_id'])
                    ->join(['t' => $this->t('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
                    ->where('t.entity_type_code = ?', 'catalog_category')
                    ->where('a.attribute_code = ?', 'name')
            );
            $linkField = $c->tableColumnExists($this->t('catalog_category_entity_varchar'), 'row_id') ? 'row_id' : 'entity_id';
            $names = $c->fetchPairs(
                $c->select()->from($this->t('catalog_category_entity_varchar'), [$linkField, 'value'])
                    ->where('attribute_id = ?', $nameAttr)
                    ->where('store_id = 0')
            );
            $this->tree = new CategoryTree(
                array_map('strval', $paths),
                array_map(static fn ($v) => trim((string)$v), $names)
            );
        }

        return $this->tree;
    }

    /**
     * Emails with ≥1 counted order, ordered, after a cursor (for resumable backfill).
     * @return string[]
     */
    public function buyerEmailsAfter(string $afterEmail, int $limit): array
    {
        $c = $this->conn();

        return $c->fetchCol(
            $c->select()
                ->from($this->t('sales_order'), ['email' => new \Zend_Db_Expr('LOWER(customer_email)')])
                ->where("state IN (?) OR (state = 'new' AND status = 'processing')", StatsCalculator::COUNTED_STATES)
                ->where('customer_email IS NOT NULL AND customer_email <> ""')
                ->where('LOWER(customer_email) > ?', $afterEmail)
                ->group(new \Zend_Db_Expr('LOWER(customer_email)'))
                ->order('email ASC')
                ->limit($limit)
        );
    }

    /**
     * Emails with ≥1 counted order created at/after $since (UTC 'Y-m-d H:i:s'), ordered.
     * @return string[]
     */
    public function buyerEmailsSince(string $since): array
    {
        $c = $this->conn();

        return $c->fetchCol(
            $c->select()
                ->from($this->t('sales_order'), ['email' => new \Zend_Db_Expr('LOWER(customer_email)')])
                ->where("state IN (?) OR (state = 'new' AND status = 'processing')", StatsCalculator::COUNTED_STATES)
                ->where('customer_email IS NOT NULL AND customer_email <> ""')
                ->where('created_at >= ?', $since)
                ->group(new \Zend_Db_Expr('LOWER(customer_email)'))
                ->order('email ASC')
        );
    }

    /** @return array<string, array{first_name:?string, last_name:?string, phone:?string}> latest order's names/phone */
    public function contactDetails(array $emails): array
    {
        if (!$emails) {
            return [];
        }
        $c = $this->conn();
        $rows = $c->fetchAll(
            $c->select()
                ->from(['so' => $this->t('sales_order')], [
                    'email' => new \Zend_Db_Expr('LOWER(so.customer_email)'),
                    'first_name' => 'so.customer_firstname',
                    'last_name' => 'so.customer_lastname',
                    'created_at',
                ])
                ->joinLeft(
                    ['ba' => $this->t('sales_order_address')],
                    'ba.parent_id = so.entity_id AND ba.address_type = "billing"',
                    ['phone' => 'ba.telephone', 'b_first' => 'ba.firstname', 'b_last' => 'ba.lastname']
                )
                ->where('so.customer_email IN (?)', $emails)
                ->order('so.created_at ASC')
        );
        $out = [];
        foreach ($rows as $r) { // ascending → last write wins = latest order
            $out[$r['email']] = [
                'first_name' => $r['first_name'] ?: $r['b_first'],
                'last_name' => $r['last_name'] ?: $r['b_last'],
                'phone' => $r['phone'],
            ];
        }

        return $out;
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
