<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Review;

use ArtLounge\BentoEvents\Model\Review\ReviewSnapshotLoader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class ReviewSnapshotLoaderTest extends TestCase
{
    private function loader($row, array $votes): ReviewSnapshotLoader
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('select')->willReturn(new Select());
        $conn->method('fetchRow')->willReturn($row);
        $conn->method('fetchCol')->willReturn($votes);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ReviewSnapshotLoader($resource);
    }

    public function testMapsReviewDetailAndVotes(): void
    {
        $s = $this->loader([
            'review_id' => '18342', 'entity_pk_value' => '500', 'status_id' => '2', 'created_at' => '2026-10-14 05:00:00',
            'nickname' => 'Meera', 'customer_id' => null, 'store_id' => '1',
        ], ['5', '4'])->load(18342);

        $this->assertSame([
            'review_id' => 18342, 'product_id' => 500, 'store_id' => 1, 'status_id' => 2, 'nickname' => 'Meera',
            'customer_id' => null, 'created_at' => '2026-10-14 05:00:00', 'votes' => [5, 4],
        ], $s);
    }

    public function testMissingReviewIsNull(): void
    {
        $this->assertNull($this->loader(false, [])->load(1));
    }
}
