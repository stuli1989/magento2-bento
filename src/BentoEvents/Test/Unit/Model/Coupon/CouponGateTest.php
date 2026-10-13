<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Coupon;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Coupon\CouponGate;
use ArtLounge\BentoEvents\Service\CouponService;
use PHPUnit\Framework\TestCase;

class CouponGateTest extends TestCase
{
    private bool $enabled = true;
    private float $threshold = 2000.0;
    private ?array $issued = ['code' => 'BENTO-ABC234', 'expires_at' => '2026-10-20T15:30:00+05:30'];
    private int $issueCalls = 0;

    private function gate(): CouponGate
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isCouponEnabled')->willReturnCallback(fn () => $this->enabled);
        $config->method('getCouponThreshold')->willReturnCallback(fn () => $this->threshold);
        $issuer = $this->createMock(CouponService::class);
        $issuer->method('generateForCart')->willReturnCallback(function () {
            $this->issueCalls++;
            return $this->issued;
        });
        return new CouponGate($config, $issuer);
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
