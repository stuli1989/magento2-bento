<?php
/**
 * Order Status Changed Observer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Observer\Sales\OrderStatusChanged;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderStatusChangedTest extends TestCase
{
    public function testExecutePublishesWhenCancelled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderStatusChanged($config, $publisher, $serializer, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $order->method('dataHasChangedFor')->with('state')->willReturn(true);
        $order->method('getEntityId')->willReturn(10);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('00001');

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn($order);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackOrderCancelledEnabled')->willReturn(true);
        $serializer->method('serialize')->with(['id' => 10])->willReturn('{"id":10}');

        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with('event.trigger', ['bento.order.cancelled', '{"id":10}']);

        $observerInstance->execute($observer);
    }

    public function testExecuteSkipsWhenStateNotCancelled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderStatusChanged($config, $publisher, $serializer, $logger);

        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);

        $event = $this->createMock(Event::class);
        $event->method('getOrder')->willReturn($order);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }
}
