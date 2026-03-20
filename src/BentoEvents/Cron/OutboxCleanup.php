<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoEvents\Model\Outbox\Processor;
use Psr\Log\LoggerInterface;

class OutboxCleanup
{
    public function __construct(
        private readonly Processor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $deleted = $this->processor->cleanup(7, 30);

            if ($deleted > 0) {
                $this->logger->debug('Outbox cleanup: deleted old entries', [
                    'count' => $deleted,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Outbox cleanup cron failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
