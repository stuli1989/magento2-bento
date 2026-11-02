<?php
/**
 * Pure: a customer's order history → Bento profile fields.
 *
 * Counted orders = state processing/complete (canceled and closed=fully-refunded excluded);
 * partial refunds reduce spend. Guest + registered orders are merged upstream by lower-cased email.
 * Never emits last_order_date (WISMO owns it).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Profile;

use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoCore\Model\Money;
use ArtLounge\BentoEvents\Model\Purchase\PurchaseDetector;

class StatsCalculator
{
    public const COUNTED_STATES = ['processing', 'complete'];

    /**
     * @param array $orders  [{increment_id, state, created_at, grand_total, total_refunded, purchased_at?}]
     * @param array $items   [{category_ids:int[], spend:float}] — items of counted orders only
     * @param array $account {is_registered:bool, created_at:?string}
     * @param array<int,string> $mediumMap
     */
    public function calculate(array $orders, array $items, array $account, CategoryTree $tree, array $mediumMap): array
    {
        $counted = array_values(array_filter(
            $orders,
            static fn (array $o) => PurchaseDetector::isPurchased((string)($o['state'] ?? ''), (string)($o['status'] ?? ''))
        ));
        usort($counted, static fn ($a, $b) => strcmp((string)$a['created_at'], (string)$b['created_at']));

        $spend = 0.0;
        $lastPurchase = null;
        foreach ($counted as $o) {
            $spend += max(0.0, (float)$o['grand_total'] - (float)($o['total_refunded'] ?? 0));
            $when = !empty($o['purchased_at']) ? $o['purchased_at'] : $o['created_at'];
            if ($lastPurchase === null || strcmp($when, $lastPurchase) > 0) {
                $lastPurchase = $when;
            }
        }
        $count = count($counted);
        $first = $counted[0]['created_at'] ?? null;
        $last = $count ? $counted[$count - 1] : null;

        $customerSince = !empty($account['is_registered']) && !empty($account['created_at'])
            ? $account['created_at']
            : $first;

        [$topCategories, $primaryMedium] = $this->profile($items, $tree, $mediumMap);

        return [
            'order_count' => $count,
            'lifetime_spend' => Money::rupees($spend),
            'aov' => $count ? Money::rupees($spend / $count) : 0.0,
            'first_order_date' => IstDate::formatOrNull($first),
            'last_purchase_date' => IstDate::formatOrNull($lastPurchase),
            'last_order_number' => $last ? (string)$last['increment_id'] : null,
            'customer_since' => IstDate::formatOrNull($customerSince),
            'is_registered' => !empty($account['is_registered']),
            'top_categories' => $topCategories,
            'primary_medium' => $primaryMedium,
        ];
    }

    /** @return array{0:string,1:?string} */
    private function profile(array $items, CategoryTree $tree, array $mediumMap): array
    {
        $byCategory = [];
        $byMedium = [];
        foreach ($items as $item) {
            $spend = (float)($item['spend'] ?? 0);
            $ids = array_map('intval', $item['category_ids'] ?? []);
            foreach ($tree->topCategoryNames($ids) as $name) {
                $byCategory[$name] = ($byCategory[$name] ?? 0.0) + $spend;
            }
            $medium = $tree->mediumFor($ids, $mediumMap);
            if ($medium !== null) {
                $byMedium[$medium] = ($byMedium[$medium] ?? 0.0) + $spend;
            }
        }

        $top = $this->rank($byCategory);
        $mediums = $this->rank($byMedium);

        return [implode(', ', array_slice($top, 0, 3)), $mediums[0] ?? null];
    }

    /** Keys sorted by value desc, ties alphabetical (deterministic). @return string[] */
    private function rank(array $totals): array
    {
        $keys = array_map('strval', array_keys($totals));
        usort($keys, static function ($a, $b) use ($totals) {
            $cmp = $totals[$b] <=> $totals[$a];
            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });

        return $keys;
    }
}
