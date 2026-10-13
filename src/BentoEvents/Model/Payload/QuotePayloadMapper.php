<?php
/**
 * Pure: cart snapshot → details for $checkout_started / $checkout_abandoned / $cart_abandoned.
 * Accepts quote snapshots (QuoteSnapshotLoader) and order snapshots (dismissed checkouts)
 * — both carry quote_id, money, contact and items.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoCore\Model\Money;

class QuotePayloadMapper
{
    public function details(array $s): array
    {
        $items = LineItems::map($s['items'] ?? []);
        $currency = (string)($s['currency'] ?? 'INR');
        $grand = (float)($s['grand_total'] ?? 0);
        $subtotal = (float)($s['subtotal'] ?? 0);

        return [
            'cart_id' => (string)$s['quote_id'],
            'cart' => [
                'quote_id' => (int)$s['quote_id'],
                'created_at' => IstDate::formatOrNull($s['created_at'] ?? null),
                'updated_at' => IstDate::formatOrNull($s['updated_at'] ?? null),
                'items' => $items,
            ],
            'item_count' => count($items),
            'value' => ['amount' => Money::paise($grand), 'currency' => $currency],
            'financials' => [
                'total_value' => Money::paise($grand),
                'total_value_display' => Money::display($grand),
                'total_display' => Money::display($grand), // name Bento flows use
                'subtotal' => Money::paise($subtotal),
                'subtotal_display' => Money::display($subtotal),
                'discount_amount' => Money::paise(abs((float)($s['discount_amount'] ?? 0))),
                'discount_amount_display' => Money::display(abs((float)($s['discount_amount'] ?? 0))),
                'currency_code' => $currency,
            ],
            'summary' => [
                'product_names' => array_values(array_column($items, 'name')),
                'product_categories' => LineItems::categories($s['items'] ?? []),
            ],
            'customer' => [
                'email' => $s['email'] ?? null,
                'firstname' => $s['firstname'] ?? null,
                'lastname' => $s['lastname'] ?? null,
                'phone' => Phone::normalize($s['phone'] ?? null),
            ],
        ];
    }

    public function fields(array $s): array
    {
        return array_filter([
            'first_name' => $s['firstname'] ?? null,
            'last_name' => $s['lastname'] ?? null,
            'phone' => Phone::normalize($s['phone'] ?? null),
        ]);
    }
}
