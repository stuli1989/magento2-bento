<?php
/**
 * A cart abandoned under one email and paid for under another (Magic Checkout can leave Razorpay's saved contact on
 * the quote) leaves the older subscriber in the cart flows: $purchase and last_purchase_date land on the new email.
 * Each email's newest sent abandon row whose quote has a paid order under other emails → $cart_recovered to that
 * email, so Bento can take it out of the flows. A newer abandoned cart is a genuine run and hides the older one.
 *
 * Runs from cron, never in the order save, so no customer request is involved. The outbox dedup_key keeps this to
 * one event per quote and email however many times the cron sees it.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Abandonment;

use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Pricing\OpenCartRefresh;

class RecoveredCartProcessor
{
    public function __construct(
        private readonly AbandonmentQueriesInterface $queries,
        private readonly Enqueuer $enqueuer
    ) {
    }

    /** @return int events queued */
    public function run(): int
    {
        $newest = [];
        foreach ($this->queries->sentAbandonRows(gmdate('Y-m-d H:i:s', time() - OpenCartRefresh::WINDOW_DAYS * 86400)) as $row) {
            // 'cart_abandoned:<quote>:<time>' or 'checkout_abandoned:<quote>:<rzp order>': only the key holds the
            // quote for all three kinds of row (entity_id is the order or contact on checkout rows).
            $newest[$row['email']] ??= $row + ['quote_id' => (int)(explode(':', $row['dedup_key'])[1] ?? 0)];
        }
        $paid = $this->queries->paidOrderEmails(array_values(array_unique(array_column($newest, 'quote_id'))));

        $queued = 0;
        foreach ($newest as $email => $row) {
            $buyers = array_map(static fn ($e) => strtolower(trim((string)$e)), $paid[$row['quote_id']] ?? []);
            if ($buyers === [] || in_array($email, $buyers, true)) {
                continue;
            }
            if ($this->enqueuer->enqueue(
                'cart_recovered',
                'quote',
                $row['quote_id'],
                $email,
                $row['store_id'],
                'cart_recovered:' . $row['quote_id'] . ':' . $email
            )) {
                $queued++;
            }
        }

        return $queued;
    }
}
