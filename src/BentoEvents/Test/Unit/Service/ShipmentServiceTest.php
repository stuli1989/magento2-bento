<?php
/**
 * Shipment Service Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Service\OrderService;
use ArtLounge\BentoEvents\Service\ShipmentService;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentItemInterface;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShipmentServiceTest extends TestCase
{
    private ShipmentService $service;
    private MockObject $shipmentRepository;
    private MockObject $orderRepository;
    private MockObject $orderService;
    private MockObject $config;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderService = $this->createMock(OrderService::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ShipmentService(
            $this->shipmentRepository,
            $this->orderRepository,
            $this->orderService,
            $this->config,
            $this->logger
        );
    }

    public function testFormatShipmentDataIncludesTracksAndItems(): void
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $track = $this->createMock(ShipmentTrackInterface::class);
        $item = $this->createMock(ShipmentItemInterface::class);

        $shipment->method('getOrderId')->willReturn(99);
        $this->orderRepository->method('get')->with(99)->willReturn($order);
        $shipment->method('getTracks')->willReturn([$track]);
        $shipment->method('getItems')->willReturn([$item]);
        $shipment->method('getEntityId')->willReturn(10);
        $shipment->method('getIncrementId')->willReturn('00001');
        $shipment->method('getCreatedAt')->willReturn('2026-01-24');

        $order->method('getStoreId')->willReturn(1);
        $this->orderService->method('formatOrderData')->willReturn(['event_type' => '$purchase']);

        $track->method('getCarrierCode')->willReturn('ups');
        $track->method('getTitle')->willReturn('UPS');
        $track->method('getTrackNumber')->willReturn('1Z');

        $item->method('getSku')->willReturn('SKU');
        $item->method('getName')->willReturn('Item');
        $item->method('getQty')->willReturn(1);

        $result = $this->service->formatShipmentData($shipment);

        $this->assertSame('$OrderShipped', $result['event_type']);
        $this->assertCount(1, $result['shipment']['tracks']);
        $this->assertCount(1, $result['shipment']['shipped_items']);
    }

    public function testGetShipmentDataThrowsOnFailure(): void
    {
        $this->shipmentRepository
            ->method('get')
            ->willThrowException(new \RuntimeException('fail'));

        $this->expectException(\RuntimeException::class);

        $this->service->getShipmentData(99);
    }
}
