<?php
/**
 * $purchase and $OrderCanceled (configured twice in di.xml with different $bentoType).
 * Only $purchase writes the order snapshot fields (last_order_item_*) — a cancel must not replace
 * the snapshot of the order the post-purchase emails are about.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;

class OrderEventBuilder implements BuilderInterface
{
    public function __construct(
        private readonly string $bentoType,
        private readonly OrderSnapshotLoader $loader,
        private readonly OrderPayloadMapper $mapper,
        private readonly EventEnvelope $envelope,
        private readonly ProfileFieldsProvider $profile
    ) {
    }

    public function build(OutboxRow $row): BuildResult
    {
        $order = $this->loader->load($row->entityId);
        if ($order === null) {
            return BuildResult::skip('order ' . $row->entityId . ' not found');
        }
        $fields = $this->mapper->subscriberFields($order);
        $uniqueKey = $this->bentoType === '$purchase'
            ? (string)$order['increment_id'] // lets Bento dedupe a resend of the same purchase
            : $row->dedupKey;

        $details = $this->mapper->details($order);
        $snapshot = $this->bentoType === '$purchase' ? SnapshotFields::order($details['cart']['items']) : [];

        return BuildResult::event(
            $this->envelope->wrap($row, $this->bentoType, $details, $uniqueKey, $fields, $snapshot),
            $this->profile->subscriberRecord($row->email, $fields)
        );
    }
}
