<?php
/**
 * Re-pushes profile fields (incl. next_pick_*, new_in_*, usual_*) via /batch/subscribers so the lists
 * follow stock and sales without anyone editing the emails. Nightly cron: every buyer with a counted
 * order in the last 30 days PLUS every VIP (lifetime_spend ≥ ₹15,000 OR order_count ≥ 5 by default, the
 * StatsCalculator values), de-duplicated — VIPs often haven't ordered recently. CLI (bento:next-picks):
 * one email, the recent buyers, the VIPs, or both. Obeys the configured test-mode allow-list.
 * A failed batch is logged and skipped; the next night retries it.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Picks;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;
use ArtLounge\BentoEvents\Model\Profile\ProfileLoader;
use ArtLounge\BentoEvents\Model\Profile\StatsCalculator;

class Refresh
{
    public const BATCH_SIZE = 1000; // /batch/subscribers maximum

    public function __construct(
        private readonly ProfileLoader $loader,
        private readonly ProfileFieldsProvider $provider,
        private readonly StatsCalculator $calculator,
        private readonly BentoClientInterface $client,
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * @param string|null $email one customer instead of an audience
     * @param bool $recent buyers with a counted order in the last $days days
     * @param bool $vips every VIP, however long ago they ordered
     * @param callable|null $progress fn(string $message)
     * @return array{recent:int, vips:int, audience:int, eligible:int, filtered_out:int, sent:int, batches:int, failed_batches:int, sample:array, error:?string}
     */
    public function run(
        ?string $email,
        bool $recent,
        bool $vips,
        int $days,
        bool $dryRun = false,
        int $batchSize = self::BATCH_SIZE,
        ?callable $progress = null
    ): array {
        $recentEmails = $vipEmails = [];
        if ($email !== null && $email !== '') {
            $emails = [strtolower(trim($email))];
        } else {
            $recentEmails = $recent ? $this->loader->buyerEmailsSince(gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400)) : [];
            $vipEmails = $vips ? $this->vipEmails() : [];
            $emails = array_values(array_unique(array_merge($recentEmails, $vipEmails)));
        }
        $testMode = $this->config->getTestMode();
        $selected = array_values(array_filter($emails, static fn (string $e) => $testMode->allows($e)));
        $report = [
            'recent' => count($recentEmails), 'vips' => count($vipEmails), 'audience' => count($emails),
            'eligible' => count($selected), 'filtered_out' => count($emails) - count($selected),
            'sent' => 0, 'batches' => 0, 'failed_batches' => 0, 'sample' => [], 'error' => null,
        ];

        foreach (array_chunk($selected, max(1, min(self::BATCH_SIZE, $batchSize))) as $chunk) {
            $records = array_values($this->provider->subscriberRecords($chunk));
            if (count($report['sample']) < 3) {
                $report['sample'] = array_slice(array_merge($report['sample'], $records), 0, 3);
            }
            if ($dryRun) {
                continue;
            }
            $result = $this->client->importSubscribers($records);
            $report['batches']++;
            if ($result->isSuccess()) {
                $report['sent'] += count($records);
            } else {
                $report['failed_batches']++;
                $report['error'] = $result->describe();
            }
            $progress && $progress(sprintf('… %d sent, %d failed batches', $report['sent'], $report['failed_batches']));
        }

        return $report;
    }

    /** @return string[] buyers whose StatsCalculator lifetime_spend or order_count reaches the VIP thresholds */
    public function vipEmails(): array
    {
        $spend = $this->config->getVipLifetimeSpend();
        $count = $this->config->getVipOrderCount();
        $tree = $this->loader->categoryTree();
        $noAccount = ['is_registered' => false, 'created_at' => null];
        $out = [];
        $cursor = '';
        do {
            $emails = $this->loader->buyerEmailsAfter($cursor, self::BATCH_SIZE);
            $orders = $this->loader->orders($emails);
            foreach ($emails as $email) {
                $stats = $this->calculator->calculate($orders[$email] ?? [], [], $noAccount, $tree, []);
                if ($stats['lifetime_spend'] >= $spend || $stats['order_count'] >= $count) {
                    $out[] = $email;
                }
            }
            $cursor = (string)end($emails);
        } while (count($emails) === self::BATCH_SIZE);

        return $out;
    }
}
