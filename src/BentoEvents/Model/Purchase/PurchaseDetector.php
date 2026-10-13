<?php
/**
 * Decides $purchase / $OrderCanceled from an order's current state — no origData needed.
 *
 * - processing|complete, created ≤ 7 days ago, first time we see it (marker INSERT succeeds) → purchase
 *   (COD goes straight to processing; prepaid reaches processing on capture; a dismissed
 *   pending_payment order paid later also lands here exactly once)
 * - canceled and a marker exists (we announced, or it's a seeded historic buyer order) → order_canceled
 * - anything else (new, pending_payment, holded, closed…) → nothing
 *
 * Never throws: runs inside save-commit callbacks of customer/admin requests.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Purchase;

use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use Psr\Log\LoggerInterface;

class PurchaseDetector
{
    public const PAID_STATES = ['processing', 'complete'];
    /** Was paid at some point (closed = fully refunded). Used to cancel abandonment, never to emit $purchase. */
    public const EVER_PAID_STATES = ['processing', 'complete', 'closed'];
    private const MAX_AGE_SECONDS = 7 * 86400;

    public function __construct(
        private readonly PurchaseMarkerStoreInterface $markers,
        private readonly Enqueuer $enqueuer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array{order_id:int, increment_id:string, email:string, store_id:int, state:string, created_at:string} $order
     */
    public function handle(array $order): void
    {
        try {
            $email = strtolower(trim((string)($order['email'] ?? '')));
            $orderId = (int)($order['order_id'] ?? 0);
            $increment = (string)($order['increment_id'] ?? '');
            $state = (string)($order['state'] ?? '');
            if ($email === '' || $orderId < 1 || $increment === '') {
                return;
            }

            if (in_array($state, self::PAID_STATES, true)) {
                $created = strtotime(($order['created_at'] ?? '') . ' UTC');
                if ($created === false || $created < time() - self::MAX_AGE_SECONDS) {
                    return;
                }
                if ($this->markers->claim($orderId, $increment, $email, gmdate('Y-m-d H:i:s'))) {
                    $this->enqueuer->enqueue('purchase', 'order', $orderId, $email, (int)$order['store_id'], 'purchase:' . $increment);
                }
                return;
            }

            if ($state === 'canceled' && $this->markers->exists($orderId)) {
                $this->enqueuer->enqueue('order_canceled', 'order', $orderId, $email, (int)$order['store_id'], 'order_canceled:' . $increment);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bento purchase detection failed (reconcile will retry)', [
                'order_id' => $order['order_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
