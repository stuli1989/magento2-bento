<?php
/**
 * Identified cart idle ≥ cart_delay, total ≥ min_value, no order, not already covered by a
 * $checkout_abandoned or $cart_abandoned for the same quote in the last 24h → $cart_abandoned.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Abandonment;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Outbox\OutboxStoreInterface;
use Psr\Log\LoggerInterface;

class IdleCartProcessor
{
    public function __construct(
        private readonly AbandonmentQueriesInterface $queries,
        private readonly OutboxStoreInterface $outbox,
        private readonly Enqueuer $enqueuer,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return int carts queued */
    public function run(): int
    {
        if (!$this->config->isAbandonedCartEnabled()) {
            return 0;
        }
        $idleBefore = gmdate('Y-m-d H:i:s', time() - $this->config->getCartDelayMinutes() * 60);
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $queued = 0;

        foreach ($this->queries->idleCarts($idleBefore, $this->config->getCartMinValue()) as $cart) {
            try {
                $quoteId = (int)$cart['quote_id'];
                if ($this->outbox->existsWithPrefixSince('checkout_abandoned:' . $quoteId . ':', $since)
                    || $this->outbox->existsWithPrefixSince('cart_abandoned:' . $quoteId . ':', $since)) {
                    continue;
                }
                $updated = strtotime($cart['updated_at'] . ' UTC') ?: time();
                if ($this->enqueuer->enqueue(
                    'cart_abandoned',
                    'quote',
                    $quoteId,
                    (string)$cart['email'],
                    (int)$cart['store_id'],
                    'cart_abandoned:' . $quoteId . ':' . $updated
                )) {
                    $queued++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Bento idle cart check failed', ['quote_id' => $cart['quote_id'] ?? null, 'error' => $e->getMessage()]);
            }
        }

        return $queued;
    }
}
