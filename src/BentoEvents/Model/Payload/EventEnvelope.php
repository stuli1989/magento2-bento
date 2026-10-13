<?php
/**
 * Wraps event details in Bento's {type, email, fields?, details} shape and stamps the
 * three keys every server event carries: unique.key, source, timestamp.
 *
 * details.timestamp = when it happened (outbox created_at), via the shared IstDate formatter —
 * the same formatter used for last_purchase_date, so Bento flow exits can compare them lexically.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;

class EventEnvelope
{
    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function wrap(OutboxRow $row, string $type, array $details, ?string $uniqueKey = null, array $fields = []): array
    {
        $details['unique'] = ['key' => $uniqueKey ?? $row->dedupKey];
        $details['source'] = $this->config->getSource($row->storeId ?: null);
        $details['timestamp'] = IstDate::format($row->createdAt);

        $event = ['type' => $type, 'email' => $row->email];
        $fields = array_filter($fields, static fn ($v) => $v !== null && $v !== '');
        if ($fields) {
            $event['fields'] = $fields;
        }
        $event['details'] = $details;

        return $event;
    }
}
