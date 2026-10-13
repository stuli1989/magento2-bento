<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Fake;

use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Outbox\OutboxStoreInterface;

/**
 * Behavioural twin of DbOutboxStore for unit tests.
 */
class InMemoryOutboxStore implements OutboxStoreInterface
{
    /** @var array<int,array> */
    public array $rows = [];
    public bool $lockAvailable = true;
    public bool $throwOnInsert = false;
    private int $seq = 0;

    public function insert(array $row): bool
    {
        if ($this->throwOnInsert) {
            throw new \RuntimeException('DB down');
        }
        foreach ($this->rows as $existing) {
            if ($existing['dedup_key'] === $row['dedup_key']) {
                return false;
            }
        }
        $id = ++$this->seq;
        $this->rows[$id] = $row + [
            'id' => $id,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'last_error' => null,
        ];
        $this->rows[$id]['id'] = $id;

        return true;
    }

    public function acquireLock(): bool
    {
        return $this->lockAvailable;
    }

    public function releaseLock(): void
    {
    }

    public function claimNext(string $nowUtc): ?OutboxRow
    {
        foreach ($this->rows as $id => $r) {
            if ($r['status'] === self::STATUS_PENDING && ($r['next_attempt_at'] ?? '0000') <= $nowUtc) {
                $this->rows[$id]['status'] = self::STATUS_SENDING;
                return OutboxRow::fromArray($this->rows[$id]);
            }
        }
        return null;
    }

    public function markSent(int $id, string $nowUtc): void
    {
        $this->rows[$id]['status'] = self::STATUS_SENT;
        $this->rows[$id]['sent_at'] = $nowUtc;
    }

    public function markRetry(int $id, int $attempts, string $nextAttemptAtUtc, string $error): void
    {
        $this->rows[$id] = array_merge($this->rows[$id], [
            'status' => self::STATUS_PENDING,
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAtUtc,
            'last_error' => $error,
        ]);
    }

    public function markParked(int $id, int $attempts, string $error): void
    {
        $this->rows[$id] = array_merge($this->rows[$id], [
            'status' => self::STATUS_PARKED,
            'attempts' => $attempts,
            'last_error' => $error,
        ]);
    }

    public function markSkipped(int $id, string $reason): void
    {
        $this->rows[$id]['status'] = self::STATUS_SKIPPED;
        $this->rows[$id]['last_error'] = $reason;
    }

    public function resetStuck(string $olderThanUtc): int
    {
        return 0;
    }

    public function getById(int $id): ?OutboxRow
    {
        return isset($this->rows[$id]) ? OutboxRow::fromArray($this->rows[$id]) : null;
    }

    public function countByStatus(): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            $out[$r['status']] = ($out[$r['status']] ?? 0) + 1;
        }
        return $out;
    }

    public function oldestPendingCreatedAt(): ?string
    {
        return null;
    }

    public function listByStatus(string $status, int $limit): array
    {
        return array_map(
            [OutboxRow::class, 'fromArray'],
            array_values(array_filter($this->rows, fn ($r) => $r['status'] === $status))
        );
    }

    public function requeue(?array $ids): int
    {
        $n = 0;
        foreach ($this->rows as $id => $r) {
            if (($ids === null && $r['status'] === self::STATUS_PARKED) || ($ids !== null && in_array($id, $ids, true))) {
                $this->rows[$id]['status'] = self::STATUS_PENDING;
                $this->rows[$id]['attempts'] = 0;
                $this->rows[$id]['next_attempt_at'] = null;
                $n++;
            }
        }
        return $n;
    }

    public function existsWithPrefixSince(string $dedupPrefix, string $sinceUtc): bool
    {
        foreach ($this->rows as $r) {
            if (str_starts_with($r['dedup_key'], $dedupPrefix) && $r['created_at'] >= $sinceUtc) {
                return true;
            }
        }
        return false;
    }

    public function purge(string $beforeUtc): int
    {
        return 0;
    }

    public function statusOf(int $id): string
    {
        return $this->rows[$id]['status'];
    }
}
