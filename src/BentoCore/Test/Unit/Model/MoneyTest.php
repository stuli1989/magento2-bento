<?php

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Model;

use ArtLounge\BentoCore\Model\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @dataProvider displayCases
     */
    public function testDisplayUsesIndianGroupingAndTwoDecimals(float $amount, string $expected): void
    {
        $this->assertSame($expected, Money::display($amount));
    }

    public static function displayCases(): array
    {
        return [
            'spec example' => [1488.6, '₹1,488.60'],
            'under one rupee' => [0.5, '₹0.50'],
            'one lakh' => [100000.0, '₹1,00,000.00'],
            'crore' => [12345678.9, '₹1,23,45,678.90'],
            'zero' => [0.0, '₹0.00'],
            'hundreds' => [999.999, '₹1,000.00'],
            'thousand' => [1000.0, '₹1,000.00'],
            'negative' => [-2500.5, '-₹2,500.50'],
        ];
    }

    public function testPaiseRoundsHalfUp(): void
    {
        $this->assertSame(1489, Money::paise(14.885));
        $this->assertSame(148860, Money::paise(1488.6));
        $this->assertSame(0, Money::paise(0.0));
        $this->assertSame(50, Money::paise(0.5));
    }

    public function testFromPaiseDisplay(): void
    {
        $this->assertSame('₹1,488.60', Money::displayPaise(148860));
    }

    public function testRupeesRoundsToTwoDecimals(): void
    {
        $this->assertSame(1488.6, Money::rupees(1488.6000001));
    }
}
