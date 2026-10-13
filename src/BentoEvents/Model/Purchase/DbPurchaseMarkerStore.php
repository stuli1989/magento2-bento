<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Purchase;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class DbPurchaseMarkerStore implements PurchaseMarkerStoreInterface
{
    public const TABLE = 'artlounge_bento_purchase';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function claim(int $orderId, string $incrementId, string $email, string $purchasedAtUtc): bool
    {
        $conn = $this->resource->getConnection();
        $affected = $conn->insertArray(
            $this->resource->getTableName(self::TABLE),
            ['order_id', 'increment_id', 'email', 'purchased_at', 'seeded'],
            [[$orderId, $incrementId, $email, $purchasedAtUtc, 0]],
            AdapterInterface::INSERT_IGNORE
        );

        return (int)$affected === 1;
    }

    public function exists(int $orderId): bool
    {
        $conn = $this->resource->getConnection();

        return (bool)$conn->fetchOne(
            $conn->select()->from($this->resource->getTableName(self::TABLE), ['order_id'])->where('order_id = ?', $orderId)
        );
    }
}
