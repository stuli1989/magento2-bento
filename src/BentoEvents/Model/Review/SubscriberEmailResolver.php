<?php
/**
 * bento_uuid → subscriber email, via Bento's read-only subscriber lookup. Used at send time (cron),
 * never inside the customer's request.
 *
 * null  = Bento doesn't know that uuid (the event is skipped — no identity, no send).
 * throw = transient/auth failure (the outbox row is retried with backoff).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Review;

use ArtLounge\BentoCore\Api\BentoClientInterface;

class SubscriberEmailResolver
{
    public function __construct(private readonly BentoClientInterface $client)
    {
    }

    public function emailForUuid(string $uuid, ?int $storeId = null): ?string
    {
        $result = $this->client->fetchSubscriberByUuid($uuid, $storeId);
        if ($result->getStatus() === 404) {
            return null;
        }
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Bento subscriber lookup failed: ' . $result->describe());
        }
        $body = json_decode($result->getBody(), true);
        $email = is_array($body) ? ($body['data']['attributes']['email'] ?? null) : null;
        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        return strtolower(trim($email));
    }
}
