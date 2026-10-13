<?php
/**
 * artlounge_bento_checkout_contact persistence (contact typed into the Magic Checkout popup).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Checkout;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class ContactStore
{
    public const TABLE = 'artlounge_bento_checkout_contact';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ENQUEUED = 'enqueued';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * One row per Razorpay order (= checkout attempt). A repeat POST for the same attempt
     * refreshes email/phone but never re-arms a row that was already processed.
     */
    public function upsert(int $quoteId, string $rzpOrderId, string $email, ?string $phone, int $storeId, string $dueAtUtc): void
    {
        $conn = $this->conn();
        $now = gmdate('Y-m-d H:i:s');
        $existing = $conn->fetchRow(
            $conn->select()->from($this->table(), ['id', 'status'])->where('rzp_order_id = ?', $rzpOrderId)
        );
        if ($existing) {
            if ($existing['status'] === self::STATUS_PENDING) {
                $conn->update($this->table(), ['email' => $email, 'phone' => $phone], ['id = ?' => (int)$existing['id']]);
            }
            return;
        }
        $conn->insertArray(
            $this->table(),
            ['quote_id', 'rzp_order_id', 'email', 'phone', 'store_id', 'status', 'due_at', 'created_at'],
            [[$quoteId, $rzpOrderId, $email, $phone, $storeId, self::STATUS_PENDING, $dueAtUtc, $now]],
            AdapterInterface::INSERT_IGNORE
        );
    }

    /** @return array<int,array> due pending rows */
    public function findDue(string $nowUtc, int $limit = 200): array
    {
        $conn = $this->conn();

        return $conn->fetchAll(
            $conn->select()->from($this->table())
                ->where('status = ?', self::STATUS_PENDING)
                ->where('due_at <= ?', $nowUtc)
                ->order('id ASC')
                ->limit($limit)
        );
    }

    public function getById(int $id): ?array
    {
        $conn = $this->conn();
        $row = $conn->fetchRow($conn->select()->from($this->table())->where('id = ?', $id));

        return $row ?: null;
    }

    public function setStatus(int $id, string $status): void
    {
        $this->conn()->update($this->table(), ['status' => $status], ['id = ?' => $id]);
    }

    public function cancelForRzpOrder(string $rzpOrderId): void
    {
        $this->conn()->update(
            $this->table(),
            ['status' => self::STATUS_CANCELLED],
            ['rzp_order_id = ?' => $rzpOrderId, 'status = ?' => self::STATUS_PENDING]
        );
    }

    public function countSince(string $sinceUtc): int
    {
        $conn = $this->conn();

        return (int)$conn->fetchOne(
            $conn->select()->from($this->table(), [new \Zend_Db_Expr('COUNT(*)')])->where('created_at >= ?', $sinceUtc)
        );
    }

    public function purge(string $beforeUtc): int
    {
        return $this->conn()->delete($this->table(), ['created_at < ?' => $beforeUtc]);
    }

    private function conn(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
