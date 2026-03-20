<?php
/**
 * Order Shipped Observer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Observer\Sales\OrderShipped;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderShippedTest extends TestCase
{
    public function testExecutePublishesEventWhenEnabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderShipped($config, $publisher, $serializer, $logger);

        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getEntityId')->willReturn(10);
        $shipment->method('getStoreId')->willReturn(1);
        $shipment->method('getOrderId')->willReturn(55);

        $event = $this->createMock(Event::class);
        $event->method('getShipment')->willReturn($shipment);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackOrderShippedEnabled')->willReturn(true);
        $serializer->method('serialize')->with(['id' => 10])->willReturn('{"id":10}');

        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with('event.trigger', ['bento.order.shipped', '{"id":10}']);

        $observerInstance->execute($observer);
    }

    public function testExecuteSkipsWhenNoShipment(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderShipped($config, $publisher, $serializer, $logger);

        $event = $this->createMock(Event::class);
        $event->method('getShipment')->willReturn(null);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }
}
