<?php
/**
 * Customer Created Observer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Observer\Customer\CustomerCreated;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomerCreatedTest extends TestCase
{
    public function testExecutePublishesEventWhenEnabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new CustomerCreated($config, $publisher, $serializer, $logger);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(10);
        $customer->method('getStoreId')->willReturn(1);
        $customer->method('getEmail')->willReturn('test@example.com');

        $event = $this->createMock(Event::class);
        $event->method('getCustomer')->willReturn($customer);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackCustomerCreatedEnabled')->willReturn(true);
        $serializer->method('serialize')->with(['id' => 10])->willReturn('{"id":10}');

        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with('event.trigger', ['bento.customer.created', '{"id":10}']);

        $observerInstance->execute($observer);
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new CustomerCreated($config, $publisher, $serializer, $logger);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(1);

        $event = $this->createMock(Event::class);
        $event->method('getCustomer')->willReturn($customer);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackCustomerCreatedEnabled')->willReturn(false);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }
}
