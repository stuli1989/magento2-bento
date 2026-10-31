<?php
/**
 * $cart_recovered: this email's abandoned cart was paid for under another email (RecoveredCartProcessor).
 * No fields: the purchase belongs to the other subscriber. The Bento flow on it removes the abandonment tags.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;

class CartRecoveredBuilder implements BuilderInterface
{
    public function __construct(private readonly EventEnvelope $envelope)
    {
    }

    public function build(OutboxRow $row): BuildResult
    {
        return BuildResult::event($this->envelope->wrap($row, '$cart_recovered', ['quote_id' => $row->entityId]));
    }
}
