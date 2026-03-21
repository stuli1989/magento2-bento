<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CouponCleanup
{
    private const USED_RETENTION_DAYS = 30;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly CouponCollectionFactory $couponCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        // Collect distinct rule_id/prefix combinations across all stores
        $combinations = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if (!$this->config->isCouponEnabled($storeId)) {
                continue;
            }

            $ruleId = $this->config->getCouponRuleId($storeId);
            $prefix = $this->config->getCouponPrefix($storeId);
            if ($ruleId === null) {
                continue;
            }

            $key = $ruleId . ':' . $prefix;
            $combinations[$key] = ['rule_id' => $ruleId, 'prefix' => $prefix];
        }

        if (empty($combinations)) {
            return;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $retentionCutoff = (new \DateTime('-' . self::USED_RETENTION_DAYS . ' days'))
            ->format('Y-m-d H:i:s');

        $totalDeleted = 0;

        foreach ($combinations as $combo) {
            $ruleId = $combo['rule_id'];
            $prefix = $combo['prefix'];

            // Delete expired coupons
            $expired = $this->couponCollectionFactory->create();
            $expired->addFieldToFilter('rule_id', $ruleId);
            $expired->addFieldToFilter('code', ['like' => $prefix . '-%']);
            $expired->addFieldToFilter('expiration_date', ['notnull' => true]);
            $expired->addFieldToFilter('expiration_date', ['lt' => $now]);

            $expiredCount = $expired->getSize();
            foreach ($expired as $coupon) {
                $coupon->delete();
            }

            // Delete used coupons older than retention period
            $used = $this->couponCollectionFactory->create();
            $used->addFieldToFilter('rule_id', $ruleId);
            $used->addFieldToFilter('code', ['like' => $prefix . '-%']);
            $used->addFieldToFilter('times_used', ['gteq' => 1]);
            $used->addFieldToFilter('created_at', ['lt' => $retentionCutoff]);

            $usedCount = $used->getSize();
            foreach ($used as $coupon) {
                $coupon->delete();
            }

            $deleted = $expiredCount + $usedCount;
            $totalDeleted += $deleted;

            if ($deleted > 0) {
                $this->logger->info('Bento coupon cleanup', [
                    'rule_id' => $ruleId,
                    'prefix' => $prefix,
                    'expired_deleted' => $expiredCount,
                    'used_deleted' => $usedCount
                ]);
            }
        }

        if ($totalDeleted > 0) {
            $this->logger->info('Bento coupon cleanup complete', [
                'total_deleted' => $totalDeleted
            ]);
        }
    }
}
