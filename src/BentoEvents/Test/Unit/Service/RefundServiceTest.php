<?php
/**
 * Refund Service Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Service\OrderService;
use ArtLounge\BentoEvents\Service\RefundService;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RefundServiceTest extends TestCase
{
    private RefundService $service;
    private MockObject $creditmemoRepository;
    private MockObject $orderService;
    private MockObject $config;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->creditmemoRepository = $this->createMock(CreditmemoRepositoryInterface::class);
        $this->orderService = $this->createMock(OrderService::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RefundService(
            $this->creditmemoRepository,
            $this->orderService,
            $this->config,
            $this->logger
        );
    }

    public function testFormatRefundDataIncludesRefundDetails(): void
    {
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $item = $this->createMock(CreditmemoItemInterface::class);

        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getItems')->willReturn([$item]);
        $creditmemo->method('getEntityId')->willReturn(10);
        $creditmemo->method('getIncrementId')->willReturn('00001');
        $creditmemo->method('getCreatedAt')->willReturn('2026-01-24');
        $creditmemo->method('getGrandTotal')->willReturn(50.0);
        $creditmemo->method('getAdjustmentPositive')->willReturn(5.0);
        $creditmemo->method('getAdjustmentNegative')->willReturn(2.0);

        $order->method('getStoreId')->willReturn(1);
        $this->orderService->method('formatOrderData')->willReturn(['event_type' => '$purchase']);
        $this->config->method('getCurrencyMultiplier')->willReturn(100);

        $item->method('getQty')->willReturn(1);
        $item->method('getSku')->willReturn('SKU');
        $item->method('getName')->willReturn('Item');
        $item->method('getRowTotal')->willReturn(50.0);

        $result = $this->service->formatRefundData($creditmemo);

        $this->assertSame('$OrderRefunded', $result['event_type']);
        $this->assertSame(5000, $result['refund']['refund_amount']);
        $this->assertCount(1, $result['refund']['refunded_items']);
    }

    public function testGetRefundDataThrowsOnFailure(): void
    {
        $this->creditmemoRepository
            ->method('get')
            ->willThrowException(new \RuntimeException('fail'));

        $this->expectException(\RuntimeException::class);

        $this->service->getRefundData(99);
    }
}
