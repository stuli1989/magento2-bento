<?php
/**
 * Outbox Writer
 *
 * Persists failed AMQP publishes to the outbox table for later replay.
 * Called from observer catch blocks when publisher->publish() throws.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Outbox;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class Writer
{
    private const TABLE = 'artlounge_bento_event_outbox';
    private const MAX_ATTEMPTS = 5;
    private const FIRST_RETRY_DELAY_SECONDS = 60;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Save a failed event to the outbox for later replay.
     *
     * This method has its own try-catch — if the DB insert fails (e.g., DB also down),
     * it logs the error and returns gracefully. Never crashes the storefront.
     */
    public function save(string $eventName, string $arguments, int $storeId = 0): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::TABLE);

            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $nextRetry = (new \DateTime('+' . self::FIRST_RETRY_DELAY_SECONDS . ' seconds'))->format('Y-m-d H:i:s');

            $connection->insert($table, [
                'event_name' => $eventName,
                'arguments' => $arguments,
                'store_id' => $storeId,
                'status' => Processor::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'created_at' => $now,
                'next_retry_at' => $nextRetry,
            ]);

            $this->logger->info('Event saved to outbox for replay', [
                'event_name' => $eventName,
                'store_id' => $storeId,
                'outbox_id' => $connection->lastInsertId($table),
            ]);
        } catch (\Exception $e) {
            // Last resort — if even the outbox write fails, we can only log it
            $this->logger->critical('Failed to save event to outbox — event is LOST', [
                'event_name' => $eventName,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
