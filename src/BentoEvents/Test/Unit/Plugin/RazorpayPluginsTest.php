<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Plugin;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Razorpay\CheckoutContextInterface;
use ArtLounge\BentoEvents\Model\Razorpay\ResultReader;
use ArtLounge\BentoEvents\Plugin\Razorpay\AbandonedQuotePlugin;
use ArtLounge\BentoEvents\Plugin\Razorpay\PlaceOrderPlugin;
use ArtLounge\BentoEvents\Test\Unit\Fake\InMemoryOutboxStore;
use Magento\Framework\Controller\Result\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Controller\OneClick\AbandonedQuote;
use Razorpay\Magento\Controller\OneClick\PlaceOrder;

/**
 * Recorded Razorpay controller responses (shapes copied from
 * Razorpay\Magento\Controller\OneClick\PlaceOrder / AbandonedQuote).
 */
class RazorpayPluginsTest extends TestCase
{
    private const PLACE_ORDER_SUCCESS = '{"status":"success","rzp_key_id":"rzp_live_x","allow_coupon_application":true,'
        . '"rzp_order_id":"order_PQ1abcDEF23xyz","items":[{"sku":"WN-1","quantity":1}],"message":"Razorpay Order created successfully"}';
    private const PLACE_ORDER_ERROR = '{"status":"error","message":"Razorpay Order not generated. Something went wrong"}';
    private const ABANDONED_SUCCESS = '{"status":"success","message":"Successfully updated the quote","orderPlacement":true}';
    private const ABANDONED_FAILED = '{"status":"Failed","code":"BAD_REQUEST","message":"Quote update failed for the reason : x"}';

    private InMemoryOutboxStore $outbox;
    private Enqueuer $enqueuer;
    private CheckoutContextInterface $ctx;
    private ?string $loggedInEmail = 'kshitij+bentotest@gmail.com';
    private ?array $order = null;

    protected function setUp(): void
    {
        $this->outbox = new InMemoryOutboxStore();
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(true);
        $this->enqueuer = new Enqueuer($this->outbox, $config, $this->createMock(LoggerInterface::class));
        $this->ctx = $this->createMock(CheckoutContextInterface::class);
        $this->ctx->method('loggedInEmail')->willReturnCallback(fn () => $this->loggedInEmail);
        $this->ctx->method('sessionQuoteIdForRzpOrder')->willReturnCallback(
            fn ($rzp) => $rzp === 'order_PQ1abcDEF23xyz' ? 9001 : null
        );
        $this->ctx->method('maskedQuoteId')->willReturn('mAsKeD123');
        $this->ctx->method('orderForRzpOrder')->willReturnCallback(fn () => $this->order);
        $this->ctx->method('storeId')->willReturn(1);
    }

    private function json(string $body, ?int $code = null): Json
    {
        $r = (new Json())->setJsonData($body);
        if ($code !== null) {
            $r->setHttpResponseCode($code);
        }
        return $r;
    }

    private function placeOrderPlugin(): PlaceOrderPlugin
    {
        return new PlaceOrderPlugin($this->ctx, new ResultReader(), $this->enqueuer, $this->createMock(LoggerInterface::class));
    }

    private function abandonedPlugin(?ContactStore $contacts = null): AbandonedQuotePlugin
    {
        return new AbandonedQuotePlugin(
            $this->ctx,
            new ResultReader(),
            $this->enqueuer,
            $contacts ?? $this->createMock(ContactStore::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testResultReaderReadsBodyAndCode(): void
    {
        $r = (new ResultReader())->read($this->json(self::ABANDONED_FAILED, 422));
        $this->assertSame(422, $r['code']);
        $this->assertSame('Failed', $r['data']['status']);
        $this->assertSame(200, (new ResultReader())->read($this->json('{}'))['code']);
        $this->assertNull((new ResultReader())->read(new \stdClass())['data']);
    }

    public function testCheckoutStartedForLoggedInCustomerOncePerMaskedQuote(): void
    {
        $plugin = $this->placeOrderPlugin();
        $result = $this->json(self::PLACE_ORDER_SUCCESS);

        $this->assertSame($result, $plugin->afterExecute(new PlaceOrder(), $result), 'result passes through unchanged');
        $plugin->afterExecute(new PlaceOrder(), $this->json(self::PLACE_ORDER_SUCCESS));

        $this->assertCount(1, $this->outbox->rows);
        $row = $this->outbox->rows[1];
        $this->assertSame('checkout_started', $row['event_type']);
        $this->assertSame('quote', $row['entity_type']);
        $this->assertSame(9001, $row['entity_id']);
        $this->assertSame('checkout_started:mAsKeD123', $row['dedup_key']);
        $this->assertSame('order_PQ1abcDEF23xyz', json_decode($row['context'], true)['rzp_order_id']);
    }

    public function testGuestPopupOpenQueuesNothing(): void
    {
        $this->loggedInEmail = null;
        $this->placeOrderPlugin()->afterExecute(new PlaceOrder(), $this->json(self::PLACE_ORDER_SUCCESS));
        $this->assertCount(0, $this->outbox->rows);
    }

    public function testRazorpayErrorQueuesNothing(): void
    {
        $this->placeOrderPlugin()->afterExecute(new PlaceOrder(), $this->json(self::PLACE_ORDER_ERROR));
        $this->assertCount(0, $this->outbox->rows);
    }

    public function testPluginExceptionsNeverReachCheckout(): void
    {
        $ctx = $this->createMock(CheckoutContextInterface::class);
        $ctx->method('loggedInEmail')->willThrowException(new \RuntimeException('session broken'));
        $plugin = new PlaceOrderPlugin($ctx, new ResultReader(), $this->enqueuer, $this->createMock(LoggerInterface::class));
        $result = $this->json(self::PLACE_ORDER_SUCCESS);
        $this->assertSame($result, $plugin->afterExecute(new PlaceOrder(), $result));
    }

    public function testDismissedPopupGoesToTheLoggedInAccountNotRazorpaysSavedContact(): void
    {
        $this->order = ['order_id' => 45, 'increment_id' => '100045', 'quote_id' => 9001,
            'email' => 'razorpay-contact@x.com', 'store_id' => 1, 'state' => 'pending_payment'];

        $this->abandonedPlugin($this->createMock(ContactStore::class))->afterExecute(
            new AbandonedQuote(['rzp_order_id' => 'order_PQ1abcDEF23xyz']),
            $this->json(self::ABANDONED_SUCCESS, 200)
        );

        $this->assertSame('kshitij+bentotest@gmail.com', $this->outbox->rows[1]['email']);
    }

    public function testDismissedPopupWithPendingPaymentOrderQueuesCheckoutAbandoned(): void
    {
        $this->loggedInEmail = null;
        $this->order = ['order_id' => 45, 'increment_id' => '100045', 'quote_id' => 9001,
            'email' => 'Guest+bentotest@x.com', 'store_id' => 1, 'state' => 'pending_payment'];
        $contacts = $this->createMock(ContactStore::class);
        $contacts->expects($this->once())->method('cancelForRzpOrder')->with('order_PQ1abcDEF23xyz');

        $this->abandonedPlugin($contacts)->afterExecute(
            new AbandonedQuote(['rzp_order_id' => 'order_PQ1abcDEF23xyz']),
            $this->json(self::ABANDONED_SUCCESS, 200)
        );

        $row = $this->outbox->rows[1];
        $this->assertSame('checkout_abandoned', $row['event_type']);
        $this->assertSame('order', $row['entity_type']);
        $this->assertSame(45, $row['entity_id']);
        $this->assertSame('guest+bentotest@x.com', $row['email']);
        $this->assertSame('checkout_abandoned:9001:order_PQ1abcDEF23xyz', $row['dedup_key']);
        $this->assertSame('dismissed', json_decode($row['context'], true)['source']);
    }

    public function testFailedAbandonedQuoteQueuesNothing(): void
    {
        $this->order = ['order_id' => 45, 'increment_id' => '100045', 'quote_id' => 9001,
            'email' => 'g@x.com', 'store_id' => 1, 'state' => 'pending_payment'];
        $this->abandonedPlugin()->afterExecute(
            new AbandonedQuote(['rzp_order_id' => 'order_PQ1abcDEF23xyz']),
            $this->json(self::ABANDONED_FAILED, 422)
        );
        $this->assertCount(0, $this->outbox->rows);
    }

    public function testAlreadyPaidOrderIsNotAbandoned(): void
    {
        $this->order = ['order_id' => 45, 'increment_id' => '100045', 'quote_id' => 9001,
            'email' => 'g@x.com', 'store_id' => 1, 'state' => 'processing'];
        $this->abandonedPlugin()->afterExecute(
            new AbandonedQuote(['rzp_order_id' => 'order_PQ1abcDEF23xyz']),
            $this->json(self::ABANDONED_SUCCESS, 200)
        );
        $this->assertCount(0, $this->outbox->rows);
    }

    public function testDismissAndContactTimeoutShareOneDedupKey(): void
    {
        $this->order = ['order_id' => 45, 'increment_id' => '100045', 'quote_id' => 9001,
            'email' => 'g@x.com', 'store_id' => 1, 'state' => 'pending_payment'];
        $this->abandonedPlugin()->afterExecute(
            new AbandonedQuote(['rzp_order_id' => 'order_PQ1abcDEF23xyz']),
            $this->json(self::ABANDONED_SUCCESS, 200)
        );
        // The contact-capture timeout for the same attempt uses the same key → ignored.
        $this->assertFalse($this->enqueuer->enqueue('checkout_abandoned', 'contact', 3, 'g@x.com', 1,
            AbandonedQuotePlugin::dedupKey(9001, 'order_PQ1abcDEF23xyz')));
        $this->assertCount(1, $this->outbox->rows);
    }
}
