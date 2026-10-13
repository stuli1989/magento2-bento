<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;

/**
 * $checkout_started — Magic Checkout popup opened by a logged-in customer. Never carries a coupon.
 */
class CheckoutStartedBuilder implements BuilderInterface
{
    public function __construct(
        private readonly QuoteSnapshotLoader $quotes,
        private readonly QuotePayloadMapper $mapper,
        private readonly EventEnvelope $envelope
    ) {
    }

    public function build(OutboxRow $row): BuildResult
    {
        $quote = $this->quotes->load($row->entityId);
        if ($quote === null) {
            return BuildResult::skip('quote ' . $row->entityId . ' not found');
        }

        return BuildResult::event($this->envelope->wrap(
            $row,
            '$checkout_started',
            $this->mapper->details($quote),
            null,
            $this->mapper->fields($quote)
        ));
    }
}
