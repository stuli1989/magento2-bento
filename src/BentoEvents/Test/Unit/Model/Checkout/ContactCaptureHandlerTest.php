<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Checkout;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Checkout\ContactCaptureHandler;
use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Razorpay\CheckoutContextInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContactCaptureHandlerTest extends TestCase
{
    private ContactStore $store;
    private array $stored = [];
    private array $rate = [];
    private bool $enabled = true;
    private ?string $loggedInEmail = null;
    private ContactCaptureHandler $handler;

    protected function setUp(): void
    {
        $this->store = $this->createMock(ContactStore::class);
        $this->store->method('upsert')->willReturnCallback(function (...$args) {
            $this->stored[] = $args;
        });
        $ctx = $this->createMock(CheckoutContextInterface::class);
        $ctx->method('sessionQuoteIdForRzpOrder')->willReturnCallback(
            fn ($rzp) => $rzp === 'order_PQ1abcDEF23xyz' ? 9001 : null
        );
        $ctx->method('storeId')->willReturn(1);
        $ctx->method('loggedInEmail')->willReturnCallback(fn () => $this->loggedInEmail);
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isContactCaptureEnabled')->willReturnCallback(fn () => $this->enabled);
        $config->method('getContactCaptureDelayMinutes')->willReturn(30);
        $this->handler = new ContactCaptureHandler($ctx, $this->store, $config, $this->createMock(LoggerInterface::class));
    }

    private function post(array $params, bool $formKeyValid = true): array
    {
        return $this->handler->handle($params + [
            'email' => 'Guest+bentotest@x.com', 'phone' => '98200 12345', 'rzp_order_id' => 'order_PQ1abcDEF23xyz',
        ], $formKeyValid, $this->rate);
    }

    public function testValidCaptureIsStoredWithDelay(): void
    {
        [$code, $body] = $this->post([]);
        $this->assertSame(200, $code);
        $this->assertSame(['ok' => true], $body);
        [$quoteId, $rzp, $email, $phone, $storeId, $due] = $this->stored[0];
        $this->assertSame(9001, $quoteId);
        $this->assertSame('order_PQ1abcDEF23xyz', $rzp);
        $this->assertSame('guest+bentotest@x.com', $email);
        $this->assertSame('+919820012345', $phone);
        $this->assertSame(1, $storeId);
        $this->assertEqualsWithDelta(time() + 1800, strtotime($due . ' UTC'), 5);
    }

    public function testLoggedInCustomerIsStoredUnderTheAccountNotRazorpaysContact(): void
    {
        $this->loggedInEmail = 'account+bentotest@x.com';
        $this->assertSame(200, $this->post([])[0]);
        $this->assertSame('account+bentotest@x.com', $this->stored[0][2]);
    }

    public function testBadFormKeyIs403(): void
    {
        $this->assertSame(403, $this->post([], false)[0]);
        $this->assertSame([], $this->stored);
    }

    public function testInvalidEmailIs400(): void
    {
        $this->assertSame(400, $this->post(['email' => 'not-an-email'])[0]);
        $this->assertSame([], $this->stored);
    }

    public function testMalformedRzpOrderIdIs400(): void
    {
        $this->assertSame(400, $this->post(['rzp_order_id' => "order_1'; DROP"])[0]);
    }

    public function testRzpOrderFromAnotherSessionIs404(): void
    {
        $this->assertSame(404, $this->post(['rzp_order_id' => 'order_SomeoneElse99'])[0]);
        $this->assertSame([], $this->stored);
    }

    public function testRateLimitTenPerMinutePerSession(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(200, $this->post([])[0]);
        }
        $this->assertSame(429, $this->post([])[0]);
        // window expires
        $this->rate['start'] = time() - 61;
        $this->assertSame(200, $this->post([])[0]);
    }

    public function testDisabledIs404(): void
    {
        $this->enabled = false;
        $this->assertSame(404, $this->post([])[0]);
    }

    public function testPhoneOptional(): void
    {
        $this->assertSame(200, $this->post(['phone' => ''])[0]);
        $this->assertNull($this->stored[0][3]);
    }
}
