<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Outbox;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\SendResult;
use ArtLounge\BentoCore\Model\TestMode;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Outbox\Sender;
use ArtLounge\BentoEvents\Model\Payload\BuilderInterface;
use ArtLounge\BentoEvents\Model\Payload\BuilderPool;
use ArtLounge\BentoEvents\Model\Payload\BuildResult;
use ArtLounge\BentoEvents\Test\Unit\Fake\InMemoryOutboxStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SenderTest extends TestCase
{
    private InMemoryOutboxStore $store;
    private array $eventCalls = [];
    private array $subscriberCalls = [];
    /** @var SendResult[] */
    private array $eventResults = [];
    private ?SendResult $subscriberResult = null;
    private ?BuildResult $buildResult = null;
    private ?\Throwable $buildThrows = null;
    private bool $configured = true;
    private TestMode $testMode;

    protected function setUp(): void
    {
        $this->store = new InMemoryOutboxStore();
        $this->testMode = new TestMode([]);
    }

    private function sender(): Sender
    {
        $client = $this->createMock(BentoClientInterface::class);
        $client->method('postEvents')->willReturnCallback(function (array $events) {
            $this->eventCalls[] = $events;
            return array_shift($this->eventResults) ?? new SendResult(200, '{}', null);
        });
        $client->method('importSubscribers')->willReturnCallback(function (array $subs) {
            $this->subscriberCalls[] = $subs;
            return $this->subscriberResult ?? new SendResult(200, '{}', null);
        });

        $builder = new class ($this) implements BuilderInterface {
            public function __construct(private SenderTest $t)
            {
            }

            public function build(OutboxRow $row): BuildResult
            {
                return $this->t->buildFor($row);
            }
        };

        $config = $this->createMock(ConfigInterface::class);
        $config->method('isConfigured')->willReturnCallback(fn () => $this->configured);
        $config->method('getTestMode')->willReturnCallback(fn () => $this->testMode);

        return new Sender(
            $this->store,
            new BuilderPool(['purchase' => $builder]),
            $client,
            $config,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function buildFor(OutboxRow $row): BuildResult
    {
        if ($this->buildThrows) {
            throw $this->buildThrows;
        }
        return $this->buildResult ?? BuildResult::event(['type' => '$purchase', 'email' => $row->email]);
    }

    private function queue(string $email = 'a+bentotest@x.com', string $type = 'purchase'): int
    {
        $this->store->insert([
            'event_type' => $type, 'entity_type' => 'order', 'entity_id' => 1, 'email' => $email,
            'store_id' => 1, 'dedup_key' => uniqid($type, true), 'context' => null, 'next_attempt_at' => null,
        ]);
        return array_key_last($this->store->rows);
    }

    public function testSuccessMarksSent(): void
    {
        $id = $this->queue();
        $stats = $this->sender()->run();
        $this->assertSame('sent', $this->store->statusOf($id));
        $this->assertSame(1, $stats['sent']);
        $this->assertSame([[['type' => '$purchase', 'email' => 'a+bentotest@x.com']]], $this->eventCalls);
    }

    public function testTestModeSkipsNonMatchingEmailWithoutCallingBento(): void
    {
        $this->testMode = new TestMode(['*+bentotest*@*']);
        $real = $this->queue('real.customer@gmail.com');
        $test = $this->queue('kshitij+bentotest1@gmail.com');

        $this->sender()->run();

        $this->assertSame('skipped', $this->store->statusOf($real));
        $this->assertStringContainsString('test mode', $this->store->rows[$real]['last_error']);
        $this->assertSame('sent', $this->store->statusOf($test));
        $this->assertCount(1, $this->eventCalls);
    }

    public function testBuilderSkipReasonMarksSkipped(): void
    {
        $id = $this->queue();
        $this->buildResult = BuildResult::skip('order not found');
        $this->sender()->run();
        $this->assertSame('skipped', $this->store->statusOf($id));
        $this->assertSame([], $this->eventCalls);
    }

    public function testPermanentFailureParksImmediatelyWithBody(): void
    {
        $id = $this->queue();
        $this->eventResults = [new SendResult(422, '{"error":"invalid email"}', null)];
        $this->sender()->run();
        $this->assertSame('parked', $this->store->statusOf($id));
        $this->assertStringContainsString('invalid email', $this->store->rows[$id]['last_error']);
    }

    public function testRetryableFailureBacksOffThenParksAfterFiveRetries(): void
    {
        $id = $this->queue();
        for ($i = 1; $i <= 5; $i++) {
            $this->eventResults = [new SendResult(503, 'down', null)];
            $this->sender()->run();
            $this->assertSame('pending', $this->store->statusOf($id), "attempt $i stays pending");
            $this->assertSame($i, $this->store->rows[$id]['attempts']);
            // make it due again
            $this->store->rows[$id]['next_attempt_at'] = null;
        }
        $this->eventResults = [new SendResult(0, '', 'Operation timed out')];
        $this->sender()->run();
        $this->assertSame('parked', $this->store->statusOf($id));
        $this->assertSame(6, $this->store->rows[$id]['attempts']);
    }

    public function testRetryIsNotPickedUpBeforeItsTime(): void
    {
        $id = $this->queue();
        $this->eventResults = [new SendResult(500, '', null)];
        $this->sender()->run();
        $this->eventCalls = [];
        $this->sender()->run();
        $this->assertSame([], $this->eventCalls);
        $this->assertSame('pending', $this->store->statusOf($id));
    }

    public function testSubscriberImportRunsFirstAndFailureBlocksEvent(): void
    {
        $id = $this->queue();
        $this->buildResult = BuildResult::event(['type' => '$purchase', 'email' => 'a@x.com'], ['email' => 'a@x.com', 'order_count' => 1]);
        $this->subscriberResult = new SendResult(500, '', null);
        $this->sender()->run();
        $this->assertCount(1, $this->subscriberCalls);
        $this->assertSame([], $this->eventCalls);
        $this->assertSame('pending', $this->store->statusOf($id));
    }

    public function testSubscriberThenEventOnSuccess(): void
    {
        $this->queue();
        $this->buildResult = BuildResult::event(['type' => '$purchase', 'email' => 'a@x.com'], ['email' => 'a@x.com']);
        $this->sender()->run();
        $this->assertCount(1, $this->subscriberCalls);
        $this->assertCount(1, $this->eventCalls);
    }

    public function testBuilderExceptionIsRetried(): void
    {
        $id = $this->queue();
        $this->buildThrows = new \RuntimeException('deadlock');
        $this->sender()->run();
        $this->assertSame('pending', $this->store->statusOf($id));
        $this->assertStringContainsString('deadlock', $this->store->rows[$id]['last_error']);
    }

    public function testUnknownEventTypeIsParked(): void
    {
        $id = $this->queue('a@x.com', 'mystery');
        $this->sender()->run();
        $this->assertSame('parked', $this->store->statusOf($id));
    }

    public function testUnconfiguredLeavesRowsPending(): void
    {
        $id = $this->queue();
        $this->configured = false;
        $stats = $this->sender()->run();
        $this->assertSame('pending', $this->store->statusOf($id));
        $this->assertTrue($stats['not_configured']);
    }

    public function testNoWorkWhenLockHeld(): void
    {
        $id = $this->queue();
        $this->store->lockAvailable = false;
        $stats = $this->sender()->run();
        $this->assertSame('pending', $this->store->statusOf($id));
        $this->assertTrue($stats['locked']);
    }

    public function testMaxRowsLimitsWork(): void
    {
        $this->queue();
        $this->queue();
        $this->queue();
        $stats = $this->sender()->run(30, 2);
        $this->assertSame(2, $stats['sent']);
    }

    public function testSendOneSendsSpecificRowRegardlessOfSchedule(): void
    {
        $id = $this->queue();
        $this->store->rows[$id]['next_attempt_at'] = '2999-01-01 00:00:00';
        $this->sender()->sendRow($this->store->getById($id));
        $this->assertSame('sent', $this->store->statusOf($id));
    }
}
