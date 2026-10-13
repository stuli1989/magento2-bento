<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

final class Phone
{
    /**
     * Indian mobiles → E.164 (+91XXXXXXXXXX); explicit +country numbers kept in E.164;
     * anything else returned as bare digits. Null when there are no digits.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $hasPlus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (strlen($digits) === 11 && $digits[0] === '0' && preg_match('/^0[6-9]/', $digits)) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10 && preg_match('/^[6-9]/', $digits)) {
            return '+91' . $digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91') && preg_match('/^91[6-9]/', $digits)) {
            return '+' . $digits;
        }

        return $hasPlus ? '+' . $digits : $digits;
    }
}
