<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Coupon\CouponGate;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\CartAbandonedBuilder;
use ArtLounge\BentoEvents\Model\Payload\CheckoutAbandonedBuilder;
use ArtLounge\BentoEvents\Model\Payload\CheckoutStartedBuilder;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use ArtLounge\BentoEvents\Model\Payload\OrderSnapshotLoader;
use ArtLounge\BentoEvents\Model\Payload\QuotePayloadMapper;
use ArtLounge\BentoEvents\Model\Payload\QuoteSnapshotLoader;
use ArtLounge\BentoEvents\Model\Payload\RecoveryUrlBuilder;
use PHPUnit\Framework\TestCase;

class QuoteBuildersTest extends TestCase
{
    private QuoteSnapshotLoader $quotes;
    private OrderSnapshotLoader $orders;
    private RecoveryUrlBuilder $urls;
    private EventEnvelope $envelope;
    private ?array $couponDecision = null;

    protected function setUp(): void
    {
        $this->quotes = $this->createMock(QuoteSnapshotLoader::class);
        $this->orders = $this->createMock(OrderSnapshotLoader::class);
        $this->urls = $this->createMock(RecoveryUrlBuilder::class);
        $this->urls->method('build')->willReturnCallback(
            fn ($q, $e, $s, $coupon = null) => 'https://local/bento/cart/recover/recover/TOKEN' . ($coupon ? '/coupon/' . $coupon : '')
        );
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');
        $this->envelope = new EventEnvelope($config);
    }

    private function quote(array $o = []): array
    {
        return array_replace([
            'quote_id' => 9001, 'store_id' => 1, 'is_active' => true, 'items_count' => 2, 'has_order' => false,
            'created_at' => '2026-10-13 08:00:00', 'updated_at' => '2026-10-13 09:00:00', 'currency' => 'INR',
            'grand_total' => 2600.0, 'subtotal' => 2500.0, 'discount_amount' => 0.0, 'shipping_amount' => 100.0,
            'email' => 'cart+bentotest@x.com', 'firstname' => 'Ravi', 'lastname' => 'K', 'phone' => '9876543210',
            'items' => Fixtures::order()['items'],
        ], $o);
    }

    private function row(string $type, string $entityType, int $entityId, array $context = []): OutboxRow
    {
        return OutboxRow::fromArray([
            'id' => 5, 'event_type' => $type, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'email' => 'cart+bentotest@x.com', 'store_id' => 1, 'dedup_key' => $type . ':9001:x',
            'created_at' => '2026-10-13 10:00:00', 'context' => json_encode($context),
        ]);
    }

    private function gate(): CouponGate
    {
        $gate = $this->createMock(CouponGate::class);
        $gate->method('evaluate')->willReturnCallback(fn () => $this->couponDecision ?? ['eligible' => false, 'coupon' => null]);
        return $gate;
    }

    public function testCheckoutStartedNeverCarriesCoupon(): void
    {
        $this->quotes->method('load')->willReturn($this->quote());
        $b = new CheckoutStartedBuilder($this->quotes, new QuotePayloadMapper(), $this->envelope);
        $e = $b->build($this->row('checkout_started', 'quote', 9001))->event;

        $this->assertSame('$checkout_started', $e['type']);
        $this->assertSame(2, $e['details']['item_count']);
        $this->assertSame('₹2,500.00', $e['details']['financials']['subtotal_display']);
        $this->assertArrayNotHasKey('coupon', $e['details']);
        $this->assertArrayNotHasKey('coupon_eligible', $e['details']);
    }

    public function testCheckoutAbandonedFromDismissUsesOrderData(): void
    {
        $this->orders->method('load')->with(45)->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001]));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->envelope);

        $e = $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->event;

        $this->assertSame('$checkout_abandoned', $e['type']);
        $this->assertSame('dismissed', $e['details']['abandon_source']);
        $this->assertArrayNotHasKey('checkout_source', $e['details']);
        $this->assertSame('+919820012345', $e['details']['customer']['phone']);
        $this->assertSame('Kshitij', $e['fields']['first_name']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN', $e['details']['recovery_url']);
        $this->assertSame('₹1,488.60', $e['details']['financials']['total_value_display']);
        $this->assertSame('₹1,488.60', $e['details']['financials']['total_display'], 'flow-facing name (spec §4)');
        $this->assertArrayNotHasKey('coupon', $e['details']);
    }

    public function testDismissedOrderPaidSinceIsSkipped(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'processing']));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->envelope);
        $this->assertTrue($b->build($this->row('checkout_abandoned', 'order', 45))->isSkip());
    }

    public function testCheckoutAbandonedFromContactCaptureUsesCapturedContact(): void
    {
        $contacts = $this->createMock(ContactStore::class);
        $contacts->method('getById')->with(3)->willReturn([
            'id' => 3, 'quote_id' => 9001, 'rzp_order_id' => 'order_X1', 'email' => 'cart+bentotest@x.com', 'phone' => '+919000000001',
        ]);
        $this->quotes->method('load')->with(9001)->willReturn($this->quote(['email' => null, 'phone' => null]));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $contacts, new QuotePayloadMapper(), $this->urls, $this->envelope);

        $e = $b->build($this->row('checkout_abandoned', 'contact', 3, ['source' => 'contact_captured']))->event;

        $this->assertSame('contact_captured', $e['details']['abandon_source']);
        $this->assertSame('+919000000001', $e['details']['customer']['phone']);
        $this->assertSame('cart+bentotest@x.com', $e['details']['customer']['email']);
    }

    public function testCartAbandonedBelowThresholdIsNotEligible(): void
    {
        $this->quotes->method('load')->willReturn($this->quote());
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope);

        $d = $b->build($this->row('cart_abandoned', 'quote', 9001))->event['details'];

        $this->assertFalse($d['coupon_eligible']);
        $this->assertArrayNotHasKey('coupon', $d);
        $this->assertArrayNotHasKey('recovery_url_with_coupon', $d);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN', $d['recovery_url']);
    }

    public function testCartAbandonedEligibleCarriesCouponAndSeparateCouponUrl(): void
    {
        $this->quotes->method('load')->willReturn($this->quote());
        $this->couponDecision = ['eligible' => true, 'coupon' => ['code' => 'BENTO-ABC234', 'expires_at' => '2026-10-20T15:30:00+05:30']];
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope);

        $d = $b->build($this->row('cart_abandoned', 'quote', 9001))->event['details'];

        $this->assertTrue($d['coupon_eligible']);
        $this->assertSame('BENTO-ABC234', $d['coupon']['code']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN', $d['recovery_url'], 'plain link never auto-applies the coupon');
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN/coupon/BENTO-ABC234', $d['coupon']['recovery_url']);
        $this->assertSame('2026-10-20T15:30:00+05:30', $d['coupon']['expires_at']);
        $this->assertSame('20 October', $d['coupon']['expires_at_display']);
        $this->assertArrayNotHasKey('recovery_url_with_coupon', $d);
    }

    public function testConvertedOrEmptyCartIsSkipped(): void
    {
        $this->quotes->method('load')->willReturnOnConsecutiveCalls(
            $this->quote(['has_order' => true]),
            $this->quote(['items_count' => 0]),
            $this->quote(['is_active' => false]),
            null
        );
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope);
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($b->build($this->row('cart_abandoned', 'quote', 9001))->isSkip());
        }
    }
}
