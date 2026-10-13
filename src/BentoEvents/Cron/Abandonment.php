<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Abandonment\ContactTimeoutProcessor;
use ArtLounge\BentoEvents\Model\Abandonment\IdleCartProcessor;
use Psr\Log\LoggerInterface;

/**
 * artlounge_bento_abandonment — every 5 minutes: contact-capture timeouts, then idle carts.
 */
class Abandonment
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ContactTimeoutProcessor $contacts,
        private readonly IdleCartProcessor $carts,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        try {
            $c = $this->contacts->run();
            $q = $this->carts->run();
            if ($c['enqueued'] + $c['cancelled'] + $q > 0) {
                $this->logger->info('Bento abandonment run', $c + ['carts_queued' => $q]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bento abandonment run failed', ['error' => $e->getMessage()]);
        }
    }
}
