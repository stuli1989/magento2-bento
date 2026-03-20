<?php
/**
 * Process Abandoned Carts Cron Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Cron;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Cron\ProcessAbandonedCarts;
use ArtLounge\BentoEvents\Model\AbandonedCart\Checker;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessAbandonedCartsTest extends TestCase
{
    private ProcessAbandonedCarts $cron;
    private MockObject $config;
    private MockObject $scheduler;
    private MockObject $checker;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->scheduler = $this->createMock(Scheduler::class);
        $this->checker = $this->createMock(Checker::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cron = new ProcessAbandonedCarts(
            $this->config,
            $this->scheduler,
            $this->checker,
            $this->logger
        );
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $this->config->method('isAbandonedCartEnabled')->willReturn(false);
        $this->scheduler->expects($this->never())->method('getPendingCarts');

        $this->cron->execute();
    }

    public function testExecuteProcessesPendingCartsWhenQueueMethodConfigured(): void
    {
        $this->config->method('isAbandonedCartEnabled')->willReturn(true);
        $this->scheduler->method('getPendingCarts')->willReturn([]);
        $this->scheduler->method('cleanup')->willReturn(0);
        $this->scheduler->expects($this->once())->method('getPendingCarts');

        $this->cron->execute();
    }

    public function testExecuteProcessesPendingCarts(): void
    {
        $this->config->method('isAbandonedCartEnabled')->willReturn(true);

        $this->scheduler->method('getPendingCarts')->willReturn([
            ['quote_id' => 10, 'quote_updated_at' => '2026-01-24 10:00:00'],
            ['quote_id' => 11, 'quote_updated_at' => '2026-01-24 10:05:00']
        ]);

        $this->checker->method('checkAndTrigger')->willReturnOnConsecutiveCalls(true, false);
        $this->scheduler->method('cleanup')->willReturn(1);

        $this->logger->expects($this->once())->method('info');
        $this->logger->expects($this->once())->method('debug');

        $this->cron->execute();
    }

    public function testExecuteHandlesExceptions(): void
    {
        $this->config->method('isAbandonedCartEnabled')->willReturn(true);
        $this->scheduler->method('getPendingCarts')->willThrowException(new \RuntimeException('fail'));

        $this->logger->expects($this->once())->method('error');

        $this->cron->execute();
    }
}
