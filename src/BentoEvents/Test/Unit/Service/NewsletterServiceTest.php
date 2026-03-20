<?php
/**
 * Newsletter Service Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Service\NewsletterService;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NewsletterServiceTest extends TestCase
{
    private NewsletterService $service;
    private MockObject $subscriberFactory;
    private MockObject $config;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->subscriberFactory = $this->createMock(SubscriberFactory::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new NewsletterService(
            $this->subscriberFactory,
            $this->config,
            $this->logger
        );
    }

    public function testGetSubscriberDataFormatsSubscribe(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('load')->willReturnSelf();
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('test@example.com');
        $subscriber->method('getCustomerId')->willReturn(5);
        $subscriber->method('getChangeStatusAt')->willReturn('2026-01-24');

        $this->subscriberFactory->method('create')->willReturn($subscriber);
        $this->config->method('getSubscribeTags')->willReturn(['tag']);

        $result = $this->service->getSubscriberData(10);

        $this->assertSame('$subscribe', $result['event_type']);
        $this->assertSame(['tag'], $result['tags']);
    }

    public function testFormatSubscriberDataUnsubscribe(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_UNSUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('test@example.com');

        $this->config->method('getSubscribeTags')->willReturn(['tag']);

        $result = $this->service->formatSubscriberData($subscriber);

        $this->assertSame('$unsubscribe', $result['event_type']);
        $this->assertSame([], $result['tags']);
    }

    public function testGetSubscriberDataThrowsOnFailure(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('load')->willThrowException(new \RuntimeException('fail'));
        $this->subscriberFactory->method('create')->willReturn($subscriber);

        $this->expectException(\RuntimeException::class);

        $this->service->getSubscriberData(99);
    }

    public function testGetSubscriberDataLogsErrorOnFailure(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('load')->willThrowException(new \RuntimeException('fail'));
        $this->subscriberFactory->method('create')->willReturn($subscriber);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to get subscriber data for Bento', $this->anything());

        try {
            $this->service->getSubscriberData(99);
        } catch (\RuntimeException $e) {
            // expected
        }
    }

    public function testFormatSubscriberDataIncludesCustomerId(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('test@example.com');
        $subscriber->method('getCustomerId')->willReturn(42);
        $subscriber->method('getChangeStatusAt')->willReturn('2026-01-24');

        $this->config->method('getSubscribeTags')->willReturn([]);

        $result = $this->service->formatSubscriberData($subscriber);

        $this->assertSame(42, $result['subscriber']['customer_id']);
    }

    public function testFormatSubscriberDataGuestHasNullCustomerId(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('guest@example.com');
        $subscriber->method('getCustomerId')->willReturn(null);
        $subscriber->method('getChangeStatusAt')->willReturn('2026-01-24');

        $this->config->method('getSubscribeTags')->willReturn([]);

        $result = $this->service->formatSubscriberData($subscriber);

        $this->assertNull($result['subscriber']['customer_id']);
    }

    public function testFormatSubscriberDataStatusLabels(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('test@example.com');
        $subscriber->method('getCustomerId')->willReturn(null);
        $subscriber->method('getChangeStatusAt')->willReturn('2026-01-24');

        $this->config->method('getSubscribeTags')->willReturn([]);

        $result = $this->service->formatSubscriberData($subscriber);

        $this->assertSame('subscribed', $result['subscriber']['status']);
    }

    public function testFormatSubscriberDataUnsubscribedStatusLabel(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_UNSUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('test@example.com');
        $subscriber->method('getCustomerId')->willReturn(null);
        $subscriber->method('getChangeStatusAt')->willReturn('2026-01-24');

        $this->config->method('getSubscribeTags')->willReturn([]);

        $result = $this->service->formatSubscriberData($subscriber);

        $this->assertSame('unsubscribed', $result['subscriber']['status']);
        $this->assertSame('$unsubscribe', $result['event_type']);
        $this->assertSame([], $result['tags']);
    }

    public function testFormatSubscriberDataIncludesStoreId(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(3);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('test@example.com');
        $subscriber->method('getCustomerId')->willReturn(null);
        $subscriber->method('getChangeStatusAt')->willReturn('2026-01-24');

        $this->config->method('getSubscribeTags')->willReturn([]);

        $result = $this->service->formatSubscriberData($subscriber);

        $this->assertSame(3, $result['store']['store_id']);
    }

    public function testFormatSubscriberDataSubscribeIncludesTags(): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStoreId')->willReturn(1);
        $subscriber->method('getSubscriberStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getId')->willReturn(10);
        $subscriber->method('getSubscriberEmail')->willReturn('test@example.com');
        $subscriber->method('getCustomerId')->willReturn(null);
        $subscriber->method('getChangeStatusAt')->willReturn('2026-01-24');

        $this->config->method('getSubscribeTags')->willReturn(['newsletter', 'marketing']);

        $result = $this->service->formatSubscriberData($subscriber);

        $this->assertSame(['newsletter', 'marketing'], $result['tags']);
    }
}
