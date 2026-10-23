<?php
/**
 * Re-pushes profile fields (incl. next_pick_*) via /batch/subscribers so the picks follow stock and sales
 * without anyone editing the emails. Nightly cron: every buyer with a counted order in the last 30 days.
 * CLI (bento:next-picks): one email, or the recent buyers. Obeys the configured test-mode allow-list.
 * A failed batch is logged and skipped; the next night retries it.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Picks;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;
use ArtLounge\BentoEvents\Model\Profile\ProfileLoader;

class Refresh
{
    public const BATCH_SIZE = 1000; // /batch/subscribers maximum

    public function __construct(
        private readonly ProfileLoader $loader,
        private readonly ProfileFieldsProvider $provider,
        private readonly BentoClientInterface $client,
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * @param string|null $email one customer instead of the recent buyers
     * @param callable|null $progress fn(string $message)
     * @return array{eligible:int, filtered_out:int, sent:int, batches:int, failed_batches:int, sample:array, error:?string}
     */
    public function run(int $days, ?string $email = null, bool $dryRun = false, int $batchSize = self::BATCH_SIZE, ?callable $progress = null): array
    {
        $emails = $email !== null && $email !== ''
            ? [strtolower(trim($email))]
            : $this->loader->buyerEmailsSince(gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400));
        $testMode = $this->config->getTestMode();
        $selected = array_values(array_filter($emails, static fn (string $e) => $testMode->allows($e)));
        $report = [
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
}
