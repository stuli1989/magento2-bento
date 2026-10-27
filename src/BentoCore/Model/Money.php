<?php
/**
 * Money helpers: paise integers for Bento revenue, ₹ display strings for emails.
 *
 * Display strings use Indian digit grouping (1,00,000.00) and always 2 decimals,
 * so Bento Liquid templates print them verbatim — no divided_by arithmetic.
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

final class Money
{
    private const SYMBOL = '₹';

    public static function paise(float $rupees): int
    {
        return (int)round($rupees * 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function rupees(float $amount): float
    {
        return round($amount, 2);
    }

    public static function displayPaise(int $paise): string
    {
        return self::display($paise / 100);
    }

    public static function display(float $rupees): string
    {
        $paise = self::paise(abs($rupees));
        $whole = intdiv($paise, 100);
        $fraction = str_pad((string)($paise % 100), 2, '0', STR_PAD_LEFT);

        $sign = ($rupees < 0 && $paise > 0) ? '-' : '';

        return $sign . self::SYMBOL . self::groupIndian((string)$whole) . '.' . $fraction;
    }

    /** Whole rupees, no decimals ("₹2,999"), for thresholds and gaps in email copy. */
    public static function displayWhole(int $rupees): string
    {
        return ($rupees < 0 ? '-' : '') . self::SYMBOL . self::groupIndian((string)abs($rupees));
    }

    private static function groupIndian(string $digits): string
    {
        if (strlen($digits) <= 3) {
            return $digits;
        }
        $lastThree = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);

        return $rest . ',' . $lastThree;
    }
}
