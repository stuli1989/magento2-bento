<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;

class RefundBuilder implements BuilderInterface
{
    public function __construct(
        private readonly OrderSnapshotLoader $loader,
        private readonly OrderPayloadMapper $mapper,
        private readonly EventEnvelope $envelope,
        private readonly ProfileFieldsProvider $profile
    ) {
    }

    public function build(OutboxRow $row): BuildResult
    {
        $cm = $this->loader->loadCreditmemo($row->entityId);
        if ($cm === null) {
            return BuildResult::skip('creditmemo ' . $row->entityId . ' not found');
        }
        $order = $cm['order'];
        $details = $this->mapper->details($order);
        $details['refund'] = $this->mapper->refund($cm);
        $fields = $this->mapper->subscriberFields($order);

        return BuildResult::event(
            $this->envelope->wrap($row, '$OrderRefunded', $details, $row->dedupKey, $fields),
            $this->profile->subscriberRecord($row->email, $fields)
        );
    }
}
