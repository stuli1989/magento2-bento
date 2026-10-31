<?php
/**
 * Pure: the "next picks" rule and its subscriber fields (PP-03 and later post-purchase emails).
 *
 * Per customer: pinned picks of their primary_medium that are eligible, then that medium's eligible
 * products by recent units sold; anything they have bought is skipped; short lists are filled from
 * "any" the same way. Ranking and exclusion are parent level (a configurable child counts as its parent);
 * a configurable is then shown as its best-selling in-stock variant, e.g. "Liquin Original - 1 Litre".
 *
 * Also the two lists built from the same filters: new arrivals (new_in_*, newest first in the customer's
 * medium) and usuals (usual_*, what the customer reorders).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Picks;

use ArtLounge\BentoEvents\Model\Payload\SnapshotFields;

final class NextPicks
{
    public const SLOTS = 3;
    public const ANY = 'any';

    /**
     * @param ?string $medium the customer's primary_medium (null/'' = none)
     * @param array<string,int[]> $ranked medium => eligible parent ids, best seller first
     * @param array<string,int[]> $pinned medium => pinned parent ids, admin order
     * @param int[] $bought parent ids to skip (bought, or already shown in another list)
     * @param bool $fillFromAny false: a short list stays short; a blank medium still uses "any"
     * @return int[] up to SLOTS parent ids
     */
    public static function choose(?string $medium, array $ranked, array $pinned, array $bought, bool $fillFromAny = true): array
    {
        $skip = array_fill_keys($bought, true);
        $out = [];
        $mediums = (string)$medium === '' ? [self::ANY] : ($fillFromAny ? [(string)$medium, self::ANY] : [(string)$medium]);
        foreach (array_unique($mediums) as $m) {
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
     * One customer's next picks, then their new arrivals: never bought, never one of those picks, no fill
     * from "any", and only products added after the customer's last order ("new since you last shopped") —
     * the window start still applies, so the effective cutoff is the later of the two.
     *
     * @param int[] $bought parent ids the customer has ever bought
     * @param array<string,int[]> $ranked $pinned next-pick candidates (see choose())
     * @param array<string,int[]> $newest medium (and "any") => new-arrival parent ids, newest first
     * @param ?string $lastOrderAt the customer's latest non-canceled order created_at (null = never ordered)
     * @param array<int,string> $createdAt new-arrival parent id => created_at (same clock as $lastOrderAt)
     * @return array{0:int[],1:int[]} [next pick ids, new-in ids]
     */
    public static function picksAndNewIn(
        ?string $medium,
        array $bought,
        array $ranked,
        array $pinned,
        array $newest,
        ?string $lastOrderAt = null,
        array $createdAt = []
    ): array {
        $picks = self::choose($medium, $ranked, $pinned, $bought);
        if ($lastOrderAt !== null && $lastOrderAt !== '') {
            $newest = array_map(
                static fn (array $ids) => array_values(array_filter($ids, static fn (int $id) => ($createdAt[$id] ?? '') > $lastOrderAt)),
                $newest
            );
        }

        return [$picks, self::choose($medium, $newest, [], array_merge($bought, $picks), false)];
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
     * Parents created at/after $since. A parent counts only when it is new itself: a new variant
     * added to an old configurable is not a new arrival.
     *
     * @param array<int,string> $createdAt product id => created_at ('Y-m-d H:i:s', UTC)
     * @param array<int,int> $parentOf child id => configurable parent id
     * @return int[]
     */
    public static function newArrivals(array $createdAt, array $parentOf, string $since): array
    {
        $out = [];
        foreach ($createdAt as $id => $at) {
            $parent = $parentOf[(int)$id] ?? (int)$id;
            if ((string)($createdAt[$parent] ?? '') >= $since) {
                $out[$parent] = $parent;
            }
        }

        return array_values($out);
    }

    /**
     * Ids newest first by created_at, ties by higher id (deterministic).
     *
     * @param int[] $ids
     * @param array<int,string> $createdAt id => 'Y-m-d H:i:s'
     * @return int[]
     */
    public static function newestFirst(array $ids, array $createdAt): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        usort($ids, static fn (int $a, int $b) => [($createdAt[$b] ?? ''), $b] <=> [($createdAt[$a] ?? ''), $a]);

        return $ids;
    }

    /**
     * The customer's usuals: parents bought in ≥ 2 separate orders (units don't count, so one bulk order
     * is not a habit), most orders first, then most recent purchase; only currently eligible parents.
     * Each is shown as the variant the customer ordered most often if that variant is sellable,
     * otherwise the best-selling sellable variant; a non-configurable shows itself.
     *
     * @param array $lines [{order_id, created_at, parent_id, product_id}] product_id = what was sold (variant or itself)
     * @param array<int,mixed> $eligible parent id set (enabled, visible, in stock)
     * @param array<int,mixed> $sellable variant id set (enabled, in stock)
     * @param array<int,int> $bestVariant configurable id => best-selling sellable variant
     * @return array<int,int> up to SLOTS: parent id => id to show, in rank order
     */
    public static function usuals(array $lines, array $eligible, array $sellable, array $bestVariant): array
    {
        $orders = [];
        $last = [];
        $variantOrders = [];
        foreach ($lines as $l) {
            $parent = (int)$l['parent_id'];
            $order = (int)$l['order_id'];
            $orders[$parent][$order] = true;
            $last[$parent] = max($last[$parent] ?? '', (string)$l['created_at']);
            if ((int)$l['product_id'] !== $parent) {
                $variantOrders[$parent][(int)$l['product_id']][$order] = true;
            }
        }
        $ranked = array_keys(array_filter($orders, static fn (array $o, int $p) => count($o) >= 2 && isset($eligible[$p]), ARRAY_FILTER_USE_BOTH));
        usort($ranked, static fn (int $a, int $b) => [count($orders[$b]), $last[$b], $b] <=> [count($orders[$a]), $last[$a], $a]);

        $out = [];
        foreach (array_slice($ranked, 0, self::SLOTS) as $parent) {
            $favourite = null;
            $most = 0;
            foreach ($variantOrders[$parent] ?? [] as $variant => $o) {
                if (count($o) > $most || (count($o) === $most && $variant < $favourite)) {
                    [$favourite, $most] = [$variant, count($o)];
                }
            }
            $out[$parent] = $favourite !== null && isset($sellable[$favourite])
                ? $favourite
                : ($bestVariant[$parent] ?? $parent);
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
     * {prefix}{1..3}_{name,url,image_url}; unused slots are CLEAR so an older, longer list never shows through.
     *
     * @param int[] $ids chosen parent ids
     * @param array<int,array{name:?string,url:?string,image:?string}> $products CatalogLookup / display() rows
     * @param string $prefix 'next_pick_', 'new_in_' or 'usual_'
     */
    public static function fields(array $ids, array $products, string $prefix = 'next_pick_'): array
    {
        $fields = [];
        $ids = array_values($ids);
        for ($n = 1; $n <= self::SLOTS; $n++) {
            $p = $products[$ids[$n - 1] ?? 0] ?? [];
            $fields[$prefix . $n . '_name'] = (string)($p['name'] ?? '') ?: SnapshotFields::CLEAR;
            $fields[$prefix . $n . '_url'] = (string)($p['url'] ?? '') ?: SnapshotFields::CLEAR;
            $fields[$prefix . $n . '_image_url'] = (string)($p['image'] ?? '') ?: SnapshotFields::CLEAR;
        }

        return $fields;
    }
}
