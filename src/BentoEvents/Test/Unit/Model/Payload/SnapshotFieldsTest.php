<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoEvents\Model\Payload\LineItems;
use ArtLounge\BentoEvents\Model\Payload\SnapshotFields;
use PHPUnit\Framework\TestCase;

class SnapshotFieldsTest extends TestCase
{
    private function items(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['item_id' => $i, 'product_id' => 10 + $i, 'sku' => 'SKU-' . $i, 'name' => 'Product ' . $i,
                'qty' => (float)$i, 'price' => 100.5 * $i, 'row_total' => 100.5 * $i * $i,
                'url' => 'https://x/p' . $i, 'image' => 'https://cdn/media/catalog/product/cache/abc/p' . $i . '.jpg'];
        }

        return LineItems::map($out);
    }

    public function testCartSnapshotFillsFiveSlotsAndTotals(): void
    {
        $f = SnapshotFields::cart($this->items(6), 248000.0 / 100, 'https://x/recover/T');

        $this->assertSame('Product 1', $f['last_cart_item_1_name']);
        $this->assertSame(1, $f['last_cart_item_1_qty']);
        $this->assertSame('₹100.50', $f['last_cart_item_1_price_display']);
        $this->assertSame('https://x/p1', $f['last_cart_item_1_url']);
        $this->assertSame('https://cdn/media/catalog/product/cache/abc/p1.jpg', $f['last_cart_item_1_image_url']);
        $this->assertSame('Product 5', $f['last_cart_item_5_name']);
        $this->assertSame(5, $f['last_cart_item_5_qty']);
        $this->assertArrayNotHasKey('last_cart_item_6_name', $f, 'never more than 5 slots');
        $this->assertSame(6, $f['last_cart_item_count'], 'count is every line, not just the 5 shown');
        $this->assertSame(248000, $f['last_cart_subtotal']);
        $this->assertSame('₹2,480.00', $f['last_cart_subtotal_display']);
        $this->assertSame('https://x/recover/T', $f['last_cart_recovery_url']);
    }

    public function testShortCartBlanksTheUnusedSlotsSoAnOlderCartCannotLeakThrough(): void
    {
        $f = SnapshotFields::cart($this->items(2), 100.0, 'https://x/r');

        $this->assertSame('Product 2', $f['last_cart_item_2_name']);
        foreach ([3, 4, 5] as $n) {
            foreach (['name', 'qty', 'price_display', 'url', 'image_url'] as $k) {
                $this->assertSame('', $f["last_cart_item_{$n}_{$k}"], "slot $n $k");
            }
        }
        $this->assertSame(2, $f['last_cart_item_count']);
        $this->assertCount(5 * 5 + 4, $f, 'exactly 25 slot fields + 4 cart fields');
    }

    public function testMissingUrlOrImageBecomesEmptyStringNotNull(): void
    {
        $items = LineItems::map([['name' => 'Bare', 'qty' => 1, 'price' => 10, 'url' => null, 'image' => null]]);
        $f = SnapshotFields::cart($items, 10.0, null);

        $this->assertSame('', $f['last_cart_item_1_url']);
        $this->assertSame('', $f['last_cart_item_1_image_url']);
        $this->assertSame('', $f['last_cart_recovery_url']);
        $this->assertNotContains(null, $f);
    }

    public function testOrderSnapshotHasNoPricesAndFiveSlots(): void
    {
        $f = SnapshotFields::order($this->items(3));

        $this->assertSame('Product 3', $f['last_order_item_3_name']);
        $this->assertSame(3, $f['last_order_item_3_qty']);
        $this->assertSame('https://x/p3', $f['last_order_item_3_url']);
        $this->assertSame('https://cdn/media/catalog/product/cache/abc/p3.jpg', $f['last_order_item_3_image_url']);
        $this->assertSame('', $f['last_order_item_4_name']);
        $this->assertSame('', $f['last_order_item_5_image_url']);
        $this->assertSame(3, $f['last_order_item_count']);
        $this->assertArrayNotHasKey('last_order_item_1_price_display', $f);
        $this->assertCount(5 * 4 + 1, $f);
    }

    public function testCouponFieldsWhenEligibleAndBlankedWhenNot(): void
    {
        $on = SnapshotFields::cartCoupon(['code' => 'BENTO-ABC234', 'recovery_url' => 'https://x/r/coupon/BENTO-ABC234',
            'expires_at_display' => '20 October', 'value_display' => '₹200']);
        $this->assertSame([
            'last_cart_coupon_eligible' => 'yes',
            'last_cart_coupon_code' => 'BENTO-ABC234',
            'last_cart_coupon_url' => 'https://x/r/coupon/BENTO-ABC234',
            'last_cart_coupon_expires' => '20 October',
            'last_cart_coupon_value_display' => '₹200',
        ], $on);

        $this->assertSame([
            'last_cart_coupon_eligible' => 'no',
            'last_cart_coupon_code' => '',
            'last_cart_coupon_url' => '',
            'last_cart_coupon_expires' => '',
            'last_cart_coupon_value_display' => '',
        ], SnapshotFields::cartCoupon(null));
    }
}
