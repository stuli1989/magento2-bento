<?php
/**
 * Order Placed Observer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Observer\Sales\OrderPlaced;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderPlacedTest extends TestCase
{
    public function testExecutePublishesEventWhenEnabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderPlaced($config, $publisher, $serializer, $logger);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(123);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000123');

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn($order);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackOrderPlacedEnabled')->with(1)->willReturn(true);
        $serializer->method('serialize')->with(['id' => 123])->willReturn('{"id":123}');

        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with('event.trigger', ['bento.order.placed', '{"id":123}']);

        $observerInstance->execute($observer);
    }

    public function testExecuteDoesNotPublishWhenDisabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderPlaced($config, $publisher, $serializer, $logger);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn($order);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackOrderPlacedEnabled')->willReturn(false);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }

    public function testExecuteReturnsEarlyWhenNoOrder(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderPlaced($config, $publisher, $serializer, $logger);

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn(null);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }

    public function testExecuteLogsErrorOnPublisherException(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderPlaced($config, $publisher, $serializer, $logger);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(123);
        $order->method('getStoreId')->willReturn(1);

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn($order);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackOrderPlacedEnabled')->willReturn(true);
        $serializer->method('serialize')->willReturn('{"id":123}');

        $publisher->method('publish')
            ->willThrowException(new \RuntimeException('Queue unavailable'));

        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to queue order placed event', $this->anything());

        $observerInstance->execute($observer);
    }
}
