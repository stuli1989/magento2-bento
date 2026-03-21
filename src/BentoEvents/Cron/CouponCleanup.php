<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CouponCleanup
{
    private const USED_RETENTION_DAYS = 30;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly CouponResource $couponResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
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

        $connection = $this->couponResource->getConnection();
        $tableName = $this->couponResource->getMainTable();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $retentionCutoff = (new \DateTimeImmutable('-' . self::USED_RETENTION_DAYS . ' days'))
            ->format('Y-m-d H:i:s');

        $totalDeleted = 0;

        foreach ($combinations as $combo) {
            $ruleId = $combo['rule_id'];
            $prefix = $combo['prefix'];

            // Batch delete expired coupons
            $expiredCount = (int)$connection->delete($tableName, [
                'rule_id = ?' => $ruleId,
                'code LIKE ?' => $prefix . '-%',
                'expiration_date IS NOT NULL',
                'expiration_date < ?' => $now,
            ]);

            // Batch delete used coupons older than retention period
            $usedCount = (int)$connection->delete($tableName, [
                'rule_id = ?' => $ruleId,
                'code LIKE ?' => $prefix . '-%',
                'times_used >= ?' => 1,
                'created_at < ?' => $retentionCutoff,
            ]);

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
