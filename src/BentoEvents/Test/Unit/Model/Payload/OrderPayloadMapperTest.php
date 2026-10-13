<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoEvents\Model\Payload\OrderPayloadMapper;
use PHPUnit\Framework\TestCase;

class OrderPayloadMapperTest extends TestCase
{
    private OrderPayloadMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OrderPayloadMapper();
    }

    public function testValueAndFinancialsCarryPaiseAndDisplayTwins(): void
    {
        $d = $this->mapper->details(Fixtures::order());

        $this->assertSame(['amount' => 148860, 'currency' => 'INR'], $d['value']);
        $this->assertSame(148860, $d['financials']['total_value']);
        $this->assertSame('₹1,488.60', $d['financials']['total_value_display']);
        $this->assertSame('₹1,488.60', $d['financials']['total_display'], 'flow-facing name (spec §4)');
        $this->assertSame(140000, $d['financials']['subtotal']);
        $this->assertSame('₹1,400.00', $d['financials']['subtotal_display']);
        $this->assertSame('₹88.60', $d['financials']['shipping_amount_display']);
        $this->assertSame('₹0.00', $d['financials']['discount_amount_display']);
    }

    public function testItemsHaveCanonicalAndAliasKeysWithDisplayMoney(): void
    {
        $item = $this->mapper->details(Fixtures::order())['cart']['items'][0];

        $this->assertSame('WN-COT-12', $item['product_sku']);
        $this->assertSame('Cotman Watercolour 12 Tubes', $item['product_name']);
        $this->assertSame(50000, $item['product_price']);
        $this->assertSame('₹500.00', $item['price_display']);
        $this->assertSame('₹500.00', $item['product_price_display'], 'flow-facing name (spec §4)');
        $this->assertSame(100000, $item['row_total']);
        $this->assertSame('₹1,000.00', $item['row_total_display']);
        $this->assertSame(2, $item['quantity']);
        $this->assertSame(2, $item['qty']);
        $this->assertSame('https://x/m.jpg', $item['product_image_url']);
        $this->assertSame('Winsor & Newton', $item['brand']);
    }

    public function testProductCategoriesIsAJsonListNotAnObject(): void
    {
        $d = $this->mapper->details(Fixtures::order());

        $this->assertSame(
            ['Paints and Mediums', 'Watercolour Paint & Mediums', 'Brushes and Painting Tools'],
            $d['summary']['product_categories']
        );
        // array_unique leaves gaps (0,1,3) which json_encode turns into an object — must be a list
        $this->assertStringStartsWith('[', json_encode($d['summary']['product_categories']));
        $this->assertSame(2, $d['summary']['ordered_product_count']);
    }

    public function testOrderBlockUsesIstDatesAndCartId(): void
    {
        $d = $this->mapper->details(Fixtures::order());

        $this->assertSame('100045', $d['order']['increment_id']);
        $this->assertSame('#100045', $d['order']['increment_id_display']);
        $this->assertSame('2026-10-13T15:30:00+05:30', $d['order']['created_at']);
        $this->assertSame('9001', $d['cart_id']);
    }

    public function testCustomerPhoneAndPayment(): void
    {
        $d = $this->mapper->details(Fixtures::order());

        $this->assertSame('+919820012345', $d['customer']['phone']);
        $this->assertTrue($d['payment']['is_cod']);
        $this->assertTrue($d['flags']['is_guest']);
        $this->assertFalse($d['flags']['discounted']);
    }

    public function testDiscountedFlag(): void
    {
        $d = $this->mapper->details(Fixtures::order(['discount_amount' => 100.0]));
        $this->assertTrue($d['flags']['discounted']);
        $this->assertSame(10000, $d['financials']['discount_amount']);
    }

    public function testNoTagsAnywhere(): void
    {
        $json = json_encode($this->mapper->details(Fixtures::order()));
        $this->assertStringNotContainsString('"tags"', $json);
    }

    public function testSubscriberFieldsFromOrder(): void
    {
        $f = $this->mapper->subscriberFields(Fixtures::order());
        $this->assertSame(['first_name' => 'Kshitij', 'last_name' => 'Shah', 'phone' => '+919820012345'], $f);
    }

    public function testRefundBlock(): void
    {
        $refund = $this->mapper->refund([
            'creditmemo_id' => 7, 'increment_id' => 'CM100007', 'created_at' => '2026-10-14 04:30:00',
            'grand_total' => 400.0, 'adjustment_positive' => 0.0, 'adjustment_negative' => 0.0,
            'items' => [['sku' => 'BR-1', 'name' => 'Round Brush 6', 'qty' => 1.0, 'row_total' => 400.0]],
        ]);
        $this->assertSame(40000, $refund['refund_amount']);
        $this->assertSame('₹400.00', $refund['refund_amount_display']);
        $this->assertSame('2026-10-14T10:00:00+05:30', $refund['created_at']);
        $this->assertSame('₹400.00', $refund['refunded_items'][0]['row_total_display']);
    }
}
