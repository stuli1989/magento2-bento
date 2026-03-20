<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoEvents\Model\Outbox\Processor;
use Psr\Log\LoggerInterface;

class OutboxReplay
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly Processor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $result = $this->processor->processQueue(self::BATCH_SIZE);

            if ($result['processed'] > 0 || $result['failed'] > 0) {
                $this->logger->info('Outbox replay cron completed', $result);
            }
        } catch (\Exception $e) {
            $this->logger->error('Outbox replay cron failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
