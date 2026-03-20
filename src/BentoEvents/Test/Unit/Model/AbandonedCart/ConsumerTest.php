<?php
/**
 * Abandoned Cart Consumer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\AbandonedCart;

use ArtLounge\BentoEvents\Model\AbandonedCart\Checker;
use ArtLounge\BentoEvents\Model\AbandonedCart\Consumer;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConsumerTest extends TestCase
{
    private Consumer $consumer;
    private MockObject $checker;
    private MockObject $serializer;
    private MockObject $dateTime;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->checker = $this->createMock(Checker::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->consumer = new Consumer(
            $this->checker,
            $this->serializer,
            $this->dateTime,
            $this->logger
        );
    }

    public function testProcessLogsErrorWhenMissingQuoteId(): void
    {
        $this->serializer->method('unserialize')->willReturn(['check_after' => 10]);

        $this->logger->expects($this->once())->method('error');

        $this->consumer->process('{"check_after":10}');
    }

    public function testProcessSkipsWhenBeforeCheckAfter(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'quote_id' => 10,
            'check_after' => 1700001000,
            'quote_updated_at' => '2026-01-24 10:00:00'
        ]);
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);

        $this->logger->expects($this->once())->method('warning');
        $this->checker->expects($this->never())->method('checkAndTrigger');

        $this->consumer->process('{"quote_id":10}');
    }

    public function testProcessCallsCheckerWhenReady(): void
    {
        $this->serializer->method('unserialize')->willReturn([
            'quote_id' => 10,
            'check_after' => 1700000000,
            'quote_updated_at' => '2026-01-24 10:00:00'
        ]);
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);

        $this->checker
            ->expects($this->once())
            ->method('checkAndTrigger')
            ->with(10, '2026-01-24 10:00:00')
            ->willReturn(true);

        $this->consumer->process('{"quote_id":10}');
    }

    public function testProcessHandlesExceptions(): void
    {
        $this->serializer
            ->method('unserialize')
            ->willThrowException(new \RuntimeException('bad'));

        $this->logger->expects($this->once())->method('error');

        $this->consumer->process('bad');
    }
}
