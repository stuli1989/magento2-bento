<?php
/**
 * Contact captured in the Magic Checkout popup + no paid order and no dismissal within
 * contact_capture_delay (tab closed) → $checkout_abandoned (source contact_captured).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Abandonment;

use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Outbox\OutboxStoreInterface;
use ArtLounge\BentoEvents\Plugin\Razorpay\AbandonedQuotePlugin;
use Psr\Log\LoggerInterface;

class ContactTimeoutProcessor
{
    public function __construct(
        private readonly ContactStore $contacts,
        private readonly AbandonmentQueriesInterface $queries,
        private readonly OutboxStoreInterface $outbox,
        private readonly Enqueuer $enqueuer,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return array{enqueued:int, cancelled:int} */
    public function run(): array
    {
        $stats = ['enqueued' => 0, 'cancelled' => 0];
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        foreach ($this->contacts->findDue(gmdate('Y-m-d H:i:s')) as $c) {
            try {
                $id = (int)$c['id'];
                $quoteId = (int)$c['quote_id'];
                $covered = $this->queries->quoteHasPaidOrder($quoteId)
                    || $this->outbox->existsWithPrefixSince('checkout_abandoned:' . $quoteId . ':', $since);
                if ($covered) {
                    $this->contacts->setStatus($id, ContactStore::STATUS_CANCELLED);
                    $stats['cancelled']++;
                    continue;
                }
                $this->enqueuer->enqueue(
                    'checkout_abandoned',
                    'contact',
                    $id,
                    (string)$c['email'],
                    (int)$c['store_id'],
                    AbandonedQuotePlugin::dedupKey($quoteId, (string)$c['rzp_order_id']),
                    ['source' => 'contact_captured', 'rzp_order_id' => (string)$c['rzp_order_id'], 'quote_id' => $quoteId]
                );
                $this->contacts->setStatus($id, ContactStore::STATUS_ENQUEUED);
                $stats['enqueued']++;
            } catch (\Throwable $e) {
                $this->logger->error('Bento contact timeout failed', ['contact_id' => $c['id'] ?? null, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }
}
