<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Newsletter;

final class NewsletterChange
{
    private const SUBSCRIBED = 1;
    private const UNSUBSCRIBED = 3;

    /**
     * @return 'subscribe'|'unsubscribe'|null
     */
    public static function decide(?int $origStatus, int $newStatus, int $origCustomerId, int $newCustomerId): ?string
    {
        // A guest subscriber being linked to a new account re-saves the row; any status
        // change there is a side effect, not the customer's choice.
        if ($origStatus !== null && $origCustomerId === 0 && $newCustomerId > 0) {
            return null;
        }
        if ($origStatus === $newStatus) {
            return null;
        }

        return match ($newStatus) {
            self::SUBSCRIBED => 'subscribe',
            self::UNSUBSCRIBED => 'unsubscribe',
            default => null, // pending confirmation / unconfirmed
        };
    }

    public static function dedupKey(int $subscriberId, string $action, int $timestamp): string
    {
        return $action . ':' . $subscriberId . ':' . gmdate('YmdHi', $timestamp);
    }
}
