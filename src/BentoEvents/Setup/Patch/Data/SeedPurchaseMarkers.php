<?php
/**
 * Marks every pre-existing paid / closed / canceled order as "already purchased" (seeded=1),
 * so an old order that is shipped (→ complete) after deploy never fires $purchase, while a
 * later cancellation of a historic paid order still fires $OrderCanceled.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class SeedPurchaseMarkers implements DataPatchInterface
{
    public function __construct(private readonly ModuleDataSetupInterface $setup)
    {
    }

    public function apply(): self
    {
        $conn = $this->setup->getConnection();
        $select = $conn->select()
            ->from($this->setup->getTable('sales_order'), [
                'order_id' => 'entity_id',
                'increment_id',
                'email' => new \Zend_Db_Expr('LOWER(COALESCE(customer_email, ""))'),
                'purchased_at' => 'created_at',
                'seeded' => new \Zend_Db_Expr('1'),
            ])
            ->where('state IN (?)', ['processing', 'complete', 'closed', 'canceled']);

        $conn->query($conn->insertFromSelect(
            $select,
            $this->setup->getTable('artlounge_bento_purchase'),
            ['order_id', 'increment_id', 'email', 'purchased_at', 'seeded'],
            \Magento\Framework\DB\Adapter\AdapterInterface::INSERT_IGNORE
        ));

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
