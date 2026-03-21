<?php
/**
 * Abandoned Cart Scheduler
 *
 * Schedules quotes for abandoned cart checking.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\AbandonedCart;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class Scheduler
{
    private const TABLE_NAME = 'artlounge_bento_abandoned_cart_schedule';
    private const QUEUE_TOPIC = 'artlounge.abandoned.cart';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Schedule a quote for abandoned cart check
     */
    public function scheduleCheck(Quote $quote): void
    {
        $storeId = (int)$quote->getStoreId();
        $processingMethod = $this->config->getAbandonedCartProcessingMethod($storeId);
        $delayMinutes = $this->config->getAbandonedCartDelay($storeId);

        if ($processingMethod === 'queue') {
            $this->scheduleViaQueue($quote, $delayMinutes);
        } else {
            $this->scheduleViaCron($quote, $delayMinutes);
        }
    }

    /**
     * Schedule via message queue with TTL
     *
     * Also records in schedule table for duplicate prevention and status tracking.
     */
    private function scheduleViaQueue(Quote $quote, int $delayMinutes): void
    {
        $quoteId = (int)$quote->getId();

        // Record in schedule table for duplicate prevention (same as cron mode)
        // This ensures we can track "sent" status even when using queue processing
        $this->recordSchedule($quote, $delayMinutes);

        $message = [
            'quote_id' => $quoteId,
            'scheduled_at' => $this->dateTime->gmtTimestamp(),
            'check_after' => $this->dateTime->gmtTimestamp() + ($delayMinutes * 60),
            'quote_updated_at' => $quote->getUpdatedAt()
        ];

        // Publish to delayed queue
        $this->publisher->publish(
            self::QUEUE_TOPIC,
            $this->serializer->serialize($message)
        );
    }

    /**
     * Record schedule in database for tracking and duplicate prevention
     */
    private function recordSchedule(Quote $quote, int $delayMinutes): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        $checkAt = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            strtotime("+{$delayMinutes} minutes")
        );

        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle existing entries
        $connection->insertOnDuplicate(
            $tableName,
            [
                'quote_id' => (int)$quote->getId(),
                'store_id' => (int)$quote->getStoreId(),
                'customer_email' => $this->normalizeEmail((string)$quote->getCustomerEmail()),
                'grand_total' => (float)$quote->getGrandTotal(),
                'scheduled_at' => $this->dateTime->gmtDate(),
                'check_at' => $checkAt,
                'quote_updated_at' => $quote->getUpdatedAt(),
                'status' => 'pending',
                'attempts' => 0
            ],
            ['scheduled_at', 'check_at', 'quote_updated_at', 'grand_total']
        );
    }

    /**
     * Schedule via database for cron processing
     */
    private function scheduleViaCron(Quote $quote, int $delayMinutes): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        $checkAt = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            strtotime("+{$delayMinutes} minutes")
        );

        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle existing entries
        $connection->insertOnDuplicate(
            $tableName,
            [
                'quote_id' => (int)$quote->getId(),
                'store_id' => (int)$quote->getStoreId(),
                'customer_email' => $this->normalizeEmail((string)$quote->getCustomerEmail()),
                'grand_total' => (float)$quote->getGrandTotal(),
                'scheduled_at' => $this->dateTime->gmtDate(),
                'check_at' => $checkAt,
                'quote_updated_at' => $quote->getUpdatedAt(),
                'status' => 'pending',
                'attempts' => 0
            ],
            ['scheduled_at', 'check_at', 'quote_updated_at', 'grand_total', 'status']
        );
    }

    /**
     * Mark a scheduled cart as processed
     */
    public function markProcessed(int $quoteId, string $status = 'sent'): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
        $updateData = [
            'status' => $status,
            'processed_at' => $this->dateTime->gmtDate(),
            'attempts' => new \Magento\Framework\DB\Sql\Expression('attempts + 1')
        ];

        if ($status === 'sent') {
            $updateData['last_sent_at'] = $this->dateTime->gmtDate();
        }

        $connection->update(
            $tableName,
            $updateData,
            ['quote_id = ?' => $quoteId]
        );
    }

    /**
     * Check if an abandoned cart event was already sent for this quote
     *
     * @param int $quoteId
     * @return bool
     */
    public function isAlreadySent(int $quoteId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        $select = $connection->select()
            ->from($tableName, ['status'])
            ->where('quote_id = ?', $quoteId)
            ->where('status = ?', 'sent')
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * Check if a sent abandoned-cart event exists for this customer within the suppression window.
     */
    public function hasRecentSentForCustomer(string $customerEmail, int $storeId, int $windowHours): bool
    {
        $normalizedEmail = $this->normalizeEmail($customerEmail);
        if ($normalizedEmail === '' || $windowHours < 1) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        $cutoff = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            strtotime("-{$windowHours} hours")
        );

        $select = $connection->select()
            ->from($tableName, ['schedule_id'])
            ->where('store_id = ?', $storeId)
            ->where('customer_email = ?', $normalizedEmail)
            ->where('last_sent_at IS NOT NULL')
            ->where('last_sent_at >= ?', $cutoff)
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * Claim and return pending scheduled carts for processing.
     *
     * Uses atomic UPDATE to transition pending → processing, preventing
     * concurrent cron runs from double-processing the same carts.
     */
    public function getPendingCarts(int $limit = 100): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
        $now = $this->dateTime->gmtDate();

        // Reset stuck entries (processing for > 10 min = likely crashed process).
        // Uses processed_at which is set when claiming rows for processing.
        $stuckThreshold = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime('-10 minutes'));
        $connection->update(
            $tableName,
            ['status' => 'pending'],
            [
                'status = ?' => 'processing',
                'processed_at <= ?' => $stuckThreshold,
            ]
        );

        // Atomically claim: UPDATE pending → processing (limited batch)
        // Get IDs first, then claim them
        $select = $connection->select()
            ->from($tableName, ['schedule_id'])
            ->where('status = ?', 'pending')
            ->where('check_at <= ?', $now)
            ->order('check_at ASC')
            ->limit($limit);

        $ids = $connection->fetchCol($select);

        if (empty($ids)) {
            return [];
        }

        // Set processed_at when claiming so stuck detection uses actual processing start time
        $claimed = $connection->update(
            $tableName,
            ['status' => 'processing', 'processed_at' => $now],
            [
                'schedule_id IN (?)' => $ids,
                'status = ?' => 'pending',
            ]
        );

        if ($claimed === 0) {
            return [];
        }

        // Fetch the rows we claimed
        $claimedSelect = $connection->select()
            ->from($tableName)
            ->where('schedule_id IN (?)', $ids)
            ->where('status = ?', 'processing');

        return $connection->fetchAll($claimedSelect);
    }

    /**
     * Cancel a pending schedule when a quote becomes ineligible.
     */
    public function cancelPending(int $quoteId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        $connection->update(
            $tableName,
            [
                'status' => 'ineligible',
                'processed_at' => $this->dateTime->gmtDate(),
            ],
            [
                'quote_id = ?' => $quoteId,
                'status = ?' => 'pending',
            ]
        );
    }

    /**
     * Clean up old scheduled entries
     */
    public function cleanup(int $daysOld = 7): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        $cutoff = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            strtotime("-{$daysOld} days")
        );

        return $connection->delete(
            $tableName,
            [
                'scheduled_at < ?' => $cutoff,
                'status IN (?)' => ['sent', 'converted', 'ordered', 'not_found', 'no_email', 'duplicate_window', 'ineligible']
            ]
        );
    }

    /**
     * Normalize customer email for consistent lookups.
     */
    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
