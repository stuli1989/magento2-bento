<?php
/**
 * $checkout_abandoned from either
 *  - entity "order": popup dismissed → Razorpay's pending_payment order (has email/phone/address), or
 *  - entity "contact": contact captured in the popup, then no payment/dismiss within the delay.
 * details.checkout_source = dismissed | contact_captured. Never carries a coupon.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Purchase\PurchaseDetector;

class CheckoutAbandonedBuilder implements BuilderInterface
{
    public function __construct(
        private readonly OrderSnapshotLoader $orders,
        private readonly QuoteSnapshotLoader $quotes,
        private readonly ContactStore $contacts,
        private readonly QuotePayloadMapper $mapper,
        private readonly RecoveryUrlBuilder $urls,
        private readonly EventEnvelope $envelope
    ) {
    }

    public function build(OutboxRow $row): BuildResult
    {
        if ($row->entityType === 'order') {
            $snapshot = $this->orders->load($row->entityId);
            if ($snapshot === null) {
                return BuildResult::skip('order ' . $row->entityId . ' not found');
            }
            if (in_array($snapshot['state'] ?? '', PurchaseDetector::EVER_PAID_STATES, true)) {
                return BuildResult::skip('paid since dismissal');
            }
            $source = 'dismissed';
        } else {
            $contact = $this->contacts->getById($row->entityId);
            if ($contact === null) {
                return BuildResult::skip('contact ' . $row->entityId . ' not found');
            }
            $snapshot = $this->quotes->load((int)$contact['quote_id']);
            if ($snapshot === null) {
                return BuildResult::skip('quote ' . $contact['quote_id'] . ' not found');
            }
            if (!empty($snapshot['has_order']) && empty($snapshot['is_active'])) {
                return BuildResult::skip('converted to an order');
            }
            $snapshot['email'] = $contact['email'];
            $snapshot['phone'] = $contact['phone'] ?: ($snapshot['phone'] ?? null);
            $source = 'contact_captured';
        }

        $details = $this->mapper->details($snapshot);
        $details['checkout_source'] = (string)$row->contextValue('source', $source);
        $details['recovery_url'] = $this->urls->build((int)$snapshot['quote_id'], $row->email, $row->storeId);

        return BuildResult::event($this->envelope->wrap(
            $row,
            '$checkout_abandoned',
            $details,
            null,
            $this->mapper->fields($snapshot)
        ));
    }
}
