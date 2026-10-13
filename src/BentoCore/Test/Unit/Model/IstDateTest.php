<?php

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Model;

use ArtLounge\BentoCore\Model\IstDate;
use PHPUnit\Framework\TestCase;

class IstDateTest extends TestCase
{
    public const FORMAT_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+05:30$/';

    public function testFormatsUtcDatabaseStringInIst(): void
    {
        $this->assertSame('2026-10-13T15:30:00+05:30', IstDate::format('2026-10-13 10:00:00'));
    }

    public function testFormatsUnixTimestamp(): void
    {
        $ts = gmmktime(10, 0, 0, 10, 13, 2026);
        $this->assertSame('2026-10-13T15:30:00+05:30', IstDate::format($ts));
    }

    public function testFormatsDateTimeObjectRegardlessOfItsZone(): void
    {
        $dt = new \DateTimeImmutable('2026-10-13 06:00:00', new \DateTimeZone('America/New_York'));
        $this->assertSame('2026-10-13T15:30:00+05:30', IstDate::format($dt));
    }

    public function testDropsFractionalSeconds(): void
    {
        $this->assertSame('2026-10-13T15:30:00+05:30', IstDate::format('2026-10-13 10:00:00.987'));
    }

    public function testNowMatchesFormat(): void
    {
        $this->assertMatchesRegularExpression(self::FORMAT_REGEX, IstDate::now());
    }

    public function testEmptyInputReturnsNull(): void
    {
        $this->assertNull(IstDate::formatOrNull(null));
        $this->assertNull(IstDate::formatOrNull(''));
        $this->assertNull(IstDate::formatOrNull('0000-00-00 00:00:00'));
    }

    public function testDayMonthIsTheIstCalendarDay(): void
    {
        $this->assertSame('18 October', IstDate::dayMonthOrNull('2026-10-18T23:30:00+05:30'));
        // 20:00 UTC on the 17th is already the 18th in IST (DB strings are UTC)
        $this->assertSame('18 October', IstDate::dayMonthOrNull('2026-10-17 20:00:00'));
        $this->assertSame('18 October', IstDate::dayMonthOrNull('2026-10-17T20:00:00+00:00'));
        $this->assertNull(IstDate::dayMonthOrNull(null));
        $this->assertNull(IstDate::dayMonthOrNull(''));
    }

    public function testLexicalOrderMatchesChronologicalOrder(): void
    {
        // Bento flows compare these strings lexically.
        $earlier = IstDate::format('2026-10-13 09:59:59');
        $later = IstDate::format('2026-10-13 10:00:00');
        $this->assertTrue(strcmp($later, $earlier) > 0);
    }
}
