<?php
/**
 * $subscribe / $unsubscribe — built purely from the outbox row (context captured at save time).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;

class SubscribeBuilder implements BuilderInterface
{
    public function __construct(private readonly EventEnvelope $envelope)
    {
    }

    public function build(OutboxRow $row): BuildResult
    {
        $subscribed = $row->eventType === 'subscribe';
        $customerId = $row->contextValue('customer_id');

        return BuildResult::event($this->envelope->wrap($row, $subscribed ? '$subscribe' : '$unsubscribe', [
            'subscriber' => [
                'subscriber_id' => $row->entityId,
                'email' => $row->email,
                'status' => $subscribed ? 'subscribed' : 'unsubscribed',
                'customer_id' => $customerId ? (int)$customerId : null,
                'changed_at' => IstDate::format($row->createdAt),
                'source' => (string)$row->contextValue('source', $customerId ? 'customer_account' : 'footer_newsletter'),
            ],
        ]));
    }
}
