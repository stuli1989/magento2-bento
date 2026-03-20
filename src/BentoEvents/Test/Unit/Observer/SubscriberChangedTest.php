<?php
/**
 * Subscriber Changed Observer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Observer\Newsletter\SubscriberChanged;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Newsletter\Model\Subscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubscriberChangedTest extends TestCase
{
    public function testExecutePublishesSubscribe(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new SubscriberChanged($config, $publisher, $serializer, $logger);

        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('test@example.com');

        $event = $this->createMock(Event::class);
        $event->method('getSubscriber')->willReturn($subscriber);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackSubscribeEnabled')->willReturn(true);
        $serializer->method('serialize')->with(['id' => 10])->willReturn('{"id":10}');

        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with('event.trigger', ['bento.newsletter.subscribed', '{"id":10}']);

        $observerInstance->execute($observer);
    }

    public function testExecutePublishesUnsubscribe(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new SubscriberChanged($config, $publisher, $serializer, $logger);

        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_UNSUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);

        $event = $this->createMock(Event::class);
        $event->method('getSubscriber')->willReturn($subscriber);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackUnsubscribeEnabled')->willReturn(true);
        $serializer->method('serialize')->with(['id' => 10])->willReturn('{"id":10}');

        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with('event.trigger', ['bento.newsletter.unsubscribed', '{"id":10}']);

        $observerInstance->execute($observer);
    }

    public function testExecuteSkipsPendingStatus(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new SubscriberChanged($config, $publisher, $serializer, $logger);

        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_NOT_ACTIVE);

        $event = $this->createMock(Event::class);
        $event->method('getSubscriber')->willReturn($subscriber);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }

    public function testExecuteSkipsUnconfirmedStatus(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new SubscriberChanged($config, $publisher, $serializer, $logger);

        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_UNCONFIRMED);

        $event = $this->createMock(Event::class);
        $event->method('getSubscriber')->willReturn($subscriber);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }

    public function testExecuteReturnsEarlyWhenNoSubscriber(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new SubscriberChanged($config, $publisher, $serializer, $logger);

        $event = $this->createMock(Event::class);
        $event->method('getSubscriber')->willReturn(null);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }

    public function testExecuteDoesNotPublishSubscribeWhenDisabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new SubscriberChanged($config, $publisher, $serializer, $logger);

        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);

        $event = $this->createMock(Event::class);
        $event->method('getSubscriber')->willReturn($subscriber);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackSubscribeEnabled')->willReturn(false);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }

    public function testExecuteDoesNotPublishUnsubscribeWhenDisabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new SubscriberChanged($config, $publisher, $serializer, $logger);

        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_UNSUBSCRIBED);

        $event = $this->createMock(Event::class);
        $event->method('getSubscriber')->willReturn($subscriber);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackUnsubscribeEnabled')->willReturn(false);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }

    public function testExecuteLogsErrorOnPublisherException(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new SubscriberChanged($config, $publisher, $serializer, $logger);

        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);

        $event = $this->createMock(Event::class);
        $event->method('getSubscriber')->willReturn($subscriber);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackSubscribeEnabled')->willReturn(true);
        $serializer->method('serialize')->willReturn('{"id":10}');

        $publisher->method('publish')
            ->willThrowException(new \RuntimeException('Queue error'));

        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to queue newsletter event', $this->anything());

        $observerInstance->execute($observer);
    }
}
