<?php
/**
 * Abandoned Cart Checker Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\AbandonedCart;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\AbandonedCart\Checker;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CheckerTest extends TestCase
{
    private Checker $checker;
    private MockObject $config;
    private MockObject $cartRepository;
    private MockObject $orderRepository;
    private MockObject $searchCriteriaBuilder;
    private MockObject $publisher;
    private MockObject $serializer;
    private MockObject $scheduler;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->scheduler = $this->createMock(Scheduler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->checker = new Checker(
            $this->config,
            $this->cartRepository,
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->publisher,
            $this->serializer,
            $this->scheduler,
            $this->logger
        );
    }

    public function testCheckAndTriggerMarksNotFound(): void
    {
        $this->cartRepository
            ->method('get')
            ->willThrowException(new \Exception('not found'));

        $this->scheduler
            ->expects($this->once())
            ->method('markProcessed')
            ->with(10, 'not_found');

        $this->assertFalse($this->checker->checkAndTrigger(10));
    }

    public function testCheckAndTriggerSkipsInactiveQuote(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn(false);
        $quote->method('getStoreId')->willReturn(1);

        $this->cartRepository->method('get')->willReturn($quote);

        $this->scheduler
            ->expects($this->once())
            ->method('markProcessed')
            ->with(10, 'converted');

        $this->assertFalse($this->checker->checkAndTrigger(10));
    }

    public function testCheckAndTriggerReschedulesWhenQuoteUpdated(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');

        $this->cartRepository->method('get')->willReturn($quote);

        $this->scheduler
            ->expects($this->once())
            ->method('scheduleCheck')
            ->with($quote);

        $this->assertFalse($this->checker->checkAndTrigger(10, '2026-01-24 09:00:00'));
    }

    public function testCheckAndTriggerSkipsWhenOrderExists(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getCustomerEmail')->willReturn('test@example.com');

        $this->cartRepository->method('get')->willReturn($quote);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class));

        $orders = $this->createMock(OrderSearchResultInterface::class);
        $orders->method('getTotalCount')->willReturn(1);
        $this->orderRepository->method('getList')->willReturn($orders);

        $this->scheduler
            ->expects($this->once())
            ->method('markProcessed')
            ->with(10, 'ordered');

        $this->assertFalse($this->checker->checkAndTrigger(10));
    }

    public function testCheckAndTriggerSkipsWhenDuplicateWindowMatches(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getCustomerEmail')->willReturn('test@example.com');

        $this->cartRepository->method('get')->willReturn($quote);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class));

        $orders = $this->createMock(OrderSearchResultInterface::class);
        $orders->method('getTotalCount')->willReturn(0);
        $this->orderRepository->method('getList')->willReturn($orders);

        $this->config->method('preventDuplicates')->willReturn(true);
        $this->config->method('getAbandonedCartDuplicateWindow')->willReturn(24);
        $this->scheduler->method('hasRecentSentForCustomer')->willReturn(true);
        $this->scheduler
            ->expects($this->once())
            ->method('markProcessed')
            ->with(10, 'duplicate_window');

        $this->assertFalse($this->checker->checkAndTrigger(10));
    }

    public function testCheckAndTriggerSkipsWhenMissingEmail(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getCustomerEmail')->willReturn(null);

        $this->cartRepository->method('get')->willReturn($quote);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class));

        $orders = $this->createMock(OrderSearchResultInterface::class);
        $orders->method('getTotalCount')->willReturn(0);
        $this->orderRepository->method('getList')->willReturn($orders);

        $this->scheduler
            ->expects($this->once())
            ->method('markProcessed')
            ->with(10, 'no_email');

        $this->assertFalse($this->checker->checkAndTrigger(10));
    }

    public function testCheckAndTriggerPublishesEventWhenValid(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getGrandTotal')->willReturn(100.0);

        $this->cartRepository->method('get')->willReturn($quote);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class));

        $orders = $this->createMock(OrderSearchResultInterface::class);
        $orders->method('getTotalCount')->willReturn(0);
        $this->orderRepository->method('getList')->willReturn($orders);

        $this->config->method('preventDuplicates')->willReturn(false);
        $this->serializer->method('serialize')->willReturn('{"id":10}');

        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('event.trigger', ['bento.cart.abandoned', '{"id":10}']);

        $this->scheduler
            ->expects($this->once())
            ->method('markProcessed')
            ->with(10, 'sent');

        $this->assertTrue($this->checker->checkAndTrigger(10));
    }

    public function testCheckAndTriggerHandlesPublishException(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getCustomerEmail')->willReturn('test@example.com');

        $this->cartRepository->method('get')->willReturn($quote);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class));

        $orders = $this->createMock(OrderSearchResultInterface::class);
        $orders->method('getTotalCount')->willReturn(0);
        $this->orderRepository->method('getList')->willReturn($orders);

        $this->serializer->method('serialize')->willReturn('{"id":10}');
        $this->publisher->method('publish')->willThrowException(new \Exception('publish failed'));

        $this->scheduler
            ->expects($this->once())
            ->method('markProcessed')
            ->with(10, 'error');

        $this->assertFalse($this->checker->checkAndTrigger(10));
    }

    public function testCheckAndTriggerReturnsFalseWhenGetListThrows(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getCustomerEmail')->willReturn('test@example.com');

        $this->cartRepository->method('get')->willReturn($quote);
        $this->orderRepository->method('getList')->willThrowException(new \RuntimeException('fail'));

        $this->config->method('preventDuplicates')->willReturn(false);
        $this->serializer->method('serialize')->willReturn('{"id":10}');

        $this->publisher
            ->expects($this->once())
            ->method('publish');

        $this->scheduler
            ->expects($this->once())
            ->method('markProcessed')
            ->with(10, 'sent');

        $this->assertTrue($this->checker->checkAndTrigger(10));
    }
}
