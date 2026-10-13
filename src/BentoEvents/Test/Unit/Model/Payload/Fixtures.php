<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

/**
 * Plain snapshot arrays as produced by the Magento-facing loaders.
 */
final class Fixtures
{
    public static function order(array $override = []): array
    {
        return array_replace_recursive([
            'order_id' => 45,
            'increment_id' => '100045',
            'quote_id' => 9001,
            'created_at' => '2026-10-13 10:00:00',
            'state' => 'processing',
            'status' => 'processing',
            'currency' => 'INR',
            'grand_total' => 1488.6,
            'subtotal' => 1400.0,
            'shipping_amount' => 88.6,
            'discount_amount' => 0.0,
            'tax_amount' => 0.0,
            'email' => 'kshitij+bentotest@gmail.com',
            'firstname' => 'Kshitij',
            'lastname' => 'Shah',
            'customer_id' => null,
            'customer_group' => 'NOT LOGGED IN',
            'phone' => '98200 12345',
            'payment_method' => 'cashondelivery',
            'payment_title' => 'Cash On Delivery',
            'shipping_method' => 'flatrate_flatrate',
            'shipping_title' => 'Standard',
            'is_virtual' => false,
            'billing' => ['firstname' => 'Kshitij', 'lastname' => 'Shah', 'street' => ['1 Road'], 'city' => 'Mumbai',
                'region' => 'Maharashtra', 'postcode' => '400001', 'country_id' => 'IN', 'telephone' => '98200 12345'],
            'shipping_address' => null,
            'items' => [
                ['item_id' => 1, 'product_id' => 11, 'sku' => 'WN-COT-12', 'name' => 'Cotman Watercolour 12 Tubes',
                    'qty' => 2.0, 'price' => 500.0, 'row_total' => 1000.0, 'url' => 'https://x/cotman', 'image' => 'https://x/m.jpg',
                    'brand' => 'Winsor & Newton', 'categories' => ['Paints and Mediums', 'Watercolour Paint & Mediums']],
                ['item_id' => 2, 'product_id' => 12, 'sku' => 'BR-1', 'name' => 'Round Brush 6',
                    'qty' => 1.0, 'price' => 400.0, 'row_total' => 400.0, 'url' => null, 'image' => null,
                    'brand' => null, 'categories' => ['Paints and Mediums', 'Brushes and Painting Tools']],
            ],
        ], $override);
    }
}
