<?php
/**
 * EventDeduplicator Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model;

use ArtLounge\BentoEvents\Model\EventDeduplicator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EventDeduplicatorTest extends TestCase
{
    private EventDeduplicator $deduplicator;
    private MockObject $resourceConnection;
    private MockObject $connection;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resourceConnection->method('getConnection')
            ->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnArgument(0);

        $this->deduplicator = new EventDeduplicator(
            $this->resourceConnection,
            $this->logger
        );
    }

    public function testTryMarkSentReturnsTrueOnFirstInsert(): void
    {
        $this->connection->method('insertOnDuplicate')
            ->willReturn(1);

        $result = $this->deduplicator->tryMarkSent(42, '$cart_abandoned');

        $this->assertTrue($result);
    }

    public function testTryMarkSentReturnsFalseOnDuplicate(): void
    {
        $this->connection->method('insertOnDuplicate')
            ->willReturn(0);

        $result = $this->deduplicator->tryMarkSent(42, '$cart_abandoned');

        $this->assertFalse($result);
    }

    public function testCleanupDeletesOldEntries(): void
    {
        $this->connection->method('delete')
            ->willReturn(5);

        $result = $this->deduplicator->cleanup(30);

        $this->assertSame(5, $result);
    }
}
