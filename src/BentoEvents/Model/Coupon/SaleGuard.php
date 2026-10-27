<?php
/**
 * Is a sale running that would make the recovery coupon invalid?
 *
 * Sales (e.g. Dussehra 2026, rules 740/741) run as automatic rules at a priority below the coupons with
 * "Discard subsequent rules", so on any cart they cover Magento answers "coupon code isn't valid".
 * While one is live the plugin issues no coupon and reports coupon_eligible = no, so the last email
 * falls back to the plain last note.
 *
 * A sale = active, no coupon, discards later rules, discount > 0, priority below the managed coupon rule,
 * dated for today (IST), on the store's website. Rule 744 (below-MRP items, 0%) is not a sale.
 * ponytail: rule conditions are not evaluated, so any such exclusive rule blocks every coupon while it runs;
 * the blocking rule id is logged. Evaluate conditions per quote if a narrow exclusive promo ever needs coupons alongside it.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Coupon;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class SaleGuard
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $stores,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /** The id of the sale rule that blocks coupons today, or null. */
    public function blockingRuleId(int $storeId): ?int
    {
        try {
            $conn = $this->resource->getConnection();
            $rule = $this->resource->getTableName('salesrule');
            $priority = 1; // coupons sit at priority 1 (BUILD-LOG 2026-10-21)
            $managed = $this->config->getCouponManagedRuleId($storeId);
            if ($managed) {
                $own = $conn->fetchOne($conn->select()->from($rule, ['sort_order'])->where('rule_id = ?', $managed));
                $priority = $own === false ? $priority : (int)$own;
            }
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            $id = $conn->fetchOne(
                $conn->select()->from(['r' => $rule], ['rule_id'])
                    ->join(['w' => $this->resource->getTableName('salesrule_website')], 'w.rule_id = r.rule_id', [])
                    ->where('r.is_active = 1')
                    ->where('r.coupon_type = 1')
                    ->where('r.stop_rules_processing = 1')
                    ->where('r.discount_amount > 0')
                    ->where('r.sort_order < ?', $priority)
                    ->where('r.from_date IS NULL OR r.from_date <= ?', $today)
                    ->where('r.to_date IS NULL OR r.to_date >= ?', $today)
                    ->where('w.website_id = ?', (int)$this->stores->getStore($storeId)->getWebsiteId())
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Bento sale check failed; coupons left on', ['error' => $e->getMessage()]);
            return null;
        }
        if ($id === false || $id === null) {
            return null;
        }
        $this->logger->info('Bento coupon held back: sale rule running', ['rule_id' => (int)$id]);

        return (int)$id;
    }
}
