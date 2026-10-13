<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Outbox;

final class Backoff
{
    /** Retry delays after failure #1..#5: 1m, 5m, 30m, 2h, 12h. Failure #6 parks. */
    private const DELAYS = [1 => 60, 2 => 300, 3 => 1800, 4 => 7200, 5 => 43200];

    public static function nextDelay(int $failedAttempts): ?int
    {
        return self::DELAYS[$failedAttempts] ?? null;
    }
}
