<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class EventDeduplicator
{
    private const TABLE_NAME = 'artlounge_bento_event_sent';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Atomically mark an event as sent. Returns true if this is the first send
     * (row inserted), false if already sent (duplicate, no insert).
     */
    public function tryMarkSent(int $quoteId, string $eventName): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        try {
            $affectedRows = $connection->insertOnDuplicate(
                $tableName,
                [
                    'quote_id' => $quoteId,
                    'event_name' => $eventName,
                    'sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                [] // empty update columns = INSERT IGNORE behavior
            );

            // 1 = inserted (first time), 0 = duplicate (already sent)
            return $affectedRows > 0;
        } catch (\Exception $e) {
            $this->logger->debug('Event dedup insert failed', [
                'quote_id' => $quoteId,
                'event_name' => $eventName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Cleanup old dedup entries.
     */
    public function cleanup(int $maxAgeDays = 30): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
        $cutoff = (new \DateTimeImmutable('-' . $maxAgeDays . ' days'))->format('Y-m-d H:i:s');

        return (int)$connection->delete($tableName, ['sent_at < ?' => $cutoff]);
    }
}
