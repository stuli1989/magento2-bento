<?php
/**
 * Line-item mapping shared by order and quote payloads.
 * Input item: {item_id, product_id, sku, name, qty, price, row_total, url?, image?, brand?, categories?}
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Model\Money;

final class LineItems
{
    public static function map(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $price = (float)($item['price'] ?? 0);
            $rowTotal = (float)($item['row_total'] ?? 0);
            $qty = (int)round((float)($item['qty'] ?? 0));
            $mapped = [
                'item_id' => (int)($item['item_id'] ?? 0),
                'product_id' => (string)($item['product_id'] ?? ''),
                // Canonical Bento Sales tab keys
                'product_sku' => $item['sku'] ?? null,
                'product_name' => $item['name'] ?? null,
                'product_price' => Money::paise($price),
                'product_price_display' => Money::display($price),
                'product_permalink' => $item['url'] ?? null,
                'quantity' => $qty,
                // Liquid-friendly aliases
                'sku' => $item['sku'] ?? null,
                'name' => $item['name'] ?? null,
                'qty' => $qty,
                'price' => Money::paise($price),
                'price_display' => Money::display($price),
                'row_total' => Money::paise($rowTotal),
                'row_total_display' => Money::display($rowTotal),
                'product_url' => $item['url'] ?? null,
                'product_image_url' => $item['image'] ?? null,
            ];
            if (!empty($item['brand'])) {
                $mapped['brand'] = $item['brand'];
            }
            if (isset($item['categories'])) {
                $mapped['categories'] = array_values($item['categories']);
            }
            $out[] = $mapped;
        }

        return $out;
    }

    /** Unique category names across items, as a JSON list. */
    public static function categories(array $items): array
    {
        $all = [];
        foreach ($items as $item) {
            foreach ($item['categories'] ?? [] as $name) {
                $all[$name] = true;
            }
        }

        return array_values(array_map('strval', array_keys($all)));
    }
}
