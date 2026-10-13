<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Outbox;

use ArtLounge\BentoEvents\Model\Outbox\Backoff;
use PHPUnit\Framework\TestCase;

class BackoffTest extends TestCase
{
    public function testScheduleIsOneMinuteFiveThirtyTwoHoursTwelveHoursThenPark(): void
    {
        $this->assertSame(60, Backoff::nextDelay(1));
        $this->assertSame(300, Backoff::nextDelay(2));
        $this->assertSame(1800, Backoff::nextDelay(3));
        $this->assertSame(7200, Backoff::nextDelay(4));
        $this->assertSame(43200, Backoff::nextDelay(5));
        $this->assertNull(Backoff::nextDelay(6));
        $this->assertNull(Backoff::nextDelay(50));
    }
}
