<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use ArtLounge\BentoEvents\Model\Payload\OrderEventBuilder;
use ArtLounge\BentoEvents\Model\Payload\OrderPayloadMapper;
use ArtLounge\BentoEvents\Model\Payload\OrderSnapshotLoader;
use ArtLounge\BentoEvents\Model\Payload\RefundBuilder;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;
use PHPUnit\Framework\TestCase;

class OrderBuildersTest extends TestCase
{
    private OrderSnapshotLoader $loader;
    private ProfileFieldsProvider $profile;
    private EventEnvelope $envelope;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(OrderSnapshotLoader::class);
        $this->profile = $this->createMock(ProfileFieldsProvider::class);
        $this->profile->method('subscriberRecord')->willReturnCallback(
            fn (string $email, array $extra) => ['email' => $email, 'order_count' => 3] + $extra
        );
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');
        $this->envelope = new EventEnvelope($config);
    }

    private function row(string $type, string $dedup, int $entityId = 45): OutboxRow
    {
        return OutboxRow::fromArray([
            'id' => 1, 'event_type' => $type, 'entity_type' => 'order', 'entity_id' => $entityId,
            'email' => 'kshitij+bentotest@gmail.com', 'store_id' => 1, 'dedup_key' => $dedup,
            'created_at' => '2026-10-13 10:05:00',
        ]);
    }

    public function testPurchaseUsesIncrementIdAsUniqueKeyAndCarriesProfile(): void
    {
        $this->loader->method('load')->with(45)->willReturn(Fixtures::order());
        $builder = new OrderEventBuilder('$purchase', $this->loader, new OrderPayloadMapper(), $this->envelope, $this->profile);

        $r = $builder->build($this->row('purchase', 'purchase:100045'));

        $this->assertFalse($r->isSkip());
        $this->assertSame('$purchase', $r->event['type']);
        $this->assertSame('100045', $r->event['details']['unique']['key']);
        $this->assertSame('+919820012345', $r->event['fields']['phone']);
        $this->assertSame('2026-10-13T15:35:00+05:30', $r->event['details']['timestamp']);
        $this->assertSame(['email' => 'kshitij+bentotest@gmail.com', 'order_count' => 3, 'first_name' => 'Kshitij',
            'last_name' => 'Shah', 'phone' => '+919820012345'], $r->subscriber);
        $this->assertArrayNotHasKey('tags', $r->event['fields']);
    }

    public function testCanceledUsesItsOwnUniqueKey(): void
    {
        $this->loader->method('load')->willReturn(Fixtures::order(['state' => 'canceled', 'status' => 'canceled']));
        $builder = new OrderEventBuilder('$OrderCanceled', $this->loader, new OrderPayloadMapper(), $this->envelope, $this->profile);

        $r = $builder->build($this->row('order_canceled', 'order_canceled:100045'));

        $this->assertSame('$OrderCanceled', $r->event['type']);
        $this->assertSame('order_canceled:100045', $r->event['details']['unique']['key']);
        $this->assertSame('canceled', $r->event['details']['order']['state']);
    }

    public function testMissingOrderIsSkipped(): void
    {
        $this->loader->method('load')->willReturn(null);
        $builder = new OrderEventBuilder('$purchase', $this->loader, new OrderPayloadMapper(), $this->envelope, $this->profile);
        $this->assertTrue($builder->build($this->row('purchase', 'purchase:1'))->isSkip());
    }

    public function testRefund(): void
    {
        $this->loader->method('loadCreditmemo')->with(7)->willReturn([
            'creditmemo_id' => 7, 'increment_id' => 'CM7', 'created_at' => '2026-10-14 04:30:00',
            'grand_total' => 400.0, 'adjustment_positive' => 0.0, 'adjustment_negative' => 0.0,
            'items' => [['sku' => 'BR-1', 'name' => 'Brush', 'qty' => 1.0, 'row_total' => 400.0]],
            'order' => Fixtures::order(),
        ]);
        $builder = new RefundBuilder($this->loader, new OrderPayloadMapper(), $this->envelope, $this->profile);

        $r = $builder->build($this->row('refund', 'refund:CM7', 7));

        $this->assertSame('$OrderRefunded', $r->event['type']);
        $this->assertSame('refund:CM7', $r->event['details']['unique']['key']);
        $this->assertSame('₹400.00', $r->event['details']['refund']['refund_amount_display']);
        $this->assertSame('100045', $r->event['details']['order']['increment_id']);
        $this->assertNotNull($r->subscriber);
    }

    public function testPurchaseWritesTheOrderSnapshotOntoTheSubscriber(): void
    {
        $this->loader->method('load')->with(45)->willReturn(Fixtures::order());
        $builder = new OrderEventBuilder('$purchase', $this->loader, new OrderPayloadMapper(), $this->envelope, $this->profile);

        $f = $builder->build($this->row('purchase', 'purchase:100045'))->event['fields'];

        $this->assertSame('Cotman Watercolour 12 Tubes', $f['last_order_item_1_name']);
        $this->assertSame(2, $f['last_order_item_1_qty']);
        $this->assertSame('https://x/cotman', $f['last_order_item_1_url']);
        $this->assertSame('https://x/m.jpg', $f['last_order_item_1_image_url']);
        $this->assertSame('Round Brush 6', $f['last_order_item_2_name']);
        $this->assertSame('', $f['last_order_item_2_image_url']);
        $this->assertSame('', $f['last_order_item_5_name']);
        $this->assertSame(2, $f['last_order_item_count']);
        $this->assertSame('Kshitij', $f['first_name']);
    }

    public function testCanceledOrderLeavesTheOrderSnapshotAlone(): void
    {
        $this->loader->method('load')->willReturn(Fixtures::order(['state' => 'canceled']));
        $builder = new OrderEventBuilder('$OrderCanceled', $this->loader, new OrderPayloadMapper(), $this->envelope, $this->profile);

        $f = $builder->build($this->row('order_canceled', 'order_canceled:45'))->event['fields'];

        $this->assertArrayNotHasKey('last_order_item_1_name', $f);
    }
}
