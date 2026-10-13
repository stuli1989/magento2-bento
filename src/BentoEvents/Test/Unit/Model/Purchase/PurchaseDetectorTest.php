<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Purchase;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Purchase\PurchaseDetector;
use ArtLounge\BentoEvents\Model\Purchase\PurchaseMarkerStoreInterface;
use ArtLounge\BentoEvents\Test\Unit\Fake\InMemoryOutboxStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurchaseDetectorTest extends TestCase
{
    private InMemoryOutboxStore $outbox;
    private object $markers;
    private PurchaseDetector $detector;

    protected function setUp(): void
    {
        $this->outbox = new InMemoryOutboxStore();
        $this->markers = new class implements PurchaseMarkerStoreInterface {
            public array $rows = [];
            public bool $throw = false;

            public function claim(int $orderId, string $incrementId, string $email, string $purchasedAtUtc): bool
            {
                if ($this->throw) {
                    throw new \RuntimeException('db');
                }
                if (isset($this->rows[$orderId])) {
                    return false;
                }
                $this->rows[$orderId] = $purchasedAtUtc;
                return true;
            }

            public function exists(int $orderId): bool
            {
                return isset($this->rows[$orderId]);
            }
        };
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $this->detector = new PurchaseDetector($this->markers, new Enqueuer($this->outbox, $config, $logger), $logger);
    }

    private function order(string $state, string $createdAt = 'now'): array
    {
        return [
            'order_id' => 45, 'increment_id' => '100045', 'email' => 'A+bentotest@x.com', 'store_id' => 1,
            'state' => $state, 'created_at' => gmdate('Y-m-d H:i:s', strtotime($createdAt)),
        ];
    }

    private function types(): array
    {
        return array_values(array_map(fn ($r) => $r['dedup_key'], $this->outbox->rows));
    }

    public function testCodOrderGoingStraightToProcessingIsAPurchase(): void
    {
        $this->detector->handle($this->order('new'));
        $this->detector->handle($this->order('processing'));
        $this->assertSame(['purchase:100045'], $this->types());
        $this->assertSame('a+bentotest@x.com', $this->outbox->rows[1]['email']);
    }

    public function testPendingPaymentIsNeverAPurchase(): void
    {
        $this->detector->handle($this->order('pending_payment'));
        $this->assertSame([], $this->types());
    }

    public function testLatePaymentFiresExactlyOnce(): void
    {
        $this->detector->handle($this->order('pending_payment'));
        $this->detector->handle($this->order('processing'));
        $this->detector->handle($this->order('processing'));
        $this->detector->handle($this->order('complete'));
        $this->assertSame(['purchase:100045'], $this->types());
    }

    public function testCancelWithoutPriorPurchaseFiresNothing(): void
    {
        $this->detector->handle($this->order('pending_payment'));
        $this->detector->handle($this->order('canceled'));
        $this->assertSame([], $this->types());
    }

    public function testCancelAfterPurchaseFiresCanceled(): void
    {
        $this->detector->handle($this->order('processing'));
        $this->detector->handle($this->order('canceled'));
        $this->detector->handle($this->order('canceled'));
        $this->assertSame(['purchase:100045', 'order_canceled:100045'], $this->types());
    }

    public function testHistoricOrderFirstSeenPaidAfterDeployIsIgnored(): void
    {
        $this->detector->handle($this->order('complete', '-10 days'));
        $this->assertSame([], $this->types());
        $this->assertFalse($this->markers->exists(45));
    }

    public function testSeededHistoricOrderNeverFiresPurchaseButCancelStillWorks(): void
    {
        $this->markers->rows[45] = '2026-01-01 00:00:00'; // seeded on install
        $this->detector->handle($this->order('complete'));
        $this->assertSame([], $this->types());
        $this->detector->handle($this->order('canceled'));
        $this->assertSame(['order_canceled:100045'], $this->types());
    }

    public function testFailuresNeverPropagate(): void
    {
        $this->markers->throw = true;
        $this->detector->handle($this->order('processing'));
        $this->assertSame([], $this->types());
    }

    public function testOrderWithoutEmailIsIgnored(): void
    {
        $o = $this->order('processing');
        $o['email'] = '';
        $this->detector->handle($o);
        $this->assertSame([], $this->types());
        $this->assertFalse($this->markers->exists(45), 'no marker for an order we could not announce');
    }
}
