<?php
/**
 * Persistence for artlounge_bento_outbox. All datetimes are UTC 'Y-m-d H:i:s'.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Outbox;

interface OutboxStoreInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_PARKED = 'parked';
    public const STATUS_SKIPPED = 'skipped';

    /** INSERT IGNORE on dedup_key. @return bool true when a new row was created */
    public function insert(array $row): bool;

    /** Non-blocking named lock so only one sender runs. */
    public function acquireLock(): bool;

    public function releaseLock(): void;

    /** Atomically move the oldest due pending row to `sending` and return it. */
    public function claimNext(string $nowUtc): ?OutboxRow;

    public function markSent(int $id, string $nowUtc): void;

    public function markRetry(int $id, int $attempts, string $nextAttemptAtUtc, string $error): void;

    public function markParked(int $id, int $attempts, string $error): void;

    public function markSkipped(int $id, string $reason): void;

    /** Rows stuck in `sending` since before $olderThanUtc go back to pending. @return int count */
    public function resetStuck(string $olderThanUtc): int;

    public function getById(int $id): ?OutboxRow;

    /** @return array<string,int> status => count */
    public function countByStatus(): array;

    public function oldestPendingCreatedAt(): ?string;

    /** @return OutboxRow[] */
    public function listByStatus(string $status, int $limit): array;

    /** Parked/skipped rows back to pending with attempts 0. @param int[]|null $ids null = all parked */
    public function requeue(?array $ids): int;

    /** True if any row with dedup_key LIKE $prefix% was created at/after $sinceUtc. */
    public function existsWithPrefixSince(string $dedupPrefix, string $sinceUtc): bool;

    /** Delete sent/skipped rows created before $beforeUtc. */
    public function purge(string $beforeUtc): int;
}
