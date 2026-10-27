<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Coupon;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Coupon\CouponGate;
use ArtLounge\BentoEvents\Model\Coupon\SaleGuard;
use ArtLounge\BentoEvents\Service\CouponService;
use PHPUnit\Framework\TestCase;

class CouponGateTest extends TestCase
{
    private bool $enabled = true;
    private float $threshold = 2000.0;
    private ?array $issued = ['code' => 'BENTO-ABC234', 'expires_at' => '2026-10-20T15:30:00+05:30'];
    private int $issueCalls = 0;
    private ?int $saleRule = null;
    private string $type = 'cart_fixed';
    private float $amount = 200.0;

    private function gate(): CouponGate
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isCouponEnabled')->willReturnCallback(fn () => $this->enabled);
        $config->method('getCouponThreshold')->willReturnCallback(fn () => $this->threshold);
        $config->method('getCouponDiscountType')->willReturnCallback(fn () => $this->type);
        $config->method('getCouponDiscountAmount')->willReturnCallback(fn () => $this->amount);
        $issuer = $this->createMock(CouponService::class);
        $issuer->method('generateForCart')->willReturnCallback(function () {
            $this->issueCalls++;
            return $this->issued;
        });
        $sales = $this->createMock(SaleGuard::class);
        $sales->method('blockingRuleId')->willReturnCallback(fn () => $this->saleRule);
        return new CouponGate($config, $issuer, $sales);
    }

    public function testNoCouponWhileASaleWouldRejectIt(): void
    {
        $this->saleRule = 741;
        $this->assertSame(['eligible' => false, 'coupon' => null], $this->gate()->evaluate(5000.0, 1, 1));
        $this->assertSame(0, $this->issueCalls);
    }

    public function testCouponCarriesItsValueFromConfig(): void
    {
        $this->assertSame('₹200', $this->gate()->evaluate(5000.0, 1, 1)['coupon']['value_display']);
        [$this->type, $this->amount] = ['by_percent', 7.5];
        $this->assertSame('7.5%', $this->gate()->evaluate(5000.0, 1, 1)['coupon']['value_display']);
    }

    public function testDisabledMeansNotEligibleAndNoCodeIssued(): void
    {
        $this->enabled = false;
        $this->assertSame(['eligible' => false, 'coupon' => null], $this->gate()->evaluate(5000.0, 1, 1));
        $this->assertSame(0, $this->issueCalls);
    }

    public function testBelowThresholdNotEligibleNoCodeIssued(): void
    {
        $this->assertSame(['eligible' => false, 'coupon' => null], $this->gate()->evaluate(1999.99, 1, 1));
        $this->assertSame(0, $this->issueCalls, 'no coupon row created for small carts');
    }

    public function testAtThresholdEligibleWithCoupon(): void
    {
        $r = $this->gate()->evaluate(2000.0, 1, 1);
        $this->assertTrue($r['eligible']);
        $this->assertSame('BENTO-ABC234', $r['coupon']['code']);
    }

    public function testIssuerFailureIsNotEligible(): void
    {
        $this->issued = null;
        $this->assertSame(['eligible' => false, 'coupon' => null], $this->gate()->evaluate(5000.0, 1, 1));
    }

    public function testZeroThresholdMeansEveryCart(): void
    {
        $this->threshold = 0.0;
        $this->assertTrue($this->gate()->evaluate(10.0, 1, 1)['eligible']);
    }
}
