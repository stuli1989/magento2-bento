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
use ArtLounge\BentoEvents\Model\Payload\CustomerSnapshotLoader;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use ArtLounge\BentoEvents\Model\Payload\OrderSnapshotLoader;
use ArtLounge\BentoEvents\Model\Payload\QuotePayloadMapper;
use ArtLounge\BentoEvents\Model\Payload\QuoteSnapshotLoader;
use ArtLounge\BentoEvents\Model\Payload\RecoveryUrlBuilder;
use ArtLounge\BentoEvents\Model\Pricing\Ladder;
use ArtLounge\BentoEvents\Test\Unit\Model\Pricing\LadderTest;
use PHPUnit\Framework\TestCase;

class QuoteBuildersTest extends TestCase
{
    private QuoteSnapshotLoader $quotes;
    private OrderSnapshotLoader $orders;
    private RecoveryUrlBuilder $urls;
    private EventEnvelope $envelope;
    private CustomerSnapshotLoader $customers;
    private ?array $couponDecision = null;
    private Ladder $ladder;

    protected function setUp(): void
    {
        $this->quotes = $this->createMock(QuoteSnapshotLoader::class);
        $this->orders = $this->createMock(OrderSnapshotLoader::class);
        $this->customers = $this->createMock(CustomerSnapshotLoader::class);
        $this->urls = $this->createMock(RecoveryUrlBuilder::class);
        $this->urls->method('build')->willReturnCallback(
            fn ($q, $e, $s, $coupon = null) => 'https://local/bento/cart/recover/recover/TOKEN' . ($coupon ? '/coupon/' . $coupon : '')
        );
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');
        $this->envelope = new EventEnvelope($config);
        $this->ladder = new Ladder(LadderTest::liveConfig($this));
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
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $e = $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->event;

        $this->assertSame('$checkout_abandoned', $e['type']);
        $this->assertSame('dismissed', $e['details']['abandon_source']);
        $this->assertArrayNotHasKey('checkout_source', $e['details']);
        $this->assertSame('+919820012345', $e['details']['customer']['phone']);
        $this->assertSame('Kshitij', $e['fields']['first_name']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN', $e['details']['recovery_url']);
        $this->assertSame('₹1,488.60', $e['details']['financials']['total_value_display']);
        $this->assertSame('₹1,488.60', $e['details']['financials']['total_display'], 'flow-facing name (spec §4)');
        $this->assertFalse($e['details']['coupon_eligible']);
        $this->assertArrayNotHasKey('coupon', $e['details']);
    }

    public function testCheckoutAbandonedEligibleCarriesCouponAndSeparateCouponUrl(): void
    {
        $this->orders->method('load')->with(45)->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001]));
        $this->couponDecision = ['eligible' => true, 'coupon' => ['code' => 'BENTO-ABC234', 'expires_at' => '2026-10-20T15:30:00+05:30']];
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $e = $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->event;

        $this->assertTrue($e['details']['coupon_eligible']);
        $this->assertSame('BENTO-ABC234', $e['details']['coupon']['code']);
        $this->assertSame('2026-10-20T15:30:00+05:30', $e['details']['coupon']['expires_at']);
        $this->assertSame('20 October', $e['details']['coupon']['expires_at_display']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN/coupon/BENTO-ABC234', $e['details']['coupon']['recovery_url']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN', $e['details']['recovery_url'], 'main link never auto-applies');
        $this->assertSame('yes', $e['fields']['last_cart_coupon_eligible']);
        $this->assertSame('BENTO-ABC234', $e['fields']['last_cart_coupon_code']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN/coupon/BENTO-ABC234', $e['fields']['last_cart_coupon_url']);
        $this->assertSame('20 October', $e['fields']['last_cart_coupon_expires']);
    }

    public function testCheckoutAbandonedAsksTheGateForTheSameQuoteAsTheCartEvent(): void
    {
        // Both events key the coupon on the quote id, so CouponService finds the code that
        // $cart_abandoned already issued for this quote instead of minting a second one.
        $contacts = $this->createMock(ContactStore::class);
        $contacts->method('getById')->willReturn(['id' => 3, 'quote_id' => 9001, 'email' => 'cart+bentotest@x.com', 'phone' => null]);
        $this->quotes->method('load')->with(9001)->willReturn($this->quote(['subtotal' => 3200.0]));
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001, 'subtotal' => 3200.0]));
        $gate = $this->createMock(CouponGate::class);
        $gate->expects($this->exactly(2))->method('evaluate')->with(3200.0, 9001, 1)
            ->willReturn(['eligible' => false, 'coupon' => null]);

        foreach ([['contact', 3], ['order', 45]] as [$type, $id]) {
            (new CheckoutAbandonedBuilder($this->orders, $this->quotes, $contacts, new QuotePayloadMapper(), $this->urls, $gate, $this->envelope, $this->customers, $this->ladder))
                ->build($this->row('checkout_abandoned', $type, $id));
        }
    }

    public function testDismissedOrderPaidSinceIsSkipped(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'processing']));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        $this->assertTrue($b->build($this->row('checkout_abandoned', 'order', 45))->isSkip());
    }

    public function testCheckoutAbandonedFromContactCaptureUsesCapturedContact(): void
    {
        $contacts = $this->createMock(ContactStore::class);
        $contacts->method('getById')->with(3)->willReturn([
            'id' => 3, 'quote_id' => 9001, 'rzp_order_id' => 'order_X1', 'email' => 'cart+bentotest@x.com', 'phone' => '+919000000001',
        ]);
        $this->quotes->method('load')->with(9001)->willReturn($this->quote(['email' => null, 'phone' => null]));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $contacts, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $e = $b->build($this->row('checkout_abandoned', 'contact', 3, ['source' => 'contact_captured']))->event;

        $this->assertSame('contact_captured', $e['details']['abandon_source']);
        $this->assertSame('+919000000001', $e['details']['customer']['phone']);
        $this->assertSame('cart+bentotest@x.com', $e['details']['customer']['email']);
    }

    public function testCartAbandonedBelowThresholdIsNotEligible(): void
    {
        $this->quotes->method('load')->willReturn($this->quote());
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

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
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

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
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        $reasons = [];
        for ($i = 0; $i < 4; $i++) {
            $reasons[] = $b->build($this->row('cart_abandoned', 'quote', 9001))->skipReason;
        }
        $this->assertSame(
            ['converted to an order', CartAbandonedBuilder::SKIP_EMPTY, 'cart no longer active', 'quote 9001 not found'],
            $reasons
        );
    }

    public function testCartEmptiedOnAQuoteWithADismissedOrderIsReportedAsEmptied(): void
    {
        $this->quotes->method('load')->willReturn($this->quote(['has_order' => true, 'items_count' => 0]));
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        $this->assertSame(CartAbandonedBuilder::SKIP_EMPTY, $b->build($this->row('cart_abandoned', 'quote', 9001))->skipReason);
    }

    public function testDismissedCheckoutWhoseCartWasEmptiedIsReportedAsEmptied(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001]));
        $this->quotes->method('load')->with(9001)->willReturn($this->quote(['has_order' => true, 'items_count' => 0]));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        $this->assertSame(CartAbandonedBuilder::SKIP_EMPTY, $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->skipReason);
    }

    public function testWhatsAppLinkSignsInOnlyOnTheCustomersOwnNumber(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001, 'customer_id' => 7]));
        $this->customers->method('ownsPhone')->willReturnOnConsecutiveCalls(true, false);
        $calls = [];
        $urls = $this->createMock(RecoveryUrlBuilder::class);
        $urls->method('build')->willReturnCallback(function ($q, $e, $s, $coupon = null, $signIn = true) use (&$calls) {
            $calls[] = $signIn;
            return 'https://local/r';
        });
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']));
        $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']));

        $this->assertSame([true, true, false, true], $calls, 'WhatsApp link then email link, per build');
    }

    public function testAGiftRecipientsNumberStaysOffTheCustomersProfileButStillGetsTheMessage(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001, 'customer_id' => 7]));
        $this->customers->method('ownsPhone')->willReturn(false);
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $e = $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->event;

        $this->assertArrayNotHasKey('phone', $e['fields']);
        $this->assertSame('+919820012345', $e['details']['phone']);
    }

    public function testDismissedCodOrderIsSkippedAsPaid(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'new', 'status' => 'processing', 'quote_id' => 9001]));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        $this->assertSame('paid since dismissal', $b->build($this->row('checkout_abandoned', 'order', 45))->skipReason);
    }

    public function testDismissedCheckoutShowsTheEditedCartButKeepsTheOrderNumber(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001]));
        $this->quotes->method('load')->willReturn($this->quote(['has_order' => true, 'subtotal' => 600.0, 'grand_total' => 600.0, 'discount_amount' => 0.0]));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        $e = $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->event;
        $this->assertSame(60000, (int)$e['fields']['last_cart_subtotal']);
        $this->assertNotSame('', $e['details']['order_increment_id']);
    }

    public function testDismissedCheckoutKeepsItsEventWhileTheCartStillHasItemsOrIsInactive(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001]));
        $this->quotes->method('load')->willReturnOnConsecutiveCalls(
            $this->quote(['has_order' => true]),                                  // popup closed, cart restored
            $this->quote(['has_order' => true, 'is_active' => false, 'items_count' => 0])  // not provably emptied
        );
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        for ($i = 0; $i < 2; $i++) {
            $this->assertFalse($b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->isSkip());
        }
    }

    public function testInactiveEmptyCartIsNotReportedAsEmptied(): void
    {
        $this->quotes->method('load')->willReturn($this->quote(['is_active' => false, 'items_count' => 0]));
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        $this->assertSame('cart no longer active', $b->build($this->row('cart_abandoned', 'quote', 9001))->skipReason);
    }

    public function testCartAbandonedWritesTheCartSnapshotAndCouponFieldsOntoTheSubscriber(): void
    {
        $this->quotes->method('load')->willReturn($this->quote());
        $this->couponDecision = ['eligible' => true, 'coupon' => ['code' => 'BENTO-ABC234', 'expires_at' => '2026-10-20T15:30:00+05:30']];
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $f = $b->build($this->row('cart_abandoned', 'quote', 9001))->event['fields'];

        $this->assertSame('Ravi', $f['first_name']);
        $this->assertSame('Cotman Watercolour 12 Tubes', $f['last_cart_item_1_name']);
        $this->assertSame(2, $f['last_cart_item_1_qty']);
        $this->assertSame('₹1,000.00', $f['last_cart_item_1_price_display']);
        $this->assertSame('https://x/cotman', $f['last_cart_item_1_url']);
        $this->assertSame('https://x/m.jpg', $f['last_cart_item_1_image_url']);
        $this->assertSame(' ', $f['last_cart_item_2_url']);
        $this->assertSame(' ', $f['last_cart_item_3_name']);
        $this->assertSame(2, $f['last_cart_item_count']);
        $this->assertSame(250000, $f['last_cart_subtotal']);
        $this->assertSame('₹2,500.00', $f['last_cart_subtotal_display']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN', $f['last_cart_recovery_url']);
        $this->assertSame('yes', $f['last_cart_coupon_eligible']);
        $this->assertSame('BENTO-ABC234', $f['last_cart_coupon_code']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN/coupon/BENTO-ABC234', $f['last_cart_coupon_url']);
        $this->assertSame('20 October', $f['last_cart_coupon_expires']);
    }

    public function testCartAbandonedBelowThresholdBlanksCouponFields(): void
    {
        $this->quotes->method('load')->willReturn($this->quote());
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $f = $b->build($this->row('cart_abandoned', 'quote', 9001))->event['fields'];

        $this->assertSame('no', $f['last_cart_coupon_eligible']);
        $this->assertSame(' ', $f['last_cart_coupon_code']);
        $this->assertSame(' ', $f['last_cart_coupon_url']);
    }

    public function testCheckoutAbandonedWritesTheCartSnapshotAndBlanksCouponFieldsWhenNotEligible(): void
    {
        $this->orders->method('load')->with(45)->willReturn(Fixtures::order(['state' => 'pending_payment']));
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $f = $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->event['fields'];

        $this->assertSame('Round Brush 6', $f['last_cart_item_2_name']);
        $this->assertSame(2, $f['last_cart_item_count']);
        $this->assertSame(140000, $f['last_cart_subtotal']);
        $this->assertSame('https://local/bento/cart/recover/recover/TOKEN', $f['last_cart_recovery_url']);
        $this->assertSame('no', $f['last_cart_coupon_eligible']);
        $this->assertSame(' ', $f['last_cart_coupon_code'], 'an earlier cart\'s coupon must not resurface');
    }

    public function testCheckoutStartedDoesNotOverwriteTheCartSnapshot(): void
    {
        $this->quotes->method('load')->willReturn($this->quote());
        $b = new CheckoutStartedBuilder($this->quotes, new QuotePayloadMapper(), $this->envelope);
        $e = $b->build($this->row('checkout_started', 'quote', 9001))->event;

        $this->assertArrayNotHasKey('last_cart_item_1_name', $e['fields'] ?? []);
    }

    // ── v2.3.0 WhatsApp layer: details.phone + details.order_increment_id ─────────────────────────

    public function testDismissedCheckoutCarriesThePendingOrderNumberAndPhone(): void
    {
        $this->orders->method('load')->with(45)->willReturn(Fixtures::order(['state' => 'pending_payment', 'quote_id' => 9001]));
        $this->customers->expects($this->never())->method('defaultPhone');
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $d = $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->event['details'];

        $this->assertSame('100045', $d['order_increment_id']);
        $this->assertSame('+919820012345', $d['phone']);
    }

    public function testDismissedCheckoutWithoutAnAddressPhoneFallsBackToTheCustomer(): void
    {
        $this->orders->method('load')->willReturn(Fixtures::order(['state' => 'pending_payment', 'phone' => null, 'customer_id' => 77]));
        $this->customers->method('defaultPhone')->with(77)->willReturn('098200 55555');
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $this->createMock(ContactStore::class),
            new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $e = $b->build($this->row('checkout_abandoned', 'order', 45, ['source' => 'dismissed']))->event;

        $this->assertSame('+919820055555', $e['details']['phone']);
        $this->assertSame('+919820055555', $e['fields']['phone'], 'same number onto the subscriber');
    }

    public function testContactCapturedCheckoutHasPhoneButNoOrderNumber(): void
    {
        $contacts = $this->createMock(ContactStore::class);
        $contacts->method('getById')->willReturn(['id' => 3, 'quote_id' => 9001, 'email' => 'cart+bentotest@x.com', 'phone' => '+919000000001']);
        $this->quotes->method('load')->willReturn($this->quote());
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $contacts, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $d = $b->build($this->row('checkout_abandoned', 'contact', 3, ['source' => 'contact_captured']))->event['details'];

        $this->assertSame('+919000000001', $d['phone'], 'the number typed in the popup wins');
        $this->assertArrayNotHasKey('order_increment_id', $d);
    }

    public function testCheckoutWithNoPhoneAnywhereSendsNullPhone(): void
    {
        $contacts = $this->createMock(ContactStore::class);
        $contacts->method('getById')->willReturn(['id' => 3, 'quote_id' => 9001, 'email' => 'cart+bentotest@x.com', 'phone' => null]);
        $this->quotes->method('load')->willReturn($this->quote(['phone' => null, 'customer_id' => null]));
        $this->customers->expects($this->never())->method('defaultPhone');
        $b = new CheckoutAbandonedBuilder($this->orders, $this->quotes, $contacts, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $d = $b->build($this->row('checkout_abandoned', 'contact', 3))->event['details'];

        $this->assertArrayHasKey('phone', $d);
        $this->assertNull($d['phone']);
    }

    public function testCartAbandonedCarriesPhoneFromTheQuoteElseTheCustomer(): void
    {
        $this->quotes->method('load')->willReturnOnConsecutiveCalls(
            $this->quote(),
            $this->quote(['phone' => '', 'customer_id' => 77]),
            $this->quote(['phone' => null, 'customer_id' => null])
        );
        $this->customers->expects($this->once())->method('defaultPhone')->with(77)->willReturn('+91 99000 11111');
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);

        $this->assertSame('+919876543210', $b->build($this->row('cart_abandoned', 'quote', 9001))->event['details']['phone']);
        $this->assertSame('+919900011111', $b->build($this->row('cart_abandoned', 'quote', 9001))->event['details']['phone']);
        $d = $b->build($this->row('cart_abandoned', 'quote', 9001))->event['details'];
        $this->assertNull($d['phone']);
        $this->assertArrayNotHasKey('order_increment_id', $d);
    }

    public function testCartAbandonedCarriesLivePricingFields(): void
    {
        $this->quotes->method('load')->willReturn($this->quote(['subtotal' => 3450.0, 'discount_amount' => 172.5]));
        $b = new CartAbandonedBuilder($this->quotes, new QuotePayloadMapper(), $this->urls, $this->gate(), $this->envelope, $this->customers, $this->ladder);
        $f = $b->build($this->row('cart_abandoned', 'quote', 9001))->event['fields'];

        $this->assertSame('₹173', $f['last_cart_discount_display'], 'what Magento takes off, not a typed %');
        $this->assertSame('yes', $f['last_cart_ships_free']);
        $this->assertSame('₹1,549', $f['last_cart_next_gap_display']);
        $this->assertSame('7.5% off', $f['last_cart_next_label']);
        $this->assertSame(LadderTest::LIVE_LINE, $f['ladder_line']);
        $this->assertSame(' ', $f['last_cart_coupon_value_display']);
    }
}
