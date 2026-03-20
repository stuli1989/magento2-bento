<?php
/**
 * Customer Updated Observer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Observer\Customer\CustomerUpdated;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomerUpdatedTest extends TestCase
{
    public function testExecutePublishesOnce(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new CustomerUpdated($config, $publisher, $serializer, $logger);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(10);
        $customer->method('getStoreId')->willReturn(1);
        $customer->method('getEmail')->willReturn('test@example.com');

        $origCustomer = $this->createMock(CustomerInterface::class);
        $origCustomer->method('getId')->willReturn(10);

        $event = $this->createMock(Event::class);
        $event->method('getCustomerDataObject')->willReturn($customer);
        $event->method('getOrigCustomerDataObject')->willReturn($origCustomer);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isTrackCustomerUpdatedEnabled')->willReturn(true);
        $serializer->method('serialize')->with(['id' => 10])->willReturn('{"id":10}');

        $publisher
            ->expects($this->once())
            ->method('publish');

        $observerInstance->execute($observer);
        $observerInstance->execute($observer);
    }

    public function testExecuteSkipsWhenOrigCustomerMissing(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new CustomerUpdated($config, $publisher, $serializer, $logger);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(10);
        $customer->method('getStoreId')->willReturn(1);

        $origCustomer = $this->createMock(CustomerInterface::class);
        $origCustomer->method('getId')->willReturn(null);

        $event = $this->createMock(Event::class);
        $event->method('getCustomerDataObject')->willReturn($customer);
        $event->method('getOrigCustomerDataObject')->willReturn($origCustomer);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $publisher->expects($this->never())->method('publish');

        $observerInstance->execute($observer);
    }
}
