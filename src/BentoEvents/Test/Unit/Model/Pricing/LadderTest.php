<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Pricing;

use ArtLounge\BentoEvents\Model\Pricing\Ladder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class LadderTest extends TestCase
{
    public const LIVE_LINE = 'Orders over ₹799 ship free. Cross ₹2,999 and you get 5% off, ₹4,999 makes it 7.5%, and ₹9,999 makes it 10%.';

    /** The live config of 2026-10-21 (RESTORE-21-OCT.md): free shipping tier, tier 2 off, 5/7.5/10 %. */
    public static function liveConfig(TestCase $t, array $override = []): ScopeConfigInterface
    {
        $values = array_replace([
            'carriers/freeshipping/active' => '1',
            'carriers/freeshipping/free_shipping_subtotal' => '799',
            'lucenttheme_cart_progress/general/enabled' => '1',
            'lucenttheme_cart_progress/tier1/enabled' => '1', 'lucenttheme_cart_progress/tier1/threshold' => '799', 'lucenttheme_cart_progress/tier1/label' => 'Free Shipping',
            'lucenttheme_cart_progress/tier2/enabled' => '0', 'lucenttheme_cart_progress/tier2/threshold' => '999', 'lucenttheme_cart_progress/tier2/label' => '2.5% Off!',
            'lucenttheme_cart_progress/tier3/enabled' => '1', 'lucenttheme_cart_progress/tier3/threshold' => '2999', 'lucenttheme_cart_progress/tier3/label' => '5% Off!',
            'lucenttheme_cart_progress/tier4/enabled' => '1', 'lucenttheme_cart_progress/tier4/threshold' => '4999', 'lucenttheme_cart_progress/tier4/label' => '7.5% Off!',
            'lucenttheme_cart_progress/tier5/enabled' => '1', 'lucenttheme_cart_progress/tier5/threshold' => '9999', 'lucenttheme_cart_progress/tier5/label' => '10% Off!',
        ], $override);
        $scope = $t->getMockBuilder(ScopeConfigInterface::class)->getMock();
        $scope->method('getValue')->willReturnCallback(static fn ($path) => $values[$path] ?? null);

        return $scope;
    }

    private function ladder(array $override = []): Ladder
    {
        return new Ladder(self::liveConfig($this, $override));
    }

    public function testLiveLadderLine(): void
    {
        $f = $this->ladder()->profileFields(1);
        $this->assertSame(self::LIVE_LINE, $f['ladder_line']);
        $this->assertSame('₹799', $f['free_ship_from_display']);
    }

    public function testDussehraStyleSaleLadderFollowsTheMinicart(): void
    {
        // Sale config: tiers 1, 3, 4 end-dated, tier 2 = 20 % @ 1,000, tier 5 = 25 % @ 3,000 (17–20 Oct).
        $l = $this->ladder([
            'lucenttheme_cart_progress/tier1/end_date' => '2026-10-16',
            'lucenttheme_cart_progress/tier2/enabled' => '1', 'lucenttheme_cart_progress/tier2/threshold' => '1000', 'lucenttheme_cart_progress/tier2/label' => '20% Off!',
            'lucenttheme_cart_progress/tier3/end_date' => '2026-10-16', 'lucenttheme_cart_progress/tier4/end_date' => '2026-10-16',
            'lucenttheme_cart_progress/tier5/threshold' => '3000', 'lucenttheme_cart_progress/tier5/label' => '25% Off!',
            'lucenttheme_cart_progress/tier5/start_date' => '2026-10-17', 'lucenttheme_cart_progress/tier5/end_date' => '2026-10-20',
        ]);
        $this->assertSame(
            'Orders over ₹799 ship free. Cross ₹1,000 and you get 20% off, and ₹3,000 makes it 25%.',
            Ladder::line($l->steps(1, '2026-10-18'))
        );
        $this->assertSame(self::LIVE_LINE, Ladder::line($this->ladder()->steps(1, '2026-10-18')));
    }

    public function testNothingTrueToSayMeansBlank(): void
    {
        $l = $this->ladder(['lucenttheme_cart_progress/general/enabled' => '0', 'carriers/freeshipping/active' => '0']);
        $this->assertSame(['ladder_line' => '', 'free_ship_from_display' => ''], $l->profileFields(1));
        $c = $l->cartFields(1, 1200.0, 0.0);
        $this->assertSame('', $c['last_cart_tier_pct']);
        $this->assertSame('', $c['last_cart_next_gap_display']);
        $this->assertSame('no', $c['last_cart_ships_free']);
    }

    /** @dataProvider carts */
    public function testCartFields(float $subtotal, float $discount, string $free, string $gap, string $label, string $pct, string $off): void
    {
        $c = Ladder::cart($this->ladder()->steps(1), $subtotal, $discount);
        $this->assertSame([$free, $gap, $label, $pct, $off], [
            $c['last_cart_ships_free'], $c['last_cart_next_gap_display'], $c['last_cart_next_label'],
            $c['last_cart_tier_pct'], $c['last_cart_discount_display'],
        ]);
    }

    public static function carts(): array
    {
        return [
            'tiny cart' => [100.0, 0.0, 'no', '₹699', Ladder::FREE_SHIPPING, '4', ''],
            'just under free shipping' => [798.5, 0.0, 'no', '₹1', Ladder::FREE_SHIPPING, '24', ''],
            'at free shipping' => [799.0, 0.0, 'yes', '₹2,200', '5% off', '25', ''],
            'tier 1, below-MRP items get nothing' => [3450.0, 0.0, 'yes', '₹1,549', '7.5% off', '55', ''],
            'tier 2' => [5000.0, 375.0, 'yes', '₹4,999', '10% off', '75', '₹375'],
            'top' => [12000.0, 1200.0, 'yes', '', '', '100', '₹1,200'],
        ];
    }
}
