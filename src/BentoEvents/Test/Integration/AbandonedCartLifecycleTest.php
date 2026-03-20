<?php
/**
 * Integration Test: Abandoned Cart Lifecycle
 *
 * Tests the Scheduler → Checker pipeline with real objects and a mock DB adapter.
 * Covers: schedule → check → trigger, freshness re-schedule, duplicate prevention.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Integration;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\AbandonedCart\Checker;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AbandonedCartLifecycleTest extends TestCase
{
    private Scheduler $scheduler;
    private Checker $checker;
    private MockObject $config;
    private MockObject $adapter;
    private MockObject $publisher;
    private MockObject $cartRepository;
    private MockObject $orderRepository;
    private MockObject $dateTime;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->adapter);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn($d) => json_encode($d));
        $serializer->method('unserialize')->willReturnCallback(fn($s) => json_decode($s, true));

        $logger = $this->createMock(LoggerInterface::class);

        $this->dateTime->method('gmtDate')->willReturn('2026-02-27 00:00:00');
        $this->dateTime->method('gmtTimestamp')->willReturn(1772092800);

        $this->scheduler = new Scheduler(
            $this->config,
            $resourceConnection,
            $this->publisher,
            $serializer,
            $this->dateTime,
            $logger
        );

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn(
            $this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class)
        );

        $this->checker = new Checker(
            $this->config,
            $this->cartRepository,
            $this->orderRepository,
            $searchCriteriaBuilder,
            $this->publisher,
            $serializer,
            $this->scheduler,
            $logger
        );
    }

    /**
     * Test full lifecycle: schedule a cart via cron mode, then check and trigger.
     */
    public function testScheduleThenCheckAndTrigger(): void
    {
        // Configure cron mode
        $this->config->method('getAbandonedCartProcessingMethod')->willReturn('cron');
        $this->config->method('getAbandonedCartDelay')->willReturn(60);
        $this->config->method('preventDuplicates')->willReturn(true);
        $this->config->method('getAbandonedCartDuplicateWindow')->willReturn(24);

        // Scheduler: expect insertOnDuplicate called (schedule the cart)
        $this->adapter->expects($this->atLeastOnce())
            ->method('insertOnDuplicate')
            ->with(
                'artlounge_bento_abandoned_cart_schedule',
                $this->callback(function (array $data) {
                    return $data['quote_id'] === 42
                        && $data['store_id'] === 1
                        && $data['customer_email'] === 'john@example.com'
                        && $data['status'] === 'pending';
                }),
                $this->anything()
            );

        $quote = $this->createQuoteMock(42, 1, 'john@example.com', '2026-02-26 23:00:00');
        $this->scheduler->scheduleCheck($quote);

        // Now check: quote still active, same updated_at, no orders, not already sent
        $this->cartRepository->method('get')->willReturn($quote);

        $orderResults = $this->createMock(OrderSearchResultInterface::class);
        $orderResults->method('getTotalCount')->willReturn(0);
        $this->orderRepository->method('getList')->willReturn($orderResults);

        // Scheduler mock: isAlreadySent returns false, hasRecentSentForCustomer returns false
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $this->adapter->method('select')->willReturn($select);
        $this->adapter->method('fetchOne')->willReturn(false);
        $this->adapter->method('fetchAll')->willReturn([]);

        // Expect the event to be published to event.trigger queue
        $this->publisher->expects($this->atLeastOnce())
            ->method('publish')
            ->with('event.trigger', $this->anything());

        // Expect markProcessed to update status to 'sent'
        $this->adapter->expects($this->atLeastOnce())
            ->method('update')
            ->with(
                'artlounge_bento_abandoned_cart_schedule',
                $this->callback(fn(array $data) => $data['status'] === 'sent'),
                $this->anything()
            );

        $triggered = $this->checker->checkAndTrigger(42, '2026-02-26 23:00:00');
        $this->assertTrue($triggered);
    }

    /**
     * Test that a modified cart (updated_at changed) is rescheduled instead of triggered.
     */
    public function testModifiedCartIsRescheduled(): void
    {
        $this->config->method('getAbandonedCartProcessingMethod')->willReturn('cron');
        $this->config->method('getAbandonedCartDelay')->willReturn(60);

        // Quote was updated after scheduling
        $quote = $this->createQuoteMock(42, 1, 'john@example.com', '2026-02-27 01:00:00');
        $this->cartRepository->method('get')->willReturn($quote);

        // Should call insertOnDuplicate (reschedule), NOT publish to event.trigger
        $this->adapter->expects($this->atLeastOnce())->method('insertOnDuplicate');
        $this->publisher->expects($this->never())->method('publish');

        // Original updated_at was different
        $triggered = $this->checker->checkAndTrigger(42, '2026-02-26 23:00:00');
        $this->assertFalse($triggered);
    }

    /**
     * Test that an inactive (converted) cart is marked as converted.
     */
    public function testConvertedCartIsMarkedCorrectly(): void
    {
        $quote = $this->createQuoteMock(42, 1, 'john@example.com', '2026-02-26 23:00:00', false);
        $this->cartRepository->method('get')->willReturn($quote);

        $this->adapter->expects($this->once())
            ->method('update')
            ->with(
                'artlounge_bento_abandoned_cart_schedule',
                $this->callback(fn(array $data) => $data['status'] === 'converted'),
                $this->anything()
            );

        $triggered = $this->checker->checkAndTrigger(42, '2026-02-26 23:00:00');
        $this->assertFalse($triggered);
    }

    /**
     * Test cleanup removes old entries.
     */
    public function testCleanupDeletesOldEntries(): void
    {
        $this->adapter->expects($this->once())
            ->method('delete')
            ->with('artlounge_bento_abandoned_cart_schedule', $this->anything())
            ->willReturn(5);

        $deleted = $this->scheduler->cleanup(7);
        $this->assertSame(5, $deleted);
    }

    private function createQuoteMock(
        int $quoteId,
        int $storeId,
        string $email,
        string $updatedAt,
        bool $isActive = true
    ): MockObject {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($quoteId);
        $quote->method('getStoreId')->willReturn($storeId);
        $quote->method('getCustomerEmail')->willReturn($email);
        $quote->method('getGrandTotal')->willReturn(150.00);
        $quote->method('getUpdatedAt')->willReturn($updatedAt);
        $quote->method('getIsActive')->willReturn($isActive);
        $quote->method('getItemsCount')->willReturn(2);
        return $quote;
    }
}
