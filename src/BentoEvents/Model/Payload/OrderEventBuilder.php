<?php
/**
 * $purchase and $OrderCanceled (configured twice in di.xml with different $bentoType).
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

        return BuildResult::event(
            $this->envelope->wrap($row, $this->bentoType, $this->mapper->details($order), $uniqueKey, $fields),
            $this->profile->subscriberRecord($row->email, $fields)
        );
    }
}
