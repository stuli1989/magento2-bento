<?php
/**
 * Abandoned Cart Queue Consumer
 *
 * Processes abandoned cart check messages from the queue.
 *
 * IMPORTANT: Queue-based processing does NOT support true delayed delivery
 * unless RabbitMQ is configured with the x-delayed-message plugin or a
 * dead-letter exchange with TTL. Without these, messages are processed
 * immediately when consumed.
 *
 * For reliable delayed processing, use processing_method="cron" in admin
 * configuration. The cron method uses the database schedule table and
 * properly respects the configured delay.
 *
 * When queue mode is used:
 * - Messages include a check_after timestamp
 * - If the delay hasn't elapsed, the message is DROPPED (not requeued)
 *   to avoid busy-loop CPU churn
 * - The cart will still be processed by the cron job if it remains abandoned
 *   (since scheduleViaQueue also records to the schedule table)
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\AbandonedCart;

use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class Consumer
{
    public function __construct(
        private readonly Checker $checker,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process abandoned cart check message
     *
     * Message format:
     * {
     *   "quote_id": 12345,
     *   "scheduled_at": 1706123456,
     *   "check_after": 1706127056,
     *   "quote_updated_at": "2026-01-24 10:30:00"
     * }
     *
     * @param string $message JSON-encoded message
     * @return void
     */
    public function process(string $message): void
    {
        try {
            $data = $this->serializer->unserialize($message);

            if (!isset($data['quote_id'])) {
                $this->logger->error('Abandoned cart message missing quote_id', ['message' => $message]);
                return;
            }

            $quoteId = (int)$data['quote_id'];
            $checkAfter = $data['check_after'] ?? 0;
            $originalUpdatedAt = $data['quote_updated_at'] ?? null;
            $currentTime = $this->dateTime->gmtTimestamp();

            // Check if delay period has elapsed
            if ($checkAfter > $currentTime) {
                $remainingSeconds = $checkAfter - $currentTime;

                // DO NOT republish - that creates a busy-loop without true delayed queue support.
                // The cart is also recorded in the schedule table, so cron will pick it up
                // if it remains abandoned after the delay period.
                $this->logger->warning(
                    'Abandoned cart message arrived before check_after time. ' .
                    'Queue processing without delayed message support is not recommended. ' .
                    'Consider using processing_method="cron" instead.',
                    [
                        'quote_id' => $quoteId,
                        'check_after' => date('Y-m-d H:i:s', $checkAfter),
                        'current_time' => date('Y-m-d H:i:s', $currentTime),
                        'remaining_seconds' => $remainingSeconds
                    ]
                );

                // Message is dropped - cron fallback will handle it via schedule table
                return;
            }

            $this->logger->debug('Processing abandoned cart check', [
                'quote_id' => $quoteId,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'check_after' => $checkAfter
            ]);

            // Delegate to Checker which handles all validation and event triggering
            $triggered = $this->checker->checkAndTrigger($quoteId, $originalUpdatedAt);

            $this->logger->debug('Abandoned cart check completed', [
                'quote_id' => $quoteId,
                'event_triggered' => $triggered
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process abandoned cart message', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            // Don't rethrow - message will be acknowledged and removed from queue
            // The error is logged for debugging
        }
    }
}
