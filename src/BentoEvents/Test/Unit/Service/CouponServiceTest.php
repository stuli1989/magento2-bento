<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Coupon\RuleManager;
use ArtLounge\BentoEvents\Service\CouponService;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\ResourceModel\Coupon\Collection;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CouponServiceTest extends TestCase
{
    /** Coupon already stored for the quote (by description bento_quote_{id}); null = none. */
    private ?Coupon $existing = null;
    private array $filters = [];

    private function service(CouponResource $resource): CouponService
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isCouponEnabled')->willReturn(true);
        $config->method('getCouponPrefix')->willReturn('BENTO');
        $config->method('getCouponLifetimeDays')->willReturn(5);

        $rules = $this->createMock(RuleManager::class);
        $rules->method('ensureRule')->willReturn(77);

        $collections = $this->createMock(CollectionFactory::class);
        $collections->method('create')->willReturnCallback(function () {
            $c = $this->createMock(Collection::class);
            $c->method('addFieldToFilter')->willReturnCallback(function ($field, $value) use ($c) {
                $this->filters[] = [$field, $value];
                return $c;
            });
            $c->method('setPageSize')->willReturnSelf();
            $c->method('setOrder')->willReturnSelf();
            $c->method('getFirstItem')->willReturnCallback(fn () => $this->existing);
            $c->method('getSize')->willReturn(0);
            return $c;
        });

        return new CouponService($config, $rules, new CouponFactory(), $resource, $collections, $this->createMock(LoggerInterface::class));
    }

    public function testASecondEventForTheSameQuoteReusesTheIssuedCode(): void
    {
        // $cart_abandoned already minted BENTO-ABC234 for quote 9001; $checkout_abandoned asks next.
        $this->existing = $this->createMock(Coupon::class);
        $this->existing->method('getId')->willReturn(12);
        $this->existing->method('getCode')->willReturn('BENTO-ABC234');
        $this->existing->method('getExpirationDate')->willReturn('2026-10-26 10:00:00');
        $resource = $this->createMock(CouponResource::class);
        $resource->expects($this->never())->method('save');

        $coupon = $this->service($resource)->generateForCart(9001, 1);

        $this->assertSame('BENTO-ABC234', $coupon['code']);
        $this->assertSame('2026-10-26T15:30:00+05:30', $coupon['expires_at']);
        $this->assertContains(['description', 'bento_quote_9001'], $this->filters);
    }

    public function testAUsedUpCodeIsNotReissued(): void
    {
        // A popup exit wrote a pending_payment order that spent BENTO-ABC234; the next event must not resend it.
        $this->existing = $this->usedCoupon('BENTO-ABC234', 'bento_quote_9001');
        $resource = $this->createMock(CouponResource::class);
        $resource->expects($this->once())->method('save');

        $coupon = $this->service($resource)->generateForCart(9001, 1);

        $this->assertNotSame('BENTO-ABC234', $coupon['code']);
        $this->assertContains(['description', 'bento_quote_9001'], $this->filters);
    }

    public function testFreshForSwapsOnlyThisQuotesUsedUpCode(): void
    {
        $resource = $this->createMock(CouponResource::class);

        $this->existing = $this->usedCoupon('BENTO-ABC234', 'bento_quote_9001');
        $this->assertMatchesRegularExpression('/^BENTO-[A-Z2-9]{6}$/', (string)$this->service($resource)->freshFor('BENTO-ABC234', 9001, 1));
        $this->assertNull($this->service($resource)->freshFor('BENTO-ABC234', 9002, 1), 'a code from another cart stays as it is');

        $this->existing = $this->createMock(Coupon::class);
        $this->existing->method('getId')->willReturn(13);
        $this->existing->method('getDescription')->willReturn('bento_quote_9001');
        $this->existing->method('getUsageLimit')->willReturn(1);
        $this->existing->method('getTimesUsed')->willReturn(0);
        $this->assertNull($this->service($resource)->freshFor('BENTO-ABC234', 9001, 1), 'a code that still works stays');
    }

    private function usedCoupon(string $code, string $description): Coupon
    {
        $c = $this->createMock(Coupon::class);
        $c->method('getId')->willReturn(12);
        $c->method('getCode')->willReturn($code);
        $c->method('getDescription')->willReturn($description);
        $c->method('getUsageLimit')->willReturn(1);
        $c->method('getTimesUsed')->willReturn(1);
        return $c;
    }

    public function testFirstEventForAQuoteMintsOneSingleUseCode(): void
    {
        $resource = $this->createMock(CouponResource::class);
        $resource->expects($this->once())->method('save');

        $coupon = $this->service($resource)->generateForCart(9001, 1);

        $this->assertMatchesRegularExpression('/^BENTO-[A-Z2-9]{6}$/', $coupon['code']);
    }
}
