<?php
/**
 * Pure: which catalog image file a product's email thumbnail uses.
 * Order: thumbnail role → small_image → base image → the same chain on the configurable parent.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

final class ProductImage
{
    public const ROLES = ['thumbnail', 'small_image', 'image'];

    /** @param array<string,mixed> $attributes role => file ('/a/b/ab.jpg'), 'no_selection' or empty */
    public static function pickFile(array $attributes): ?string
    {
        foreach (self::ROLES as $role) {
            $file = $attributes[$role] ?? null;
            if (is_string($file) && $file !== '' && $file !== 'no_selection') {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<int,array> $own product id => role attributes
     * @param array<int,int> $parentOf product id => configurable parent id (only for products that have one)
     * @param array<int,array> $parents parent id => role attributes
     * @return array<int,?string> product id => file
     */
    public static function resolve(array $own, array $parentOf, array $parents): array
    {
        $out = [];
        foreach ($own as $id => $attributes) {
            $file = self::pickFile($attributes);
            if ($file === null && isset($parentOf[$id])) {
                $file = self::pickFile($parents[$parentOf[$id]] ?? []);
            }
            $out[$id] = $file;
        }

        return $out;
    }
}
