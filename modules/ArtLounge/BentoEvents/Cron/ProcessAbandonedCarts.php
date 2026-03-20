<?php
/**
 * Process Abandoned Carts Cron Job
 *
 * Processes scheduled abandoned cart checks via cron.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\AbandonedCart\Checker;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use Psr\Log\LoggerInterface;

class ProcessAbandonedCarts
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Scheduler $scheduler,
        private readonly Checker $checker,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Execute cron job
     */
    public function execute(): void
    {
        if (!$this->config->isAbandonedCartEnabled()) {
            return;
        }

        try {
            $pendingCarts = $this->scheduler->getPendingCarts(self::BATCH_SIZE);

            $processed = 0;
            $triggered = 0;

            foreach ($pendingCarts as $cart) {
                $result = $this->checker->checkAndTrigger(
                    (int)$cart['quote_id'],
                    $cart['quote_updated_at']
                );

                $processed++;
                if ($result) {
                    $triggered++;
                }
            }

            if ($processed > 0) {
                $this->logger->info('Processed abandoned carts', [
                    'total' => $processed,
                    'triggered' => $triggered
                ]);
            }

            // Cleanup old entries
            $cleaned = $this->scheduler->cleanup(7);
            if ($cleaned > 0) {
                $this->logger->debug('Cleaned up old abandoned cart entries', [
                    'count' => $cleaned
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to process abandoned carts', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
