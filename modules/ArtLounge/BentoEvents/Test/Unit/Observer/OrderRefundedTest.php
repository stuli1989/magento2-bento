<?php
/**
 * Order Refunded Observer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Observer\Sales\OrderRefunded;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderRefundedTest extends TestCase
{
    public function testExecutePublishesEventWhenEnabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderRefunded($config, $publisher, $serializer, $logger);

        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getEntityId')->willReturn(10);
        $creditmemo->method('getStoreId')->willReturn(1);
        $creditmemo->method('getOrderId')->willReturn(55);

        $event = $this->createMock(Event::class);
        $event->method('getCreditmemo')->willReturn($creditmemo);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackOrderRefundedEnabled')->willReturn(true);
        $serializer->method('serialize')->with(['id' => 10])->willReturn('{"id":10}');

        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with('event.trigger', ['bento.order.refunded', '{"id":10}']);

        $observerInstance->execute($observer);
    }

    public function testExecuteSkipsWhenNoCreditmemo(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new OrderRefunded($config, $publisher, $serializer, $logger);

        $event = $this->createMock(Event::class);
        $event->method('getCreditmemo')->willReturn(null);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }
}
