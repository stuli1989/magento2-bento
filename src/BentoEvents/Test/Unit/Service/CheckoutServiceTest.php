<?php
/**
 * CheckoutService Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoEvents\Service\AbandonedCartService;
use ArtLounge\BentoEvents\Service\CheckoutService;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CheckoutServiceTest extends TestCase
{
    private CheckoutService $service;
    private MockObject $abandonedCartService;
    private MockObject $cartRepository;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->abandonedCartService = $this->createMock(AbandonedCartService::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new CheckoutService(
            $this->abandonedCartService,
            $this->cartRepository,
            $this->logger
        );
    }

    public function testGetCheckoutStartedDataOverridesEventType(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $this->cartRepository->method('get')->willReturn($quote);

        $this->abandonedCartService->method('formatAbandonedCartData')
            ->willReturn($this->getSamplePayload());

        $result = $this->service->getCheckoutStartedData(100);

        $this->assertSame('$checkoutStarted', $result['event_type']);
    }

    public function testRemovesAbandonedDurationMinutes(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $this->cartRepository->method('get')->willReturn($quote);

        $this->abandonedCartService->method('formatAbandonedCartData')
            ->willReturn($this->getSamplePayload());

        $result = $this->service->getCheckoutStartedData(100);

        $this->assertArrayNotHasKey('abandoned_duration_minutes', $result['cart']);
    }

    public function testDelegatesToAbandonedCartService(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $this->cartRepository->method('get')->willReturn($quote);

        $this->abandonedCartService->expects($this->once())
            ->method('formatAbandonedCartData')
            ->willReturn($this->getSamplePayload());

        $this->service->getCheckoutStartedData(100);
    }

    private function getSamplePayload(): array
    {
        return [
            'event_type' => '$cart_abandoned',
            'cart_id' => 100,
            'cart' => [
                'quote_id' => 100,
                'abandoned_duration_minutes' => 60,
            ],
            'financials' => ['total_value' => 5000],
            'items' => [],
            'customer' => ['email' => 'test@example.com'],
        ];
    }
}
