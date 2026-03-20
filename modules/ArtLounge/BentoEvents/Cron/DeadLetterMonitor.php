<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoEvents\Model\DeadLetter\Monitor;
use Psr\Log\LoggerInterface;

class DeadLetterMonitor
{
    public function __construct(
        private readonly Monitor $monitor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->monitor->checkQueueDepth();
        } catch (\Exception $e) {
            $this->logger->error('Dead-letter monitor cron failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
