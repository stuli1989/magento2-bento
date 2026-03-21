<?php
/**
 * CouponService Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Service\CouponService;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\ResourceModel\Coupon\Collection;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CouponServiceTest extends TestCase
{
    private CouponService $service;
    private MockObject $config;
    private MockObject $ruleRepository;
    private MockObject $couponFactory;
    private MockObject $couponResource;
    private MockObject $couponCollectionFactory;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->ruleRepository = $this->createMock(RuleRepositoryInterface::class);
        $this->couponFactory = $this->createMock(CouponFactory::class);
        $this->couponResource = $this->createMock(CouponResource::class);
        $this->couponCollectionFactory = $this->createMock(CouponCollectionFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new CouponService(
            $this->config,
            $this->ruleRepository,
            $this->couponFactory,
            $this->couponResource,
            $this->couponCollectionFactory,
            $this->logger
        );
    }

    public function testReturnsNullWhenDisabled(): void
    {
        $this->config->method('isCouponEnabled')->willReturn(false);

        $result = $this->service->generateForCart(100, 1);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenRuleIdNotConfigured(): void
    {
        $this->config->method('isCouponEnabled')->willReturn(true);
        $this->config->method('getCouponRuleId')->willReturn(null);

        $result = $this->service->generateForCart(100, 1);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenRuleNotFound(): void
    {
        $this->config->method('isCouponEnabled')->willReturn(true);
        $this->config->method('getCouponRuleId')->willReturn(42);

        // findExistingCoupon collection: no existing coupon
        $existingCollection = $this->createMock(Collection::class);
        $existingCollection->method('addFieldToFilter')->willReturnSelf();
        $existingCollection->method('setPageSize')->willReturnSelf();
        $existingItem = $this->createMock(Coupon::class);
        $existingItem->method('getId')->willReturn(null);
        $existingCollection->method('getFirstItem')->willReturn($existingItem);

        $this->couponCollectionFactory->method('create')
            ->willReturn($existingCollection);

        $this->ruleRepository->method('getById')
            ->willThrowException(new \RuntimeException('Rule not found'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Bento coupon: configured rule not found', $this->anything());

        $result = $this->service->generateForCart(100, 1);

        $this->assertNull($result);
    }

    public function testGeneratesCouponWithCorrectFormat(): void
    {
        $this->config->method('isCouponEnabled')->willReturn(true);
        $this->config->method('getCouponRuleId')->willReturn(42);
        $this->config->method('getCouponPrefix')->willReturn('BENTO');
        $this->config->method('getCouponLifetimeDays')->willReturn(7);

        // Collection mock for both findExistingCoupon and codeExists calls
        $existingCollection = $this->createMock(Collection::class);
        $existingCollection->method('addFieldToFilter')->willReturnSelf();
        $existingCollection->method('setPageSize')->willReturnSelf();
        // findExistingCoupon: no existing coupon (getId returns null)
        $existingItem = $this->createMock(Coupon::class);
        $existingItem->method('getId')->willReturn(null);
        $existingCollection->method('getFirstItem')->willReturn($existingItem);
        // codeExists: no collision (getSize returns 0)
        $existingCollection->method('getSize')->willReturn(0);

        $this->couponCollectionFactory->method('create')
            ->willReturn($existingCollection);

        $this->ruleRepository->method('getById')->willReturn(
            $this->createMock(\Magento\SalesRule\Api\Data\RuleInterface::class)
        );

        // CouponFactory returns a mock coupon that accepts setters
        $coupon = $this->createMock(Coupon::class);
        $coupon->method('setRuleId')->willReturnSelf();
        $coupon->method('setCode')->willReturnSelf();
        $coupon->method('setUsageLimit')->willReturnSelf();
        $coupon->method('setUsagePerCustomer')->willReturnSelf();
        $coupon->method('setExpirationDate')->willReturnSelf();
        $coupon->method('setType')->willReturnSelf();
        $coupon->method('setIsPrimary')->willReturnSelf();
        $coupon->method('setDescription')->willReturnSelf();
        $this->couponFactory->method('create')->willReturn($coupon);

        $this->couponResource->expects($this->once())->method('save');

        $result = $this->service->generateForCart(100, 1);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertMatchesRegularExpression('/^BENTO-[A-Z2-9]{6}$/', $result['code']);
    }

    public function testReturnsExistingCouponForSameQuote(): void
    {
        $this->config->method('isCouponEnabled')->willReturn(true);
        $this->config->method('getCouponRuleId')->willReturn(42);

        // findExistingCoupon: returns an existing coupon
        $existingCollection = $this->createMock(Collection::class);
        $existingCollection->method('addFieldToFilter')->willReturnSelf();
        $existingCollection->method('setPageSize')->willReturnSelf();
        $existingItem = $this->createMock(Coupon::class);
        $existingItem->method('getId')->willReturn(99);
        $existingItem->method('getCode')->willReturn('BENTO-ABC123');
        $existingItem->method('getExpirationDate')->willReturn('2026-04-01 00:00:00');
        $existingCollection->method('getFirstItem')->willReturn($existingItem);

        $this->couponCollectionFactory->method('create')
            ->willReturn($existingCollection);

        // The rule repository and coupon factory should NOT be called
        $this->ruleRepository->expects($this->never())->method('getById');
        $this->couponFactory->expects($this->never())->method('create');

        $result = $this->service->generateForCart(100, 1);

        $this->assertNotNull($result);
        $this->assertSame('BENTO-ABC123', $result['code']);
        $this->assertNotNull($result['expires_at']);
    }
}
