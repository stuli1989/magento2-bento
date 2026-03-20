<?php
/**
 * Quote Saved Observer Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use ArtLounge\BentoEvents\Observer\Quote\QuoteSaved;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QuoteSavedTest extends TestCase
{
    public function testExecuteSchedulesWhenEligible(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $scheduler = $this->createMock(Scheduler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new QuoteSaved($config, $scheduler, $logger);

        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getItemsCount')->willReturn(2);
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getGrandTotal')->willReturn(200.0);
        $quote->method('getCustomerGroupId')->willReturn(1);

        $event = $this->createMock(Event::class);
        $event->method('getQuote')->willReturn($quote);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('isAbandonedCartEmailRequired')->willReturn(true);
        $config->method('getAbandonedCartMinValue')->willReturn(100.0);
        $config->method('getExcludedCustomerGroups')->willReturn([]);

        $scheduler->expects($this->once())->method('scheduleCheck')->with($quote);

        $observerInstance->execute($observer);
    }

    public function testExecuteSkipsWhenNotEligible(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $scheduler = $this->createMock(Scheduler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $observerInstance = new QuoteSaved($config, $scheduler, $logger);

        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getIsActive')->willReturn(false);

        $event = $this->createMock(Event::class);
        $event->method('getQuote')->willReturn($quote);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $config->method('isAbandonedCartEnabled')->willReturn(true);

        $scheduler->expects($this->never())->method('scheduleCheck');

        $observerInstance->execute($observer);
    }
}
