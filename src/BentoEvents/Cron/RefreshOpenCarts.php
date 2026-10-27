<?php
/**
 * artlounge_bento_open_carts — nightly: carts abandoned in the last 4 days get fresh pricing and coupon fields.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Pricing\OpenCartRefresh;
use Psr\Log\LoggerInterface;

class RefreshOpenCarts
{
    public function __construct(
        private readonly OpenCartRefresh $refresh,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }
        try {
            $this->logger->info('Bento open carts refreshed', $this->refresh->run());
        } catch (\Throwable $e) {
            $this->logger->error('Bento open carts refresh failed', ['error' => $e->getMessage()]);
        }
    }
}
