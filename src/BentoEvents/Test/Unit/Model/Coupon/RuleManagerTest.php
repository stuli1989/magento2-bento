<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Coupon;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Coupon\RuleManager;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RuleManagerTest extends TestCase
{
    private ?int $storedRuleId = null;
    /** What load() finds in the DB: null = no such rule. */
    private ?array $dbRule = null;
    /** @var Rule[] */
    private array $saved = [];
    private bool $saveFails = false;

    private function manager(bool $couponEnabled = true): RuleManager
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getCouponManagedRuleId')->willReturnCallback(fn () => $this->storedRuleId);
        $config->method('isCouponEnabled')->willReturn($couponEnabled);
        $config->method('getCouponDiscountType')->willReturn('cart_fixed');
        $config->method('getCouponDiscountAmount')->willReturn(200.0);
        $config->method('getCouponThreshold')->willReturn(2999.0);

        $resource = $this->createMock(RuleResource::class);
        $resource->method('load')->willReturnCallback(function (Rule $rule) use ($resource) {
            foreach ($this->dbRule ?? [] as $k => $v) {
                $rule->{'set' . ucfirst($k)}($v);
            }
            return $resource;
        });
        $resource->method('save')->willReturnCallback(function (Rule $rule) use ($resource) {
            if ($this->saveFails) {
                throw new \RuntimeException('db down');
            }
            $rule->setId($rule->getId() ?? 501);
            $this->saved[] = clone $rule;
            return $resource;
        });

        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getWebsites')->willReturn([]);

        return new RuleManager(
            $config,
            new RuleFactory(),
            $resource,
            $stores,
            new GroupCollectionFactory(),
            $this->createMock(WriterInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testNewRuleIsCreatedAtPriorityOneWithoutDiscardingLaterRules(): void
    {
        $this->assertSame(501, $this->manager()->ensureRule(1));

        $this->assertCount(1, $this->saved);
        $this->assertSame(1, $this->saved[0]->getSortOrder(), 'after rule 744 (priority 0), before the ladder (100+)');
        $this->assertFalse($this->saved[0]->getStopRulesProcessing(), 'the ladder must still apply after the code');
    }

    public function testExistingRuleAtTheOldPriorityIsMovedToOneOnFirstUse(): void
    {
        $this->storedRuleId = 77;
        $this->dbRule = ['id' => 77, 'sortOrder' => 1000, 'stopRulesProcessing' => 0];

        $this->assertSame(77, $this->manager()->ensureRule(1));

        $this->assertCount(1, $this->saved);
        $this->assertSame(1, $this->saved[0]->getSortOrder());
        $this->assertFalse($this->saved[0]->getStopRulesProcessing());
    }

    public function testExistingRuleThatDiscardsLaterRulesIsCorrected(): void
    {
        $this->storedRuleId = 77;
        $this->dbRule = ['id' => 77, 'sortOrder' => 1, 'stopRulesProcessing' => 1];

        $this->manager()->ensureRule(1);

        $this->assertCount(1, $this->saved);
        $this->assertFalse($this->saved[0]->getStopRulesProcessing());
    }

    public function testExistingRuleAlreadyAtPriorityOneIsNotResaved(): void
    {
        $this->storedRuleId = 77;
        $this->dbRule = ['id' => 77, 'sortOrder' => '1', 'stopRulesProcessing' => '0'];

        $this->assertSame(77, $this->manager()->ensureRule(1));
        $this->assertCount(0, $this->saved);
    }

    public function testFailedPriorityCorrectionStillReturnsTheRule(): void
    {
        $this->storedRuleId = 77;
        $this->dbRule = ['id' => 77, 'sortOrder' => 1000, 'stopRulesProcessing' => 0];
        $this->saveFails = true;

        $this->assertSame(77, $this->manager()->ensureRule(1), 'a coupon at the old priority beats no coupon');
    }

    public function testConfigSyncMovesTheExistingRuleToPriorityOne(): void
    {
        $this->storedRuleId = 77;
        $this->dbRule = ['id' => 77, 'sortOrder' => 1000, 'stopRulesProcessing' => 1];

        $this->manager()->syncRuleFromConfig(1);

        $this->assertCount(1, $this->saved);
        $this->assertSame(1, $this->saved[0]->getSortOrder());
        $this->assertFalse($this->saved[0]->getStopRulesProcessing());
        $this->assertSame(200.0, $this->saved[0]->getDiscountAmount());
    }
}
