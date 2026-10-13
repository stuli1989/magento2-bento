<?php
/**
 * Drains the outbox: claim → test-mode filter → build → (import subscriber) → post event → classify.
 *
 * 2xx sent · 4xx parked (body kept) · 5xx/timeout/429 retry 1m,5m,30m,2h,12h → parked.
 * Runs under a DB named lock so overlapping cron runs never double-send.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Outbox;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\SendResult;
use ArtLounge\BentoEvents\Model\Payload\BuilderPool;
use Psr\Log\LoggerInterface;

class Sender
{
    private const STUCK_MINUTES = 10;

    public function __construct(
        private readonly OutboxStoreInterface $store,
        private readonly BuilderPool $builders,
        private readonly BentoClientInterface $client,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{sent:int, skipped:int, retry:int, parked:int, locked:bool, not_configured:bool}
     */
    public function run(int $maxSeconds = 30, int $maxRows = 100): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'retry' => 0, 'parked' => 0, 'locked' => false, 'not_configured' => false];

        if (!$this->config->isConfigured()) {
            $stats['not_configured'] = true;
            return $stats;
        }
        if (!$this->store->acquireLock()) {
            $stats['locked'] = true;
            return $stats;
        }

        try {
            $this->store->resetStuck(gmdate('Y-m-d H:i:s', time() - self::STUCK_MINUTES * 60));
            $deadline = microtime(true) + $maxSeconds;
            $done = 0;
            while ($done < $maxRows && microtime(true) < $deadline) {
                $row = $this->store->claimNext(gmdate('Y-m-d H:i:s'));
                if ($row === null) {
                    break;
                }
                $stats[$this->process($row)]++;
                $done++;
            }
        } finally {
            $this->store->releaseLock();
        }

        return $stats;
    }

    /**
     * Send one specific row now (CLI replay/run --id). Ignores next_attempt_at.
     * @return string outcome: sent|skipped|retry|parked
     */
    public function sendRow(OutboxRow $row): string
    {
        return $this->process($row);
    }

    private function process(OutboxRow $row): string
    {
        $testMode = $this->config->getTestMode();
        if (!$testMode->allows($row->email)) {
            $this->store->markSkipped($row->id, 'test mode: email not in allow-list');
            return 'skipped';
        }

        $builder = $this->builders->get($row->eventType);
        if ($builder === null) {
            $this->store->markParked($row->id, $row->attempts, 'No payload builder for event type ' . $row->eventType);
            return 'parked';
        }

        try {
            $built = $builder->build($row);
        } catch (\Throwable $e) {
            return $this->fail($row, new SendResult(0, '', 'build failed: ' . $e->getMessage()));
        }

        if ($built->isSkip()) {
            $this->store->markSkipped($row->id, (string)$built->skipReason);
            return 'skipped';
        }

        $storeId = $row->storeId ?: null;
        if ($built->subscriber !== null) {
            $result = $this->client->importSubscribers([$built->subscriber], $storeId);
            if (!$result->isSuccess()) {
                return $this->fail($row, $result);
            }
        }

        $result = $this->client->postEvents([$built->event], $storeId);
        if (!$result->isSuccess()) {
            return $this->fail($row, $result);
        }

        $this->store->markSent($row->id, gmdate('Y-m-d H:i:s'));
        $this->config->debug('Outbox sent', ['id' => $row->id, 'type' => $row->eventType]);

        return 'sent';
    }

    private function fail(OutboxRow $row, SendResult $result): string
    {
        $attempts = $row->attempts + 1;
        $error = $result->describe();

        if ($result->isPermanent()) {
            $this->store->markParked($row->id, $attempts, $error);
            $this->logger->error('Bento outbox row parked (permanent error)', ['id' => $row->id, 'error' => $error]);
            return 'parked';
        }

        $delay = Backoff::nextDelay($attempts);
        if ($delay === null) {
            $this->store->markParked($row->id, $attempts, $error);
            $this->logger->error('Bento outbox row parked after retries', ['id' => $row->id, 'error' => $error]);
            return 'parked';
        }

        $this->store->markRetry($row->id, $attempts, gmdate('Y-m-d H:i:s', time() + $delay), $error);
        $this->logger->warning('Bento outbox row will retry', ['id' => $row->id, 'in_seconds' => $delay, 'error' => $error]);

        return 'retry';
    }
}
