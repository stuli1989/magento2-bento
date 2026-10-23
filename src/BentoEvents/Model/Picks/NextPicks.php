<?php
/**
 * Pure: the "next picks" rule and its subscriber fields (PP-03 and later post-purchase emails).
 *
 * Per customer: pinned picks of their primary_medium that are eligible, then that medium's eligible
 * products by recent units sold; anything they have bought is skipped; short lists are filled from
 * "any" the same way. Ranking and exclusion are parent level (a configurable child counts as its parent);
 * a configurable is then shown as its best-selling in-stock variant, e.g. "Liquin Original - 1 Litre".
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Picks;

final class NextPicks
{
    public const SLOTS = 3;
    public const ANY = 'any';

    /**
     * @param ?string $medium the customer's primary_medium (null/'' = none)
     * @param array<string,int[]> $ranked medium => eligible parent ids, best seller first
     * @param array<string,int[]> $pinned medium => pinned parent ids, admin order
     * @param int[] $bought parent ids the customer has ever bought
     * @return int[] up to SLOTS parent ids
     */
    public static function choose(?string $medium, array $ranked, array $pinned, array $bought): array
    {
        $skip = array_fill_keys($bought, true);
        $out = [];
        foreach (array_unique(array_filter([(string)$medium, self::ANY], 'strlen')) as $m) {
            $eligible = $ranked[$m] ?? [];
            // A pin only counts if it is eligible (in the medium's add-on categories, enabled, visible, in stock).
            $pins = array_values(array_intersect($pinned[$m] ?? [], $eligible));
            foreach (array_merge($pins, $eligible) as $id) {
                if (count($out) === self::SLOTS) {
                    return $out;
                }
                if (!isset($skip[$id])) {
                    $out[] = $id;
                    $skip[$id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Parents that may be recommended: enabled and visible, and in stock — a configurable through any
     * enabled in-stock child, anything else through its own stock.
     *
     * @param int[] $parents
     * @param array<int,string> $types id => type_id
     * @param array<int,int[]> $children configurable id => child ids
     * @param array<int,mixed> $enabled $visible $inStock id-keyed sets (in stock = ≥ minimum stock)
     * @return int[]
     */
    public static function eligible(array $parents, array $types, array $children, array $enabled, array $visible, array $inStock): array
    {
        $out = [];
        foreach ($parents as $id) {
            if (!isset($enabled[$id], $visible[$id])) {
                continue;
            }
            $stockIds = ($types[$id] ?? '') === 'configurable' ? ($children[$id] ?? []) : [$id];
            foreach ($stockIds as $sid) {
                if (isset($enabled[$sid], $inStock[$sid])) {
                    $out[] = $id;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The variant each configurable is shown as: its best-selling enabled, in-stock child (ties: lowest id).
     *
     * @param int[] $parents eligible parent ids
     * @param array<int,int[]> $children configurable id => child ids
     * @param array<int,mixed> $enabled $inStock id-keyed sets
     * @param array<int,float> $units product id => units sold in the window
     * @return array<int,int> configurable id => variant id
     */
    public static function variants(array $parents, array $children, array $enabled, array $inStock, array $units): array
    {
        $out = [];
        foreach ($parents as $id) {
            $sellable = array_values(array_filter(
                $children[$id] ?? [],
                static fn (int $c) => isset($enabled[$c], $inStock[$c])
            ));
            if ($sellable) {
                $out[$id] = self::rank($sellable, $units)[0];
            }
        }

        return $out;
    }

    /**
     * What each pick shows: a configurable's variant name and image (the parent's image if the variant has none),
     * always the parent's URL (variants are not visible on their own).
     *
     * @param int[] $parents
     * @param array<int,int> $variantOf configurable id => variant id
     * @param array<int,array{name:?string,url:?string,image:?string}> $products CatalogLookup rows for parents and variants
     * @return array<int,array{name:?string,url:?string,image:?string}> parent id => row
     */
    public static function display(array $parents, array $variantOf, array $products): array
    {
        $out = [];
        foreach ($parents as $id) {
            $parent = $products[$id] ?? [];
            $variant = $products[$variantOf[$id] ?? 0] ?? [];
            $out[$id] = [
                'name' => $variant['name'] ?? $parent['name'] ?? null,
                'url' => $parent['url'] ?? null,
                'image' => $variant['image'] ?? $parent['image'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Product ids → sold-unit totals per parent.
     *
     * @param array<int,float> $qtyByProduct product id => units
     * @param array<int,int> $parentOf child id => configurable parent id
     * @return array<int,float> parent id => units
     */
    public static function rollUp(array $qtyByProduct, array $parentOf): array
    {
        $out = [];
        foreach ($qtyByProduct as $id => $qty) {
            $parent = $parentOf[(int)$id] ?? (int)$id;
            $out[$parent] = ($out[$parent] ?? 0.0) + (float)$qty;
        }

        return $out;
    }

    /**
     * Eligible parent ids ordered by units sold desc, ties by id (deterministic).
     *
     * @param int[] $eligible
     * @param array<int,float> $units parent id => units sold in the window
     * @return int[]
     */
    public static function rank(array $eligible, array $units): array
    {
        $eligible = array_values(array_unique(array_map('intval', $eligible)));
        usort($eligible, static fn (int $a, int $b) => [($units[$b] ?? 0.0), $a] <=> [($units[$a] ?? 0.0), $b]);

        return $eligible;
    }

    /**
     * next_pick_{1..3}_{name,url,image_url}; unused slots are '' so an older, longer list never shows through.
     *
     * @param int[] $ids chosen parent ids
     * @param array<int,array{name:?string,url:?string,image:?string}> $products CatalogLookup rows
     */
    public static function fields(array $ids, array $products): array
    {
        $fields = [];
        $ids = array_values($ids);
        for ($n = 1; $n <= self::SLOTS; $n++) {
            $p = $products[$ids[$n - 1] ?? 0] ?? [];
            $fields['next_pick_' . $n . '_name'] = (string)($p['name'] ?? '');
            $fields['next_pick_' . $n . '_url'] = (string)($p['url'] ?? '');
            $fields['next_pick_' . $n . '_image_url'] = (string)($p['image'] ?? '');
        }

        return $fields;
    }
}
