<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Pricing;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\SendResult;
use ArtLounge\BentoCore\Model\TestMode;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\BuilderInterface;
use ArtLounge\BentoEvents\Model\Payload\BuilderPool;
use ArtLounge\BentoEvents\Model\Payload\BuildResult;
use ArtLounge\BentoEvents\Model\Pricing\OpenCartRefresh;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class OpenCartRefreshTest extends TestCase
{
    private function row(int $id, string $type, string $email): array
    {
        return ['id' => $id, 'event_type' => $type, 'entity_type' => 'quote', 'entity_id' => $id, 'email' => $email,
            'store_id' => 1, 'dedup_key' => "$type:$id", 'created_at' => '2026-10-26 10:00:00', 'status' => 'sent', 'context' => '{}'];
    }

    public function testNewestCartPerEmailFieldsOnlyNoEvents(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('select')->willReturn(new Select());
        $conn->method('fetchAll')->willReturn([ // id DESC
            $this->row(30, 'checkout_abandoned', 'a@x.com'),
            $this->row(20, 'cart_abandoned', 'a@x.com'),   // older cart, same person: ignored
            $this->row(10, 'cart_abandoned', 'gone@x.com'), // converted since: builder skips
        ]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);

        $builder = $this->createMock(BuilderInterface::class);
        $builder->method('build')->willReturnCallback(fn (OutboxRow $r) => $r->email === 'gone@x.com'
            ? BuildResult::skip('converted to an order')
            : BuildResult::event(['type' => '$x', 'email' => $r->email, 'fields' => ['last_cart_next_label' => 'from ' . $r->id]]));
        $pool = new BuilderPool(['cart_abandoned' => $builder, 'checkout_abandoned' => $builder]);

        $sent = [];
        $client = $this->createMock(BentoClientInterface::class);
        $client->expects($this->never())->method('postEvents');
        $client->method('importSubscribers')->willReturnCallback(function (array $records) use (&$sent) {
            $sent = $records;
            return new SendResult(200, '{}', null);
        });
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getTestMode')->willReturn(new TestMode([]));

        $report = (new OpenCartRefresh($resource, $pool, $client, $config))->run();

        $this->assertSame([['email' => 'a@x.com', 'last_cart_next_label' => 'from 30']], $sent);
        $this->assertSame(['carts' => 1, 'sent' => 1, 'skipped' => 1, 'error' => null], $report);
    }
}
