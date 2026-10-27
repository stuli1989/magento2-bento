<?php
/**
 * The live discount ladder, so no email ever carries a typed threshold or percentage.
 *
 * Steps come from the same two places the storefront uses:
 *  - free shipping: carriers/freeshipping (active + free_shipping_subtotal), the rule Magento applies;
 *  - discount tiers: the Lucenttheme Cart Progress config (minicart progress bar), with its enabled flags and
 *    start/end dates. The owner already edits and dates these for every sale, so email = minicart.
 * Read straight from config (same rules as Lucenttheme\CartProgress\Model\TierProvider) so the plugin
 * doesn't depend on that theme module.
 *
 * Fields (all strings, '' when there is nothing true to say; templates fall back to number-free copy):
 *  profile: ladder_line ("Orders over ₹799 ship free. Cross ₹2,999 and you get 5% off, …"), free_ship_from_display
 *  cart:    last_cart_discount(_display) — what Magento really takes off this cart (ladder, sale, Pebeo, coupon);
 *           last_cart_ships_free yes/no; last_cart_next_gap_display + last_cart_next_label (next step up);
 *           last_cart_tier_pct (bar fill, each step owns an equal share, like the minicart);
 *           ladder_tick_1..6 + ladder_tick_width (the goal labels under the bar).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Pricing;

use ArtLounge\BentoCore\Model\Money;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Ladder
{
    public const FREE_SHIPPING = 'free shipping';
    public const TICK_SLOTS = 6; // free shipping + the 5 Cart Progress tiers
    private const TIERS = ['tier1', 'tier2', 'tier3', 'tier4', 'tier5'];
    private const CP = 'lucenttheme_cart_progress';

    public function __construct(private readonly ScopeConfigInterface $scope)
    {
    }

    /** @return list<array{at:float,label:string}> ascending; label FREE_SHIPPING or "7.5% off" */
    public function steps(int $storeId, ?string $today = null): array
    {
        $get = fn (string $path) => $this->scope->getValue($path, ScopeInterface::SCOPE_STORE, $storeId ?: null);
        $freeShip = $get('carriers/freeshipping/active') ? (float)$get('carriers/freeshipping/free_shipping_subtotal') : 0.0;
        $tiers = [];
        if ($get(self::CP . '/general/enabled')) {
            $today ??= (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            foreach (self::TIERS as $id) {
                $start = (string)$get(self::CP . "/$id/start_date");
                $end = (string)$get(self::CP . "/$id/end_date");
                if (!$get(self::CP . "/$id/enabled") || ($start !== '' && $start > $today) || ($end !== '' && $end < $today)) {
                    continue;
                }
                $tiers[] = ['threshold' => (float)$get(self::CP . "/$id/threshold"), 'label' => (string)$get(self::CP . "/$id/label")];
            }
        }

        return self::stepsFrom($tiers, $freeShip);
    }

    /** Profile fields: on every profile push (orders, nightly refresh, backfill) and every cart snapshot. */
    public function profileFields(int $storeId): array
    {
        $steps = $this->steps($storeId);
        $free = array_values(array_filter($steps, static fn ($s) => $s['label'] === self::FREE_SHIPPING));

        return [
            'ladder_line' => self::line($steps),
            'free_ship_from_display' => $free ? Money::displayWhole((int)ceil($free[0]['at'])) : '',
        ];
    }

    public function cartFields(int $storeId, float $subtotal, float $discount): array
    {
        $steps = $this->steps($storeId);

        return self::cart($steps, $subtotal, $discount) + self::ticks($steps) + $this->profileFields($storeId);
    }

    /**
     * Labels under the tier bar, one per step ("₹799 · Free shipping", "₹2,999 · 5%"), so the customer sees the goals.
     * Always TICK_SLOTS slots ('' unused, like the cart item slots); ladder_tick_width = each cell's % width.
     */
    public static function ticks(array $steps): array
    {
        $out = ['ladder_tick_width' => $steps ? (string)intdiv(100, count($steps)) : ''];
        for ($n = 1; $n <= self::TICK_SLOTS; $n++) {
            $s = $steps[$n - 1] ?? null;
            $out['ladder_tick_' . $n] = $s === null ? '' : Money::displayWhole((int)ceil($s['at'])) . ' · '
                . ($s['label'] === self::FREE_SHIPPING ? 'Free shipping' : substr($s['label'], 0, -4));
        }

        return $out;
    }

    /**
     * Tiers whose label names a percentage become discount steps ("10% Off!" → "10% off").
     * ponytail: other labels (the "Free Shipping" tier, a gift) are skipped; free shipping comes from the carrier.
     *
     * @param list<array{threshold:float,label:string}> $tiers
     */
    public static function stepsFrom(array $tiers, float $freeShip): array
    {
        $steps = $freeShip > 0 ? [['at' => $freeShip, 'label' => self::FREE_SHIPPING]] : [];
        foreach ($tiers as $t) {
            if ($t['threshold'] > 0 && preg_match('/(\d+(?:\.\d+)?)\s*%/', $t['label'], $m)) {
                $steps[] = ['at' => (float)$t['threshold'], 'label' => $m[1] . '% off'];
            }
        }
        usort($steps, static fn ($a, $b) => $a['at'] <=> $b['at']);

        return $steps;
    }

    /** "Orders over ₹799 ship free. Cross ₹2,999 and you get 5% off, ₹4,999 makes it 7.5%, and ₹9,999 makes it 10%." */
    public static function line(array $steps): string
    {
        $out = [];
        $tiers = [];
        foreach ($steps as $s) {
            $at = Money::displayWhole((int)ceil($s['at']));
            if ($s['label'] === self::FREE_SHIPPING) {
                $out[] = "Orders over $at ship free.";
            } else {
                $tiers[] = [$at, $s['label']];
            }
        }
        if ($tiers) {
            $parts = ["Cross {$tiers[0][0]} and you get {$tiers[0][1]}"];
            $n = count($tiers);
            for ($i = 1; $i < $n; $i++) {
                $parts[] = ($i === $n - 1 ? 'and ' : '') . $tiers[$i][0] . ' makes it ' . substr($tiers[$i][1], 0, -4);
            }
            $out[] = implode(', ', $parts) . '.';
        }

        return implode(' ', $out);
    }

    public static function cart(array $steps, float $subtotal, float $discount): array
    {
        $shipsFree = false;
        $next = null;
        $pct = '';
        $lo = 0.0;
        $n = count($steps);
        foreach ($steps as $i => $s) {
            if ($subtotal >= $s['at']) {
                $shipsFree = $shipsFree || $s['label'] === self::FREE_SHIPPING;
                $lo = $s['at'];
                continue;
            }
            $next ??= $s;
            if ($pct === '') {
                $pct = (int)max(4, floor(($i + ($subtotal - $lo) / ($s['at'] - $lo)) * 100 / $n));
            }
        }
        if ($n && $pct === '') {
            $pct = 100;
        }
        $off = (int)round($discount);

        return [
            'last_cart_discount' => Money::paise($off >= 1 ? $discount : 0),
            'last_cart_discount_display' => $off >= 1 ? Money::displayWhole($off) : '',
            'last_cart_ships_free' => $shipsFree ? 'yes' : 'no',
            'last_cart_next_gap_display' => $next ? Money::displayWhole(max(1, (int)ceil($next['at'] - $subtotal))) : '',
            'last_cart_next_label' => $next['label'] ?? '',
            'last_cart_tier_pct' => $pct === '' ? '' : (string)$pct,
        ];
    }
}
