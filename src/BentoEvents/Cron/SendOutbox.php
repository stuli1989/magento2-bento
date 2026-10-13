<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoEvents\Model\Outbox\Sender;
use Psr\Log\LoggerInterface;

/**
 * artlounge_bento_outbox_send — every minute. Idle run = one indexed SELECT.
 */
class SendOutbox
{
    public function __construct(
        private readonly Sender $sender,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $stats = $this->sender->run(30, 100);
            if ($stats['sent'] + $stats['retry'] + $stats['parked'] + $stats['skipped'] > 0) {
                $this->logger->info('Bento outbox run', $stats);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bento outbox run failed', ['error' => $e->getMessage()]);
        }
    }
}
