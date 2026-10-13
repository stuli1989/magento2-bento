<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Outbox;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class DbOutboxStore implements OutboxStoreInterface
{
    public const TABLE = 'artlounge_bento_outbox';
    private const LOCK_NAME = 'artlounge_bento_outbox_send';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function insert(array $row): bool
    {
        // INSERT IGNORE: 1 row affected on insert, 0 when dedup_key already exists.
        $affected = $this->conn()->insertArray(
            $this->table(),
            array_keys($row),
            [array_values($row)],
            AdapterInterface::INSERT_IGNORE
        );

        return (int)$affected === 1;
    }

    public function acquireLock(): bool
    {
        return (int)$this->conn()->fetchOne('SELECT GET_LOCK(?, 0)', [$this->lockName()]) === 1;
    }

    public function releaseLock(): void
    {
        $this->conn()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
    }

    public function claimNext(string $nowUtc): ?OutboxRow
    {
        $conn = $this->conn();
        for ($i = 0; $i < 5; $i++) {
            $id = $conn->fetchOne(
                $conn->select()
                    ->from($this->table(), ['id'])
                    ->where('status = ?', self::STATUS_PENDING)
                    ->where('next_attempt_at IS NULL OR next_attempt_at <= ?', $nowUtc)
                    ->order('id ASC')
                    ->limit(1)
            );
            if (!$id) {
                return null;
            }
            $claimed = $conn->update(
                $this->table(),
                ['status' => self::STATUS_SENDING],
                ['id = ?' => (int)$id, 'status = ?' => self::STATUS_PENDING]
            );
            if ($claimed === 1) {
                return $this->getById((int)$id);
            }
        }

        return null;
    }

    public function markSent(int $id, string $nowUtc): void
    {
        $this->conn()->update(
            $this->table(),
            ['status' => self::STATUS_SENT, 'sent_at' => $nowUtc, 'last_error' => null],
            ['id = ?' => $id]
        );
    }

    public function markRetry(int $id, int $attempts, string $nextAttemptAtUtc, string $error): void
    {
        $this->conn()->update(
            $this->table(),
            ['status' => self::STATUS_PENDING, 'attempts' => $attempts, 'next_attempt_at' => $nextAttemptAtUtc, 'last_error' => $error],
            ['id = ?' => $id]
        );
    }

    public function markParked(int $id, int $attempts, string $error): void
    {
        $this->conn()->update(
            $this->table(),
            ['status' => self::STATUS_PARKED, 'attempts' => $attempts, 'last_error' => $error],
            ['id = ?' => $id]
        );
    }

    public function markSkipped(int $id, string $reason): void
    {
        $this->conn()->update(
            $this->table(),
            ['status' => self::STATUS_SKIPPED, 'last_error' => $reason],
            ['id = ?' => $id]
        );
    }

    public function resetStuck(string $olderThanUtc): int
    {
        return $this->conn()->update(
            $this->table(),
            ['status' => self::STATUS_PENDING],
            ['status = ?' => self::STATUS_SENDING, 'updated_at < ?' => $olderThanUtc]
        );
    }

    public function getById(int $id): ?OutboxRow
    {
        $row = $this->conn()->fetchRow(
            $this->conn()->select()->from($this->table())->where('id = ?', $id)
        );

        return $row ? OutboxRow::fromArray($row) : null;
    }

    public function countByStatus(): array
    {
        $conn = $this->conn();
        $pairs = $conn->fetchPairs(
            $conn->select()->from($this->table(), ['status', 'cnt' => new \Zend_Db_Expr('COUNT(*)')])->group('status')
        );

        return array_map('intval', $pairs);
    }

    public function oldestPendingCreatedAt(): ?string
    {
        $v = $this->conn()->fetchOne(
            $this->conn()->select()->from($this->table(), [new \Zend_Db_Expr('MIN(created_at)')])
                ->where('status IN (?)', [self::STATUS_PENDING, self::STATUS_SENDING])
        );

        return $v ?: null;
    }

    public function listByStatus(string $status, int $limit): array
    {
        $rows = $this->conn()->fetchAll(
            $this->conn()->select()->from($this->table())->where('status = ?', $status)->order('id DESC')->limit($limit)
        );

        return array_map([OutboxRow::class, 'fromArray'], $rows);
    }

    public function requeue(?array $ids): int
    {
        $where = $ids === null
            ? ['status = ?' => self::STATUS_PARKED]
            : ['id IN (?)' => array_map('intval', $ids)];
        if ($ids !== null && $ids === []) {
            return 0;
        }

        return $this->conn()->update(
            $this->table(),
            ['status' => self::STATUS_PENDING, 'attempts' => 0, 'next_attempt_at' => null],
            $where
        );
    }

    public function existsWithPrefixSince(string $dedupPrefix, string $sinceUtc): bool
    {
        $conn = $this->conn();
        $id = $conn->fetchOne(
            $conn->select()->from($this->table(), ['id'])
                ->where('dedup_key LIKE ?', addcslashes($dedupPrefix, '%_\\') . '%')
                ->where('created_at >= ?', $sinceUtc)
                ->limit(1)
        );

        return (bool)$id;
    }

    public function purge(string $beforeUtc): int
    {
        return $this->conn()->delete($this->table(), [
            'status IN (?)' => [self::STATUS_SENT, self::STATUS_SKIPPED],
            'created_at < ?' => $beforeUtc,
        ]);
    }

    private function conn(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function lockName(): string
    {
        // Scope the lock to this database so two installs on one MySQL server don't contend.
        return self::LOCK_NAME . ':' . $this->conn()->fetchOne('SELECT DATABASE()');
    }
}
