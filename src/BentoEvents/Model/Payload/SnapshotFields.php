<?php
/**
 * Flat subscriber "snapshot" fields for Bento email templates (The Order Sheet).
 *
 * Bento custom fields are primitives only, and sequence emails can read only visitor.* — so the
 * cart/order an email shows must be flattened onto the subscriber. These fields ride on the event's
 * `fields` (applied to the subscriber with the event), replacing the per-slot "Update Field" nodes
 * the flows used to need.
 *
 * Always exactly SLOTS slots: unused slots are sent as CLEAR so a shorter cart never shows lines left over
 * from an older one. Templates show 1–5 lines, or lines 1–4 + "+ N more" when *_item_count > 5.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Model\Money;

final class SnapshotFields
{
    public const SLOTS = 5;
    /** Bento keeps the old value when a field is sent as '' or null. A space replaces it and Liquid reads it as blank. */
    public const CLEAR = ' ';

    /**
     * $cart_abandoned / $checkout_abandoned.
     *
     * @param array $items LineItems::map() output (every visible line)
     * @param float $subtotal rupees
     */
    public static function cart(array $items, float $subtotal, ?string $recoveryUrl): array
    {
        $fields = [];
        $items = array_values($items);
        for ($n = 1; $n <= self::SLOTS; $n++) {
            $item = $items[$n - 1] ?? null;
            $p = 'last_cart_item_' . $n . '_';
            $fields[$p . 'name'] = self::str($item['product_name'] ?? null);
            $fields[$p . 'qty'] = $item ? (int)$item['quantity'] : '';
            $fields[$p . 'price_display'] = self::str($item['row_total_display'] ?? null); // line total, so the rows add up to the subtotal
            $fields[$p . 'url'] = self::str($item['product_url'] ?? null);
            $fields[$p . 'image_url'] = self::str($item['product_image_url'] ?? null);
        }
        $fields['last_cart_item_count'] = count($items);
        $fields['last_cart_subtotal'] = Money::paise($subtotal);
        $fields['last_cart_subtotal_display'] = Money::display($subtotal);
        $fields['last_cart_recovery_url'] = self::str($recoveryUrl);

        return $fields;
    }

    /**
     * $cart_abandoned and $checkout_abandoned (D5/D6). Eligible → the code, its auto-apply link and the expiry day;
     * otherwise blanked so an earlier cart's coupon can't resurface.
     *
     * @param array|null $coupon details.coupon {code, recovery_url, expires_at_display, value_display} or null
     */
    public static function cartCoupon(?array $coupon): array
    {
        return [
            'last_cart_coupon_eligible' => $coupon ? 'yes' : 'no',
            'last_cart_coupon_code' => self::str($coupon['code'] ?? null),
            'last_cart_coupon_url' => self::str($coupon['recovery_url'] ?? null),
            'last_cart_coupon_expires' => self::str($coupon['expires_at_display'] ?? null),
            'last_cart_coupon_value_display' => self::str($coupon['value_display'] ?? null),
        ];
    }

    /**
     * $purchase. Order snapshots carry no prices (the templates don't show them).
     *
     * @param array $items LineItems::map() output
     */
    public static function order(array $items): array
    {
        $fields = [];
        $items = array_values($items);
        for ($n = 1; $n <= self::SLOTS; $n++) {
            $item = $items[$n - 1] ?? null;
            $p = 'last_order_item_' . $n . '_';
            $fields[$p . 'name'] = self::str($item['product_name'] ?? null);
            $fields[$p . 'qty'] = $item ? (int)$item['quantity'] : '';
            $fields[$p . 'url'] = self::str($item['product_url'] ?? null);
            $fields[$p . 'image_url'] = self::str($item['product_image_url'] ?? null);
        }
        $fields['last_order_item_count'] = count($items);

        return $fields;
    }

    private static function str(mixed $v): string
    {
        return $v === null ? '' : (string)$v;
    }
}
