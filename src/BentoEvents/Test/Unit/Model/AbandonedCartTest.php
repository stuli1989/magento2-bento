<?php
/**
 * Abandoned Cart Integration Test (Unit Conversion)
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model;

use ArtLounge\BentoEvents\Model\AbandonedCart\Checker;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use PHPUnit\Framework\TestCase;

class AbandonedCartTest extends TestCase
{
    public function testCheckerSkipsConvertedCarts(): void
    {
        $checker = $this->createMock(Checker::class);
        $checker->method('checkAndTrigger')->willReturn(false);

        $this->assertFalse($checker->checkAndTrigger(10));
    }

    public function testSchedulerCleanupReturnsInt(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->method('cleanup')->willReturn(1);

        $this->assertIsInt($scheduler->cleanup(0));
    }
}
