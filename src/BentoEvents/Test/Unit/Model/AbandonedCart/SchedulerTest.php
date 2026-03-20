<?php
/**
 * Abandoned Cart Scheduler Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\AbandonedCart;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchedulerTest extends TestCase
{
    private Scheduler $scheduler;
    private MockObject $config;
    private MockObject $resourceConnection;
    private MockObject $connection;
    private MockObject $publisher;
    private MockObject $serializer;
    private MockObject $dateTime;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturn('artlounge_bento_abandoned_cart_schedule');

        $this->scheduler = new Scheduler(
            $this->config,
            $this->resourceConnection,
            $this->publisher,
            $this->serializer,
            $this->dateTime,
            $this->logger
        );
    }

    public function testScheduleCheckUsesQueueWhenConfigured(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(10);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getGrandTotal')->willReturn(120.0);

        $this->config->method('getAbandonedCartProcessingMethod')->willReturn('queue');
        $this->config->method('getAbandonedCartDelay')->willReturn(30);
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24 10:00:00');
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);
        $this->serializer->method('serialize')->willReturn('{"quote_id":10}');

        $this->connection->expects($this->once())->method('insertOnDuplicate');
        $this->publisher->expects($this->once())->method('publish');

        $this->scheduler->scheduleCheck($quote);
    }

    public function testScheduleCheckUsesCronWhenConfigured(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(11);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getGrandTotal')->willReturn(120.0);

        $this->config->method('getAbandonedCartProcessingMethod')->willReturn('cron');
        $this->config->method('getAbandonedCartDelay')->willReturn(15);
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24 10:00:00');

        $this->connection->expects($this->once())->method('insertOnDuplicate');
        $this->publisher->expects($this->never())->method('publish');

        $this->scheduler->scheduleCheck($quote);
    }

    public function testMarkProcessedUpdatesStatus(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'artlounge_bento_abandoned_cart_schedule',
                $this->arrayHasKey('status'),
                ['quote_id = ?' => 10]
            );

        $this->dateTime->method('gmtDate')->willReturn('2026-01-24 10:00:00');

        $this->scheduler->markProcessed(10, 'sent');
    }

    public function testIsAlreadySentReturnsBool(): void
    {
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn('sent');

        $this->assertTrue($this->scheduler->isAlreadySent(10));
    }

    public function testGetPendingCartsFetchesList(): void
    {
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([['quote_id' => 10]]);
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24 10:00:00');

        $result = $this->scheduler->getPendingCarts(10);

        $this->assertCount(1, $result);
    }

    public function testHasRecentSentForCustomerReturnsTrueWhenMatchFound(): void
    {
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn('1');
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24 10:00:00');

        $this->assertTrue($this->scheduler->hasRecentSentForCustomer('TEST@EXAMPLE.COM', 1, 24));
    }

    public function testCleanupDeletesOldEntries(): void
    {
        $this->connection->method('delete')->willReturn(3);
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24 10:00:00');

        $this->assertSame(3, $this->scheduler->cleanup(7));
    }
}
