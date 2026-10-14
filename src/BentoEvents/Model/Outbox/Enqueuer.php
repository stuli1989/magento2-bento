<?php
/**
 * The only way anything gets to Bento: one ~1ms INSERT IGNORE into the outbox.
 *
 * Called from observers (after commit), Razorpay controller plugins and the
 * contact endpoint — i.e. inside customer requests. It must NEVER throw.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Outbox;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Psr\Log\LoggerInterface;

class Enqueuer
{
    public function __construct(
        private readonly OutboxStoreInterface $store,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * $email may be '' only when $context['bento_uuid'] is set: the recipient is then resolved from
     * Bento at send time (guest $review_submitted).
     *
     * @return bool true when a new row was queued (false: duplicate, disabled, no identity, or error)
     */
    public function enqueue(
        string $eventType,
        string $entityType,
        int $entityId,
        string $email,
        int $storeId,
        string $dedupKey,
        array $context = [],
        int $delaySeconds = 0
    ): bool {
        try {
            $email = strtolower(trim($email));
            $resolvable = $email === '' && is_string($context['bento_uuid'] ?? null) && $context['bento_uuid'] !== '';
            if (($email === '' && !$resolvable) || !$this->config->isEnabled($storeId ?: null)) {
                return false;
            }
            $now = time();
            $inserted = $this->store->insert([
                'event_type' => $eventType,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'email' => mb_substr($email, 0, 255),
                'store_id' => $storeId,
                'dedup_key' => mb_substr($dedupKey, 0, 190),
                'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'status' => OutboxStoreInterface::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', $now + max(0, $delaySeconds)),
                'created_at' => gmdate('Y-m-d H:i:s', $now),
            ]);
            if ($inserted) {
                $this->config->debug('Outbox enqueued', ['type' => $eventType, 'dedup' => $dedupKey]);
            }

            return $inserted;
        } catch (\Throwable $e) {
            $this->logger->error('Bento outbox enqueue failed — event not queued', [
                'type' => $eventType,
                'dedup' => $dedupKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
