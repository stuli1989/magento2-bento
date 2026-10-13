<?php
/**
 * Pure mapping: order snapshot (see OrderSnapshotLoader) → Bento event details.
 * Shared by $purchase, $OrderCanceled, $OrderRefunded and dismissed $checkout_abandoned.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoCore\Model\Money;

class OrderPayloadMapper
{
    private const COD_METHODS = ['cashondelivery', 'cod', 'razorpay_cod'];

    public function details(array $o): array
    {
        $items = LineItems::map($o['items'] ?? []);
        $currency = (string)($o['currency'] ?? 'INR');
        $phone = Phone::normalize($o['phone'] ?? null);

        return [
            'order' => [
                'id' => (int)$o['order_id'],
                'increment_id' => (string)$o['increment_id'],
                'increment_id_display' => '#' . $o['increment_id'],
                'quote_id' => (int)($o['quote_id'] ?? 0),
                'created_at' => IstDate::formatOrNull($o['created_at'] ?? null),
                'status' => $o['status'] ?? null,
                'state' => $o['state'] ?? null,
            ],
            'value' => ['amount' => Money::paise((float)$o['grand_total']), 'currency' => $currency],
            'currency' => $currency,
            'financials' => $this->money([
                'total_value' => (float)$o['grand_total'],
                'subtotal' => (float)($o['subtotal'] ?? 0),
                'shipping_amount' => (float)($o['shipping_amount'] ?? 0),
                'discount_amount' => abs((float)($o['discount_amount'] ?? 0)),
                'tax_amount' => (float)($o['tax_amount'] ?? 0),
            ]) + ['currency_code' => $currency],
            'flags' => [
                'discounted' => abs((float)($o['discount_amount'] ?? 0)) > 0.0001,
                'is_virtual' => (bool)($o['is_virtual'] ?? false),
                'is_guest' => empty($o['customer_id']),
            ],
            'cart' => ['items' => $items],
            'summary' => [
                'ordered_products' => array_values(array_column($items, 'name')),
                'ordered_product_count' => count($items),
                'product_categories' => LineItems::categories($o['items'] ?? []),
            ],
            'customer' => [
                'email' => $o['email'] ?? null,
                'firstname' => $o['firstname'] ?? null,
                'lastname' => $o['lastname'] ?? null,
                'customer_id' => !empty($o['customer_id']) ? (int)$o['customer_id'] : null,
                'customer_group' => $o['customer_group'] ?? null,
                'phone' => $phone,
            ],
            'addresses' => [
                'billing' => $o['billing'] ?? null,
                'shipping' => $o['shipping_address'] ?? null,
            ],
            'payment' => [
                'method' => $o['payment_method'] ?? null,
                'method_title' => $o['payment_title'] ?? null,
                'is_cod' => in_array($o['payment_method'] ?? '', self::COD_METHODS, true),
            ],
            'shipping_method' => [
                'method' => $o['shipping_method'] ?? null,
                'method_title' => $o['shipping_title'] ?? null,
            ],
            'cart_id' => (string)($o['quote_id'] ?? ''),
        ];
    }

    /** Light subscriber fields carried on the event itself. */
    public function subscriberFields(array $o): array
    {
        return array_filter([
            'first_name' => $o['firstname'] ?? null,
            'last_name' => $o['lastname'] ?? null,
            'phone' => Phone::normalize($o['phone'] ?? null),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    public function refund(array $cm): array
    {
        $items = [];
        foreach ($cm['items'] ?? [] as $item) {
            if ((float)($item['qty'] ?? 0) <= 0) {
                continue;
            }
            $items[] = [
                'sku' => $item['sku'] ?? null,
                'name' => $item['name'] ?? null,
                'qty' => (int)round((float)$item['qty']),
                'row_total' => Money::paise((float)($item['row_total'] ?? 0)),
                'row_total_display' => Money::display((float)($item['row_total'] ?? 0)),
            ];
        }

        return [
            'creditmemo_id' => (int)$cm['creditmemo_id'],
            'increment_id' => (string)$cm['increment_id'],
            'created_at' => IstDate::formatOrNull($cm['created_at'] ?? null),
            'refunded_items' => $items,
        ] + $this->money([
            'refund_amount' => (float)$cm['grand_total'],
            'adjustment_positive' => (float)($cm['adjustment_positive'] ?? 0),
            'adjustment_negative' => (float)($cm['adjustment_negative'] ?? 0),
        ]);
    }

    /** @param array<string,float> $amounts → key (paise) + key_display (₹) */
    private function money(array $amounts): array
    {
        $out = [];
        foreach ($amounts as $key => $rupees) {
            $out[$key] = Money::paise($rupees);
            $out[$key . '_display'] = Money::display($rupees);
        }

        return $out;
    }
}
