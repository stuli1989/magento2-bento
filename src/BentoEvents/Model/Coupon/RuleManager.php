<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Coupon;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\Cache\TypeListInterface as CacheTypeList;
use Psr\Log\LoggerInterface;

class RuleManager
{
    private const MANAGED_RULE_NAME = 'Bento: Abandoned Cart Recovery';
    private const MANAGED_RULE_DESCRIPTION = 'Auto-managed by Bento integration. Configure via Stores > Configuration > Art Lounge > Bento Integration > Abandoned Cart.';
    private const CONFIG_PATH_MANAGED_RULE_ID = 'artlounge_bento/abandoned_cart/coupon_managed_rule_id';
    private const SORT_ORDER = 1000;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $ruleResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly GroupCollectionFactory $groupCollectionFactory,
        private readonly ConfigWriter $configWriter,
        private readonly CacheTypeList $cacheTypeList,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Ensure a managed rule exists and return its ID.
     * Creates the rule if missing, verifies it if stored.
     */
    public function ensureRule(int $storeId): ?int
    {
        $ruleId = $this->config->getCouponManagedRuleId($storeId);

        if ($ruleId !== null) {
            $rule = $this->ruleFactory->create();
            $this->ruleResource->load($rule, $ruleId);
            if ($rule->getId()) {
                return (int)$rule->getId();
            }
            $this->logger->warning('Bento managed rule was deleted, recreating', [
                'old_rule_id' => $ruleId
            ]);
        }

        return $this->createManagedRule($storeId);
    }

    /**
     * Sync managed rule settings from config.
     * Called on admin config save.
     */
    public function syncRuleFromConfig(int $storeId): void
    {
        if (!$this->config->isCouponEnabled($storeId)) {
            $this->deactivateManagedRule($storeId);
            return;
        }

        $ruleId = $this->config->getCouponManagedRuleId($storeId);
        if ($ruleId === null) {
            // No rule yet — will be created on first coupon generation
            return;
        }

        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, $ruleId);

        if (!$rule->getId()) {
            // Rule was deleted — clear stale config, will recreate on next generation
            $this->configWriter->delete(self::CONFIG_PATH_MANAGED_RULE_ID);
            $this->cacheTypeList->cleanType('config');
            return;
        }

        $discountType = $this->config->getCouponDiscountType($storeId);
        $discountAmount = $this->config->getCouponDiscountAmount($storeId);
        $minSubtotal = $this->config->getCouponMinSubtotal($storeId);

        $rule->setSimpleAction($discountType);
        $rule->setDiscountAmount($discountAmount);
        $rule->setIsActive(1);
        $rule->setFromDate(null);
        $rule->setToDate(null);
        $rule->setWebsiteIds($this->getAllWebsiteIds());
        $rule->setCustomerGroupIds($this->getAllCustomerGroupIds());

        $this->setMinSubtotalCondition($rule, $minSubtotal);

        $this->ruleResource->save($rule);

        $this->logger->info('Bento managed rule synced from config', [
            'rule_id' => $ruleId,
            'discount_type' => $discountType,
            'discount_amount' => $discountAmount,
            'min_subtotal' => $minSubtotal
        ]);
    }

    /**
     * Get the managed rule ID from config.
     * One rule globally (not store-scoped).
     */
    public function getManagedRuleId(): ?int
    {
        return $this->config->getCouponManagedRuleId();
    }

    private function createManagedRule(int $storeId): ?int
    {
        try {
            $discountType = $this->config->getCouponDiscountType($storeId);
            $discountAmount = $this->config->getCouponDiscountAmount($storeId);
            $minSubtotal = $this->config->getCouponMinSubtotal($storeId);

            $rule = $this->ruleFactory->create();
            $rule->setName(self::MANAGED_RULE_NAME);
            $rule->setDescription(self::MANAGED_RULE_DESCRIPTION);
            $rule->setIsActive(1);
            $rule->setCouponType(Rule::COUPON_TYPE_SPECIFIC);
            $rule->setUseAutoGeneration(true);
            $rule->setSimpleAction($discountType);
            $rule->setDiscountAmount($discountAmount);
            $rule->setDiscountStep(0);
            $rule->setStopRulesProcessing(false);
            $rule->setSortOrder(self::SORT_ORDER);
            $rule->setWebsiteIds($this->getAllWebsiteIds());
            $rule->setCustomerGroupIds($this->getAllCustomerGroupIds());
            $rule->setFromDate(null);
            $rule->setToDate(null);

            $this->setMinSubtotalCondition($rule, $minSubtotal);

            $this->ruleResource->save($rule);
            $ruleId = (int)$rule->getId();

            // Store the managed rule ID in config (default scope)
            $this->configWriter->save(self::CONFIG_PATH_MANAGED_RULE_ID, (string)$ruleId);
            $this->cacheTypeList->cleanType('config');

            $this->logger->info('Bento managed rule created', [
                'rule_id' => $ruleId,
                'discount_type' => $discountType,
                'discount_amount' => $discountAmount,
                'min_subtotal' => $minSubtotal
            ]);

            return $ruleId;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create Bento managed rule', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function deactivateManagedRule(int $storeId): void
    {
        $ruleId = $this->config->getCouponManagedRuleId($storeId);
        if ($ruleId === null) {
            return;
        }

        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, $ruleId);

        if ($rule->getId() && $rule->getIsActive()) {
            $rule->setIsActive(0);
            $this->ruleResource->save($rule);
            $this->logger->info('Bento managed rule deactivated', ['rule_id' => $ruleId]);
        }
    }

    private function setMinSubtotalCondition(Rule $rule, float $minSubtotal): void
    {
        if ($minSubtotal > 0) {
            $conditions = [
                'type' => \Magento\SalesRule\Model\Rule\Condition\Combine::class,
                'attribute' => null,
                'operator' => null,
                'value' => '1',
                'is_value_processed' => null,
                'aggregator' => 'all',
                'conditions' => [
                    [
                        'type' => \Magento\SalesRule\Model\Rule\Condition\Address::class,
                        'attribute' => 'base_subtotal',
                        'operator' => '>=',
                        'value' => (string)$minSubtotal,
                        'is_value_processed' => false
                    ]
                ]
            ];
        } else {
            $conditions = [
                'type' => \Magento\SalesRule\Model\Rule\Condition\Combine::class,
                'attribute' => null,
                'operator' => null,
                'value' => '1',
                'is_value_processed' => null,
                'aggregator' => 'all',
                'conditions' => []
            ];
        }
        $rule->getConditions()->loadArray($conditions);
    }

    private function getAllWebsiteIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $ids[] = (int)$website->getId();
        }
        return $ids;
    }

    private function getAllCustomerGroupIds(): array
    {
        $collection = $this->groupCollectionFactory->create();
        $ids = [];
        foreach ($collection as $group) {
            $ids[] = (int)$group->getId();
        }
        return $ids;
    }
}
