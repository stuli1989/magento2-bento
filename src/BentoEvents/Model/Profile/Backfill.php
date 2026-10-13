<?php
/**
 * One-time push of profile fields for every email with ≥1 counted order via /batch/subscribers.
 * Batches of 1000 (≈12 calls for ~12K buyers). Resumable: the last email of each successful
 * batch is saved in a flag; --resume continues after it. Obeys --email-pattern AND the
 * configured test-mode allow-list.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Profile;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\TestMode;
use Magento\Framework\FlagManager;

class Backfill
{
    public const CURSOR_FLAG = 'artlounge_bento_backfill_cursor';

    public function __construct(
        private readonly ProfileLoader $loader,
        private readonly ProfileFieldsProvider $provider,
        private readonly BentoClientInterface $client,
        private readonly ConfigInterface $config,
        private readonly FlagManager $flags
    ) {
    }

    /**
     * @param callable|null $progress fn(string $message)
     * @return array{eligible:int, filtered_out:int, sent:int, batches:int, sample:array, error:?string}
     */
    public function run(?string $emailPattern, bool $dryRun, int $batchSize = 1000, bool $resume = false, ?callable $progress = null): array
    {
        $pattern = $emailPattern !== null && $emailPattern !== '' ? new TestMode([$emailPattern]) : null;
        $testMode = $this->config->getTestMode();
        $cursor = $resume ? (string)($this->flags->getFlagData(self::CURSOR_FLAG) ?? '') : '';
        $report = ['eligible' => 0, 'filtered_out' => 0, 'sent' => 0, 'batches' => 0, 'sample' => [], 'error' => null];

        while (true) {
            $emails = $this->loader->buyerEmailsAfter($cursor, $batchSize);
            if (!$emails) {
                break;
            }
            $lastInBatch = end($emails);
            $selected = array_values(array_filter(
                $emails,
                static fn (string $e) => ($pattern === null || $pattern->allows($e)) && $testMode->allows($e)
            ));
            $report['filtered_out'] += count($emails) - count($selected);
            $report['eligible'] += count($selected);

            if ($selected) {
                $records = array_values($this->provider->subscriberRecords($selected));
                if (count($report['sample']) < 3) {
                    $report['sample'] = array_slice(array_merge($report['sample'], $records), 0, 3);
                }
                if (!$dryRun) {
                    $result = $this->client->importSubscribers($records);
                    $report['batches']++;
                    if (!$result->isSuccess()) {
                        $report['error'] = $result->describe();
                        $progress && $progress('Batch failed: ' . $report['error'] . ' — rerun with --resume');
                        return $report;
                    }
                    $report['sent'] += count($records);
                }
            }
            $cursor = $lastInBatch;
            if (!$dryRun) {
                $this->flags->saveFlag(self::CURSOR_FLAG, $cursor);
            }
            $progress && $progress(sprintf('… up to %s: %d eligible so far', $cursor, $report['eligible']));
            if (count($emails) < $batchSize) {
                break;
            }
        }

        if (!$dryRun) {
            $this->flags->deleteFlag(self::CURSOR_FLAG);
        }

        return $report;
    }
}
