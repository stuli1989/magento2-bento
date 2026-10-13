<?php
/**
 * The ONE date formatter for everything the plugin sends to Bento.
 *
 * ISO-8601, Asia/Kolkata offset, second precision: 2026-10-13T15:30:00+05:30.
 * Matches WISMO's dates. Bento flows compare these strings lexically
 * (visitor.last_purchase_date > event.details.timestamp), so every date the
 * plugin emits — event timestamps and profile date fields — must come from here.
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

final class IstDate
{
    public const FORMAT = 'Y-m-d\TH:i:sP';
    private const ZONE = 'Asia/Kolkata';

    /**
     * @param string|int|\DateTimeInterface $value DB datetime strings are UTC (Magento convention).
     */
    public static function format(string|int|\DateTimeInterface $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $dt = \DateTimeImmutable::createFromInterface($value);
        } elseif (is_int($value)) {
            $dt = (new \DateTimeImmutable('@' . $value));
        } else {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        return $dt->setTimezone(new \DateTimeZone(self::ZONE))->format(self::FORMAT);
    }

    public static function formatOrNull(string|int|\DateTimeInterface|null $value): ?string
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }
        if (is_string($value) && str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return self::format($value);
    }

    /**
     * "18 October" — the IST calendar day of a date, for emails (e.g. coupon.expires_at_display).
     * ISO strings keep their own offset; DB strings are UTC.
     */
    public static function dayMonthOrNull(string|int|\DateTimeInterface|null $value): ?string
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }
        if (is_string($value) && str_starts_with($value, '0000-00-00')) {
            return null;
        }
        // format() yields an IST ISO string (an offset in the input wins over the UTC default).
        return (new \DateTimeImmutable(self::format($value)))->format('j F');
    }

    public static function now(): string
    {
        return self::format(time());
    }
}
