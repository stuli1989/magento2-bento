<?php
/**
 * Outbox Processor
 *
 * Replays pending outbox entries by re-publishing to the AMQP queue.
 * Uses atomic status transitions to prevent double-pickup by concurrent cron runs.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Outbox;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

class Processor
{
    private const TABLE = 'artlounge_bento_event_outbox';
    private const QUEUE_TOPIC = 'event.trigger';
    private const STUCK_THRESHOLD_MINUTES = 10;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Exponential backoff schedule in seconds, indexed by attempt number (1-based).
     */
    private const BACKOFF_SECONDS = [
        1 => 60,
        2 => 300,
        3 => 900,
        4 => 1800,
        5 => 3600,
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process pending outbox entries.
     *
     * @return array{processed: int, failed: int, skipped: int}
     */
    public function processQueue(int $limit = 50): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $result = ['processed' => 0, 'failed' => 0, 'skipped' => 0];

        // Reset stuck entries (processing for > 10 min = likely crashed process).
        // Uses updated_at which is set when transitioning to 'processing'.
        $stuckThreshold = (new \DateTime("-" . self::STUCK_THRESHOLD_MINUTES . " minutes"))
            ->format('Y-m-d H:i:s');
        $resetCount = $connection->update(
            $table,
            ['status' => self::STATUS_PENDING, 'updated_at' => $now],
            [
                'status = ?' => self::STATUS_PROCESSING,
                'updated_at <= ?' => $stuckThreshold,
            ]
        );
        if ($resetCount > 0) {
            $this->logger->warning('Reset stuck outbox entries', ['count' => $resetCount]);
        }

        // Fetch pending entries ready for retry
        $select = $connection->select()
            ->from($table)
            ->where('status = ?', self::STATUS_PENDING)
            ->where('next_retry_at <= ?', $now)
            ->order('created_at ASC')
            ->limit($limit);

        $entries = $connection->fetchAll($select);

        foreach ($entries as $entry) {
            $outboxId = (int)$entry['outbox_id'];

            // Atomic claim: only proceed if we successfully transition to 'processing'.
            // Sets updated_at so stuck-detection uses the actual processing start time.
            $claimed = $connection->update(
                $table,
                ['status' => self::STATUS_PROCESSING, 'updated_at' => $now],
                [
                    'outbox_id = ?' => $outboxId,
                    'status = ?' => self::STATUS_PENDING,
                ]
            );

            if ($claimed === 0) {
                $result['skipped']++;
                continue;
            }

            try {
                $this->publisher->publish(
                    self::QUEUE_TOPIC,
                    [$entry['event_name'], $entry['arguments']]
                );

                // Success — mark completed
                $connection->update($table, [
                    'status' => self::STATUS_COMPLETED,
                    'processed_at' => $now,
                ], ['outbox_id = ?' => $outboxId]);

                $result['processed']++;

                $this->logger->info('Outbox event replayed successfully', [
                    'outbox_id' => $outboxId,
                    'event_name' => $entry['event_name'],
                ]);

            } catch (\Exception $e) {
                $attempts = (int)$entry['attempts'] + 1;
                $maxAttempts = (int)$entry['max_attempts'];

                if ($attempts >= $maxAttempts) {
                    // Permanent failure
                    $connection->update($table, [
                        'status' => self::STATUS_FAILED,
                        'attempts' => $attempts,
                        'error_message' => $e->getMessage(),
                        'processed_at' => $now,
                    ], ['outbox_id = ?' => $outboxId]);

                    $this->logger->error('Outbox event permanently failed after max attempts', [
                        'outbox_id' => $outboxId,
                        'event_name' => $entry['event_name'],
                        'attempts' => $attempts,
                        'error' => $e->getMessage(),
                    ]);

                    $result['failed']++;
                } else {
                    // Schedule retry with backoff
                    $backoffSeconds = self::BACKOFF_SECONDS[$attempts] ?? 3600;
                    $nextRetry = (new \DateTime("+{$backoffSeconds} seconds"))
                        ->format('Y-m-d H:i:s');

                    $connection->update($table, [
                        'status' => self::STATUS_PENDING,
                        'attempts' => $attempts,
                        'error_message' => $e->getMessage(),
                        'next_retry_at' => $nextRetry,
                    ], ['outbox_id = ?' => $outboxId]);

                    $this->logger->warning('Outbox replay failed, scheduled retry', [
                        'outbox_id' => $outboxId,
                        'event_name' => $entry['event_name'],
                        'attempt' => $attempts,
                        'next_retry_at' => $nextRetry,
                        'error' => $e->getMessage(),
                    ]);

                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    /**
     * Delete old completed/failed entries.
     *
     * @return int Number of rows deleted
     */
    public function cleanup(int $completedDays = 7, int $failedDays = 30): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $completedThreshold = (new \DateTime("-{$completedDays} days"))->format('Y-m-d H:i:s');
        $failedThreshold = (new \DateTime("-{$failedDays} days"))->format('Y-m-d H:i:s');

        $deletedCompleted = $connection->delete($table, [
            'status = ?' => self::STATUS_COMPLETED,
            'processed_at <= ?' => $completedThreshold,
        ]);

        $deletedFailed = $connection->delete($table, [
            'status = ?' => self::STATUS_FAILED,
            'processed_at <= ?' => $failedThreshold,
        ]);

        return $deletedCompleted + $deletedFailed;
    }

    /**
     * Get counts by status for monitoring.
     *
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, ['status', 'cnt' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
            ->group('status');

        $rows = $connection->fetchPairs($select);

        return [
            self::STATUS_PENDING => (int)($rows[self::STATUS_PENDING] ?? 0),
            self::STATUS_PROCESSING => (int)($rows[self::STATUS_PROCESSING] ?? 0),
            self::STATUS_COMPLETED => (int)($rows[self::STATUS_COMPLETED] ?? 0),
            self::STATUS_FAILED => (int)($rows[self::STATUS_FAILED] ?? 0),
        ];
    }

    /**
     * Get the age of the oldest pending entry.
     */
    public function getOldestPendingAge(): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, ['created_at'])
            ->where('status = ?', self::STATUS_PENDING)
            ->order('created_at ASC')
            ->limit(1);

        $oldest = $connection->fetchOne($select);

        return $oldest ?: null;
    }
}
