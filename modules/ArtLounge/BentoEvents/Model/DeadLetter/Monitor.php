<?php
/**
 * Dead-Letter Queue Monitor & Replay
 *
 * Reads messages from the Aligent framework's event.failover.deadletter queue
 * and re-publishes them to the retry queue for reprocessing.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\DeadLetter;

use Aligent\AsyncEvents\Helper\QueueMetadataInterface;
use Aligent\AsyncEvents\Service\AsyncEvent\AmqpPublisher;
use Aligent\AsyncEvents\Service\AsyncEvent\RetryManager;
use Magento\Framework\Amqp\Config as AmqpConfig;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class Monitor
{
    private const DEAD_LETTER_QUEUE = 'event.failover.deadletter';

    public function __construct(
        private readonly AmqpConfig $amqpConfig,
        private readonly AmqpPublisher $amqpPublisher,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Replay dead-lettered messages back to the retry queue.
     *
     * Reads messages from event.failover.deadletter and re-publishes them
     * to the event.retry.init routing key (event.delay.1 → event.failover.retry)
     * with death_count reset to 1 so the retry consumer gives it a fresh cycle.
     *
     * @return array{replayed: int, failed: int}
     */
    public function replay(int $limit = 50): array
    {
        $result = ['replayed' => 0, 'failed' => 0];

        try {
            $channel = $this->amqpConfig->getChannel();
        } catch (\Exception $e) {
            $this->logger->error('Cannot connect to AMQP for dead-letter replay', [
                'error' => $e->getMessage(),
            ]);
            return $result;
        }

        for ($i = 0; $i < $limit; $i++) {
            $message = $channel->basic_get(self::DEAD_LETTER_QUEUE);

            if ($message === null) {
                break; // Queue empty
            }

            try {
                $body = $this->json->unserialize($message->getBody());

                // Ensure required RetryHandler fields are present
                $subscriptionId = (int) ($body[RetryManager::SUBSCRIPTION_ID] ?? 0);
                $content = $body[RetryManager::CONTENT] ?? '[]';
                // Generate a UUID if missing (kill() omits it)
                $uuid = $body[RetryManager::UUID] ?? bin2hex(random_bytes(16));

                // Re-publish directly to event.failover.retry (routing key = event.retry)
                // Skip the delay queue — for manual replay we don't need the 1s delay,
                // and the delay queue may not exist (it's created dynamically with x-expires)
                // death_count starts at 1 so RetryHandler treats it as a first retry
                $this->amqpPublisher->publish(
                    QueueMetadataInterface::DEAD_LETTER_ROUTING_KEY,
                    [
                        RetryManager::SUBSCRIPTION_ID => $subscriptionId,
                        RetryManager::DEATH_COUNT     => 1,
                        RetryManager::CONTENT         => $content,
                        RetryManager::UUID            => $uuid,
                    ]
                );

                // Acknowledge the dead-letter message (remove from queue)
                $channel->basic_ack($message->getDeliveryTag());

                $this->logger->info('Dead-letter message replayed to retry queue', [
                    'subscription_id' => $subscriptionId,
                    'uuid'            => $uuid,
                    'delivery_tag'    => $message->getDeliveryTag(),
                ]);

                $result['replayed']++;

            } catch (\Exception $e) {
                // Reject but requeue so we don't lose the message
                $channel->basic_nack($message->getDeliveryTag(), false, true);

                $this->logger->error('Failed to replay dead-letter message', [
                    'delivery_tag' => $message->getDeliveryTag(),
                    'error'        => $e->getMessage(),
                ]);

                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * Check dead-letter queue depth and log warning if non-empty.
     *
     * @return int Number of messages in the dead-letter queue
     */
    public function checkQueueDepth(): int
    {
        try {
            $channel = $this->amqpConfig->getChannel();
            [$queueName, $messageCount, $consumerCount] = $channel->queue_declare(
                self::DEAD_LETTER_QUEUE,
                true,   // passive — just check, don't create
                false,
                false,
                false
            );

            if ($messageCount > 0) {
                $this->logger->warning('Dead-letter queue has unprocessed events', [
                    'queue'         => self::DEAD_LETTER_QUEUE,
                    'message_count' => $messageCount,
                    'action'        => 'Run: php bin/magento bento:deadletter:replay',
                ]);
            }

            return (int) $messageCount;
        } catch (\Exception $e) {
            $this->logger->error('Cannot check dead-letter queue depth', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
