<?php
/**
 * artlounge_bento_reconcile — nightly.
 *  1. Orders that reached processing/complete in the last 48h without a $purchase → enqueue
 *     (covers a crash between commit and insert). INSERT IGNORE makes this idempotent.
 *  2. Contact-capture health: zero captures in 7 days while orders happened → warning
 *     (Razorpay mx-analytics event names may have changed).
 *  3. Cleanup: sent/skipped outbox rows and contact rows older than 14 days.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Cron;

use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Outbox\OutboxStoreInterface;
use ArtLounge\BentoEvents\Model\Purchase\PurchaseDetector;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class Reconcile
{
    private const RETENTION_DAYS = 14;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly PurchaseDetector $detector,
        private readonly Enqueuer $enqueuer,
        private readonly OutboxStoreInterface $outbox,
        private readonly ContactStore $contacts,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->reconcilePurchases();
        } catch (\Throwable $e) {
            $this->logger->error('Bento reconcile (purchases) failed', ['error' => $e->getMessage()]);
        }
        try {
            $this->checkContactCaptureHealth();
        } catch (\Throwable $e) {
            $this->logger->error('Bento reconcile (contact health) failed', ['error' => $e->getMessage()]);
        }
        try {
            $before = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400);
            $purged = $this->outbox->purge($before) + $this->contacts->purge($before);
            if ($purged) {
                $this->logger->info('Bento reconcile cleanup', ['deleted' => $purged]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bento reconcile (cleanup) failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return int number of orders (re)enqueued or handed to the detector */
    public function reconcilePurchases(): int
    {
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchAll(
            $conn->select()
                ->from(['so' => $this->resource->getTableName('sales_order')], [
                    'entity_id', 'increment_id', 'customer_email', 'store_id', 'state', 'created_at',
                ])
                ->joinLeft(
                    ['p' => $this->resource->getTableName('artlounge_bento_purchase')],
                    'p.order_id = so.entity_id',
                    ['marker' => 'p.order_id', 'seeded' => 'p.seeded']
                )
                ->where('so.updated_at >= ?', gmdate('Y-m-d H:i:s', time() - 48 * 3600))
                ->where('so.created_at >= ?', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                ->where('so.state IN (?)', PurchaseDetector::PAID_STATES)
        );

        $n = 0;
        foreach ($rows as $r) {
            if ($r['marker'] === null) {
                $this->detector->handle([
                    'order_id' => (int)$r['entity_id'],
                    'increment_id' => (string)$r['increment_id'],
                    'email' => (string)$r['customer_email'],
                    'store_id' => (int)$r['store_id'],
                    'state' => (string)$r['state'],
                    'created_at' => (string)$r['created_at'],
                ]);
                $n++;
            } elseif ((int)$r['seeded'] === 0) {
                // Marker written but the outbox insert may have failed: re-enqueue (no-op if present).
                if ($this->enqueuer->enqueue('purchase', 'order', (int)$r['entity_id'], (string)$r['customer_email'],
                    (int)$r['store_id'], 'purchase:' . $r['increment_id'])) {
                    $this->logger->warning('Bento reconcile re-enqueued a missing $purchase', ['order' => $r['increment_id']]);
                    $n++;
                }
            }
        }

        return $n;
    }

    private function checkContactCaptureHealth(): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $captures = $this->contacts->countSince($since);
        if ($captures > 0) {
            return;
        }
        $conn = $this->resource->getConnection();
        $orders = (int)$conn->fetchOne(
            $conn->select()->from($this->resource->getTableName('sales_order'), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('created_at >= ?', $since)
        );
        if ($orders > 0) {
            $this->logger->warning(
                'Bento contact capture: 0 captures in 7 days while ' . $orders . ' orders were placed. '
                . 'Razorpay mx-analytics event names (user_data / address_info_submitted) may have changed.'
            );
        }
    }
}
