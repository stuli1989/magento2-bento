<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Profile;

use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use ArtLounge\BentoEvents\Model\Profile\CategoryTree;
use ArtLounge\BentoEvents\Model\Profile\StatsCalculator;
use ArtLounge\BentoCore\Api\ConfigInterface;
use PHPUnit\Framework\TestCase;

class StatsCalculatorTest extends TestCase
{
    private StatsCalculator $calc;
    private CategoryTree $tree;

    protected function setUp(): void
    {
        $this->calc = new StatsCalculator();
        // 1/2 root; 84 Paints (L2) > 94 Watercolour (L3) > 300 Tubes (L4); 92 Acrylic (L3); 86 Drawing (L2) > 103 Pencils (L3)
        $this->tree = new CategoryTree(
            [84 => '1/2/84', 94 => '1/2/84/94', 300 => '1/2/84/94/300', 92 => '1/2/84/92', 86 => '1/2/86', 103 => '1/2/86/103', 999 => '1/2/999'],
            [84 => 'Paints and Mediums', 94 => 'Watercolour Paint & Mediums', 300 => 'Tubes', 92 => 'Acrylic Paint & Mediums',
                86 => 'Drawing and Illustration', 103 => 'Pencils', 999 => 'Clearance']
        );
    }

    private function order(string $inc, string $state, string $created, float $total, float $refunded = 0.0, ?string $purchasedAt = null): array
    {
        return ['increment_id' => $inc, 'state' => $state, 'created_at' => $created, 'grand_total' => $total,
            'total_refunded' => $refunded, 'purchased_at' => $purchasedAt];
    }

    public function testCountsOnlyProcessingAndCompleteAndSubtractsPartialRefunds(): void
    {
        $f = $this->calc->calculate([
            $this->order('100001', 'complete', '2025-01-10 05:00:00', 1000.0),
            $this->order('100002', 'processing', '2026-10-01 05:00:00', 2000.0, 500.0),
            $this->order('100003', 'canceled', '2026-10-05 05:00:00', 9999.0),
            $this->order('100004', 'closed', '2026-10-06 05:00:00', 7777.0, 7777.0),
            $this->order('100005', 'pending_payment', '2026-10-07 05:00:00', 5555.0),
        ], [], ['is_registered' => false, 'created_at' => null], $this->tree, []);

        $this->assertSame(2, $f['order_count']);
        $this->assertSame(2500.0, $f['lifetime_spend']);
        $this->assertSame(1250.0, $f['aov']);
        $this->assertSame('100002', $f['last_order_number']);
        $this->assertSame(IstDate::format('2025-01-10 05:00:00'), $f['first_order_date']);
        $this->assertSame(IstDate::format('2026-10-01 05:00:00'), $f['last_purchase_date']);
        $this->assertSame(IstDate::format('2025-01-10 05:00:00'), $f['customer_since'], 'guest: since first order');
        // Bento fields are DateTime-typed: ISO-8601 with the IST offset
        foreach (['first_order_date', 'last_purchase_date', 'customer_since'] as $k) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+05:30$/', $f[$k], $k);
        }
        $this->assertFalse($f['is_registered']);
        $this->assertArrayNotHasKey('last_order_date', $f, 'WISMO owns last_order_date');
    }

    public function testLastPurchaseDatePrefersPurchaseMarkerTime(): void
    {
        $f = $this->calc->calculate([
            $this->order('100010', 'processing', '2026-10-13 04:00:00', 500.0, 0.0, '2026-10-13 04:21:07'),
        ], [], ['is_registered' => true, 'created_at' => '2024-05-01 00:00:00'], $this->tree, []);

        $this->assertSame('2026-10-13T09:51:07+05:30', $f['last_purchase_date']);
        $this->assertSame(IstDate::format('2024-05-01 00:00:00'), $f['customer_since']);
        $this->assertTrue($f['is_registered']);
    }

    public function testAovRoundsToTwoDecimals(): void
    {
        $f = $this->calc->calculate([
            $this->order('1', 'complete', '2026-01-01 00:00:00', 100.0),
            $this->order('2', 'complete', '2026-01-02 00:00:00', 100.0),
            $this->order('3', 'complete', '2026-01-03 00:00:00', 100.01),
        ], [], ['is_registered' => false, 'created_at' => null], $this->tree, []);
        $this->assertSame(300.01, $f['lifetime_spend']);
        $this->assertSame(100.0, $f['aov']);
    }

    public function testNoCountedOrdersGivesZerosAndNulls(): void
    {
        $f = $this->calc->calculate(
            [$this->order('1', 'canceled', '2026-01-01 00:00:00', 100.0)],
            [],
            ['is_registered' => true, 'created_at' => '2026-02-02 00:00:00'],
            $this->tree,
            []
        );
        $this->assertSame(0, $f['order_count']);
        $this->assertSame(0.0, $f['lifetime_spend']);
        $this->assertSame(0.0, $f['aov']);
        $this->assertNull($f['first_order_date']);
        $this->assertNull($f['last_purchase_date']);
        $this->assertNull($f['last_order_number']);
        $this->assertNull($f['primary_medium']);
        $this->assertSame('', $f['top_categories']);
        $this->assertSame(IstDate::format('2026-02-02 00:00:00'), $f['customer_since']);
    }

    public function testPrimaryMediumUsesDeepestMappedCategoryAndHighestSpend(): void
    {
        $map = [84 => 'mixed', 94 => 'watercolour', 92 => 'acrylic', 103 => 'drawing'];
        $items = [
            ['category_ids' => [300], 'spend' => 600.0],        // Tubes → deepest mapped ancestor 94 watercolour
            ['category_ids' => [92, 84], 'spend' => 500.0],     // acrylic (deeper than 84)
            ['category_ids' => [103], 'spend' => 300.0],        // drawing
            ['category_ids' => [999], 'spend' => 5000.0],       // unmapped → ignored
            ['category_ids' => [300, 92], 'spend' => 100.0],    // both L3/L4: 300→94 is depth 3 via ancestor... deepest node wins
        ];
        $f = $this->calc->calculate(
            [$this->order('1', 'complete', '2026-01-01 00:00:00', 6500.0)],
            $items,
            ['is_registered' => false, 'created_at' => null],
            $this->tree,
            $map
        );
        $this->assertSame('watercolour', $f['primary_medium']);
    }

    public function testTopCategoriesTop3Level3NamesBySpend(): void
    {
        $items = [
            ['category_ids' => [300], 'spend' => 600.0],
            ['category_ids' => [92], 'spend' => 500.0],
            ['category_ids' => [103], 'spend' => 300.0],
            ['category_ids' => [84], 'spend' => 9999.0], // level 2 only → not a level-3 category
            ['category_ids' => [94, 300], 'spend' => 50.0], // same L3 counted once for the item
        ];
        $f = $this->calc->calculate(
            [$this->order('1', 'complete', '2026-01-01 00:00:00', 1.0)],
            $items,
            ['is_registered' => false, 'created_at' => null],
            $this->tree,
            []
        );
        $this->assertSame('Watercolour Paint & Mediums, Acrylic Paint & Mediums, Pencils', $f['top_categories']);
    }

    public function testLastPurchaseDateAndEventTimestampShareOneFormatter(): void
    {
        // Bento flows exit on: visitor.last_purchase_date > event.details.timestamp (lexical).
        $instant = '2026-10-13 10:00:00';
        $f = $this->calc->calculate(
            [$this->order('1', 'processing', '2026-10-13 09:00:00', 1.0, 0.0, $instant)],
            [],
            ['is_registered' => false, 'created_at' => null],
            $this->tree,
            []
        );

        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');
        $event = (new EventEnvelope($config))->wrap(OutboxRow::fromArray([
            'id' => 1, 'event_type' => 'x', 'email' => 'a@b.c', 'dedup_key' => 'x', 'created_at' => $instant,
        ]), '$x', []);

        $this->assertSame($f['last_purchase_date'], $event['details']['timestamp']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+05:30$/', $f['last_purchase_date']);
        $this->assertSame('2026-10-13T15:30:00+05:30', $event['details']['timestamp']);
    }
}
