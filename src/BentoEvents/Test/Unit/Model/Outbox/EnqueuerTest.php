<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Outbox;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Test\Unit\Fake\InMemoryOutboxStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EnqueuerTest extends TestCase
{
    private InMemoryOutboxStore $store;
    private Enqueuer $enqueuer;
    private LoggerInterface $logger;
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->store = new InMemoryOutboxStore();
        $this->logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
        $this->enqueuer = new Enqueuer($this->store, $config, $this->logger);
    }

    public function testInsertsNormalisedRow(): void
    {
        $ok = $this->enqueuer->enqueue('purchase', 'order', 45, '  Kshitij+BentoTest@Gmail.com ', 1, 'purchase:100045', ['a' => 1]);

        $this->assertTrue($ok);
        $row = $this->store->rows[1];
        $this->assertSame('kshitij+bentotest@gmail.com', $row['email']);
        $this->assertSame('purchase', $row['event_type']);
        $this->assertSame('order', $row['entity_type']);
        $this->assertSame(45, $row['entity_id']);
        $this->assertSame('purchase:100045', $row['dedup_key']);
        $this->assertSame('{"a":1}', $row['context']);
        $this->assertSame('pending', $row['status']);
    }

    public function testDuplicateDedupKeyIsIgnored(): void
    {
        $this->assertTrue($this->enqueuer->enqueue('purchase', 'order', 45, 'a@b.c', 1, 'purchase:1'));
        $this->assertFalse($this->enqueuer->enqueue('purchase', 'order', 45, 'a@b.c', 1, 'purchase:1'));
        $this->assertCount(1, $this->store->rows);
    }

    public function testEmptyEmailIsNotQueued(): void
    {
        $this->assertFalse($this->enqueuer->enqueue('purchase', 'order', 45, '  ', 1, 'purchase:1'));
        $this->assertCount(0, $this->store->rows);
    }

    public function testDatabaseFailureIsSwallowedAndLogged(): void
    {
        $this->store->throwOnInsert = true;
        $this->logger->expects($this->once())->method('error');

        $this->assertFalse($this->enqueuer->enqueue('purchase', 'order', 45, 'a@b.c', 1, 'purchase:1'));
    }

    public function testDisabledIntegrationQueuesNothing(): void
    {
        $this->enabled = false;
        $this->assertFalse($this->enqueuer->enqueue('purchase', 'order', 45, 'a@b.c', 1, 'purchase:1'));
        $this->assertCount(0, $this->store->rows);
    }

    public function testDelaySetsNextAttempt(): void
    {
        $this->enqueuer->enqueue('x', 'quote', 1, 'a@b.c', 1, 'x:1', [], 120);
        $next = strtotime($this->store->rows[1]['next_attempt_at'] . ' UTC');
        $this->assertEqualsWithDelta(time() + 120, $next, 5);
    }
}
