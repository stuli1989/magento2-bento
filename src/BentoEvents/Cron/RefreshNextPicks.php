<?php
/**
 * artlounge_bento_next_picks — nightly: refresh next_pick_* for every buyer of the last 30 days.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Picks\Refresh;
use Psr\Log\LoggerInterface;

class RefreshNextPicks
{
    public const DAYS = 30;

    public function __construct(
        private readonly Refresh $refresh,
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
            $report = $this->refresh->run(self::DAYS);
            unset($report['sample']); // holds customer emails
            $this->logger->info('Bento next picks refreshed', $report);
        } catch (\Throwable $e) {
            $this->logger->error('Bento next picks refresh failed', ['error' => $e->getMessage()]);
        }
    }
}
