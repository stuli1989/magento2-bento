<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Picks;

use ArtLounge\BentoEvents\Model\Picks\NextPicks;
use PHPUnit\Framework\TestCase;

class NextPicksTest extends TestCase
{
    private const RANKED = [
        'oil' => [11, 12, 13, 14],
        'any' => [90, 91, 92, 93],
    ];

    public function testTopThreeOfTheMediumByRank(): void
    {
        $this->assertSame([11, 12, 13], NextPicks::choose('oil', self::RANKED, [], []));
    }

    public function testAlreadyBoughtIsSkipped(): void
    {
        $this->assertSame([12, 14, 90], NextPicks::choose('oil', self::RANKED, [], [11, 13]));
    }

    public function testEligiblePinsGoFirstInAdminOrder(): void
    {
        $this->assertSame([14, 12, 11], NextPicks::choose('oil', self::RANKED, ['oil' => [14, 12]], []));
    }

    public function testPinThatIsNotEligibleOrAlreadyBoughtIsIgnored(): void
    {
        // 99: out of stock / not in the add-on categories (absent from ranked); 13: bought
        $this->assertSame([11, 12, 14], NextPicks::choose('oil', self::RANKED, ['oil' => [99, 13]], [13]));
    }

    public function testShortMediumIsFilledFromAnyWithoutDuplicates(): void
    {
        $ranked = ['ink' => [5, 90], 'any' => [90, 91, 92]];
        $this->assertSame([5, 90, 91], NextPicks::choose('ink', $ranked, [], []));
    }

    public function testBlankOrUnmappedMediumUsesAnyAndItsPins(): void
    {
        $pinned = ['any' => [92]];
        $this->assertSame([92, 90, 91], NextPicks::choose(null, self::RANKED, $pinned, []));
        $this->assertSame([92, 90, 91], NextPicks::choose('', self::RANKED, $pinned, []));
        $this->assertSame([92, 90, 91], NextPicks::choose('gouache', self::RANKED, $pinned, []));
    }

    public function testFewerThanThreeLeavesTheRestBlank(): void
    {
        $ids = NextPicks::choose('ink', ['ink' => [5], 'any' => [6]], [], [6]);
        $this->assertSame([5], $ids);
        $f = NextPicks::fields($ids, [5 => ['name' => 'Nib', 'url' => 'https://x/nib', 'image' => 'https://x/nib.jpg']]);
        $this->assertSame('Nib', $f['next_pick_1_name']);
        foreach ([2, 3] as $n) {
            foreach (['name', 'url', 'image_url'] as $k) {
                $this->assertSame(' ', $f["next_pick_{$n}_{$k}"]);
            }
        }
    }

    public function testFieldNamesAreExact(): void
    {
        $this->assertSame([
            'next_pick_1_name', 'next_pick_1_url', 'next_pick_1_image_url',
            'next_pick_2_name', 'next_pick_2_url', 'next_pick_2_image_url',
            'next_pick_3_name', 'next_pick_3_url', 'next_pick_3_image_url',
        ], array_keys(NextPicks::fields([], [])));
    }

    public function testFieldsCarryNameUrlAndImageInPickOrder(): void
    {
        $products = [
            7 => ['name' => 'Liquin', 'url' => 'https://s/liquin', 'image' => 'https://m/l.jpg'],
            3 => ['name' => 'Gamvar', 'url' => 'https://s/gamvar', 'image' => null],
        ];
        $f = NextPicks::fields([7, 3], $products);
        $this->assertSame(['Liquin', 'https://s/liquin', 'https://m/l.jpg'], [$f['next_pick_1_name'], $f['next_pick_1_url'], $f['next_pick_1_image_url']]);
        $this->assertSame(['Gamvar', 'https://s/gamvar', ' '], [$f['next_pick_2_name'], $f['next_pick_2_url'], $f['next_pick_2_image_url']]);
    }

    public function testConfigurableIsShownAsItsBestSellingInStockVariant(): void
    {
        // 4631 = W&N Oil Colour Mediums: 4636 Liquin 75 ml sells most but is out of stock, 4644 disabled
        $children = [4631 => [4636, 4639, 4643, 4644], 500 => [501, 502], 600 => [601]];
        $enabled = array_flip([4636, 4639, 4643, 501, 502, 601]);
        $inStock = array_flip([4639, 4643, 4644, 501, 502]);
        $units = [4636 => 40.0, 4643 => 6.0, 4639 => 2.0, 4644 => 99.0];
        $this->assertSame(
            [4631 => 4643, 500 => 501], // 500: no sales, lowest id; 600: nothing sellable, no variant
            NextPicks::variants([4631, 500, 600, 7], $children, $enabled, $inStock, $units)
        );
    }

    public function testDisplayUsesVariantNameAndImageWithTheParentUrl(): void
    {
        $products = [
            4631 => ['name' => 'W&N Oil Colour Mediums', 'url' => 'https://s/oil-mediums', 'image' => 'https://m/parent.jpg'],
            4643 => ['name' => 'Liquin Fine Detail 500 ML', 'url' => 'https://s/catalog/product/view/id/4643', 'image' => 'https://m/liquin.jpg'],
            500 => ['name' => 'Palette', 'url' => 'https://s/palette', 'image' => 'https://m/palette.jpg'],
            501 => ['name' => 'Palette - Small', 'url' => null, 'image' => null],
            7 => ['name' => 'Eraser', 'url' => 'https://s/eraser', 'image' => null],
        ];
        $d = NextPicks::display([4631, 500, 7], [4631 => 4643, 500 => 501], $products);
        $this->assertSame(['name' => 'Liquin Fine Detail 500 ML', 'url' => 'https://s/oil-mediums', 'image' => 'https://m/liquin.jpg'], $d[4631]);
        $this->assertSame(['name' => 'Palette - Small', 'url' => 'https://s/palette', 'image' => 'https://m/palette.jpg'], $d[500], 'variant without image → parent image');
        $this->assertSame(['name' => 'Eraser', 'url' => 'https://s/eraser', 'image' => null], $d[7]);
        $this->assertSame('Liquin Fine Detail 500 ML', NextPicks::fields([4631], $d)['next_pick_1_name']);
    }

    // ---------- new_in_* ----------

    public function testNewArrivalWindowCountsOnlyParentsThatAreNewThemselves(): void
    {
        $createdAt = [
            1 => '2026-09-01 00:00:00',  // new simple
            2 => '2026-01-01 00:00:00',  // old simple
            30 => '2025-05-01 00:00:00', // old configurable …
            31 => '2026-10-01 00:00:00', // … with a new variant: not a new arrival
            40 => '2026-08-01 00:00:00', // new configurable
            41 => '2026-08-01 00:00:00',
            5 => '2026-06-25 00:00:00',  // exactly on the window start
        ];
        $parents = NextPicks::newArrivals($createdAt, [31 => 30, 41 => 40], '2026-06-25 00:00:00');
        sort($parents);
        $this->assertSame([1, 5, 40], $parents);
    }

    public function testNewArrivalsAreNewestFirst(): void
    {
        $this->assertSame([3, 9, 7, 1], NextPicks::newestFirst(
            [1, 7, 3, 9],
            [1 => '2026-07-01 00:00:00', 7 => '2026-09-01 00:00:00', 3 => '2026-10-20 00:00:00', 9 => '2026-09-01 00:00:00']
        ), 'tie on created_at: higher id first');
    }

    public function testNewInSkipsBoughtAndCurrentPicksAndDoesNotFillFromAny(): void
    {
        $newest = ['oil' => [50, 51, 52, 53], 'any' => [60, 50, 51]];
        $picks = [52];
        $this->assertSame([51, 53], NextPicks::choose('oil', $newest, [], array_merge([50], $picks), false));
    }

    public function testNewInNeverRepeatsTheCustomersCurrentNextPicks(): void
    {
        // 11 is both a top seller (pick) and new; 12 is new; 13 bought
        [$picks, $newIn] = NextPicks::picksAndNewIn('oil', [13], ['oil' => [11, 14, 15]], [], ['oil' => [11, 13, 12, 16]]);
        $this->assertSame([11, 14, 15], $picks);
        $this->assertSame([12, 16], $newIn);
    }

    public function testNewInOnlyShowsProductsAddedAfterTheLastOrder(): void
    {
        $newest = ['oil' => [53, 52, 51, 50], 'any' => [60, 53, 52, 51, 50]];
        $createdAt = [53 => '2026-10-20 00:00:00', 52 => '2026-09-15 10:00:01', 51 => '2026-09-15 10:00:00', 50 => '2026-07-01 00:00:00', 60 => '2026-10-21 00:00:00'];
        // last order 2026-09-15 10:00:00: 51 was added in the same second → already there when they shopped
        [, $newIn] = NextPicks::picksAndNewIn('oil', [], [], [], $newest, '2026-09-15 10:00:00', $createdAt);
        $this->assertSame([53, 52], $newIn);
        [, $blank] = NextPicks::picksAndNewIn(null, [], [], [], $newest, '2026-10-01 00:00:00', $createdAt);
        $this->assertSame([60, 53], $blank, 'blank medium: newest across all mediums, same cutoff');
    }

    public function testNewInWithoutOrdersUsesTheWholeWindow(): void
    {
        $newest = ['oil' => [53, 50]];
        $createdAt = [53 => '2026-10-20 00:00:00', 50 => '2026-07-01 00:00:00'];
        $this->assertSame([53, 50], NextPicks::picksAndNewIn('oil', [], [], [], $newest, null, $createdAt)[1]);
        $this->assertSame([53, 50], NextPicks::picksAndNewIn('oil', [], [], [], $newest, '', $createdAt)[1]);
    }

    public function testLastOrderCutoffDoesNotAffectNextPicks(): void
    {
        [$picks] = NextPicks::picksAndNewIn('oil', [], ['oil' => [1, 2, 3]], [], [], '2026-10-24 00:00:00', []);
        $this->assertSame([1, 2, 3], $picks);
    }

    public function testNewInWithBlankMediumUsesNewestAcrossAllMediums(): void
    {
        $newest = ['oil' => [50], 'any' => [60, 50, 51, 52]];
        $this->assertSame([60, 50, 51], NextPicks::choose(null, $newest, [], [], false));
        $this->assertSame([], NextPicks::choose('pastel', $newest, [], [], false), 'mapped medium with no arrivals stays blank');
    }

    // ---------- usual_* ----------

    private function line(int $order, string $at, int $parent, ?int $product = null): array
    {
        return ['order_id' => $order, 'created_at' => $at, 'parent_id' => $parent, 'product_id' => $product ?? $parent];
    }

    public function testUsualsCountOrdersNotUnitsAndNeedTwoOrders(): void
    {
        $lines = [
            // 10: one bulk order (lines repeat in one order) — not a habit
            $this->line(1, '2026-01-01', 10), $this->line(1, '2026-01-01', 10), $this->line(1, '2026-01-01', 10),
            // 20: 3 orders; 30: 2 orders, latest purchase most recent; 40: 2 orders, older
            $this->line(2, '2026-02-01', 20), $this->line(3, '2026-03-01', 20), $this->line(4, '2026-04-01', 20),
            $this->line(5, '2026-05-01', 40), $this->line(6, '2026-06-01', 40),
            $this->line(7, '2026-07-01', 30), $this->line(8, '2026-09-01', 30),
            $this->line(9, '2026-09-02', 50), $this->line(10, '2026-09-03', 50),
        ];
        $eligible = array_flip([10, 20, 30, 40, 50]);
        $this->assertSame([20 => 20, 50 => 50, 30 => 30], NextPicks::usuals($lines, $eligible, [], []),
            'orders desc, then most recent purchase; 40 is 4th');
        // a 5-unit bulk line in one order still isn't a usual, even when the list is short
        $this->assertSame([20 => 20], NextPicks::usuals(
            [$this->line(1, '2026-01-01', 10), $this->line(2, '2026-02-01', 20), $this->line(3, '2026-03-01', 20)],
            $eligible, [], []
        ));
    }

    public function testOutOfStockUsualIsDropped(): void
    {
        $lines = [$this->line(1, '2026-01-01', 20), $this->line(2, '2026-02-01', 20), $this->line(3, '2026-03-01', 30), $this->line(4, '2026-04-01', 30)];
        $this->assertSame([30 => 30], NextPicks::usuals($lines, array_flip([30]), [], []));
    }

    public function testUsualConfigurableShowsTheCustomersFavouriteVariantWhenSellable(): void
    {
        // parent 4631 bought in 3 orders: variant 4643 twice, 4639 once (configurable line + its variant line per order)
        $lines = [];
        foreach ([[1, 4643], [2, 4643], [3, 4639]] as [$order, $variant]) {
            $lines[] = $this->line($order, "2026-0$order-01", 4631);
            $lines[] = $this->line($order, "2026-0$order-01", 4631, $variant);
        }
        $eligible = array_flip([4631]);
        $this->assertSame([4631 => 4643], NextPicks::usuals($lines, $eligible, [4643 => true, 4639 => true], [4631 => 4639]));
        // favourite out of stock → best-selling sellable variant
        $this->assertSame([4631 => 4644], NextPicks::usuals($lines, $eligible, [4639 => true, 4644 => true], [4631 => 4644]));
    }

    public function testListFieldNamesAreExact(): void
    {
        foreach (['new_in_', 'usual_'] as $prefix) {
            $f = NextPicks::fields([1], [1 => ['name' => 'A', 'url' => 'u', 'image' => 'i']], $prefix);
            $this->assertSame([
                "{$prefix}1_name", "{$prefix}1_url", "{$prefix}1_image_url",
                "{$prefix}2_name", "{$prefix}2_url", "{$prefix}2_image_url",
                "{$prefix}3_name", "{$prefix}3_url", "{$prefix}3_image_url",
            ], array_keys($f));
            $this->assertSame(['A', ' ', ' '], [$f["{$prefix}1_name"], $f["{$prefix}2_name"], $f["{$prefix}3_name"]], 'blanks when fewer than 3');
        }
    }

    public function testChildSalesRollUpToTheConfigurableParent(): void
    {
        // 101, 102 are variants of 100; 200 is a plain simple; 100 also sold as a parent line
        $units = NextPicks::rollUp([101 => 4.0, 102 => 3.0, 100 => 1.0, 200 => 5.0], [101 => 100, 102 => 100]);
        $this->assertSame([100 => 8.0, 200 => 5.0], $units);
    }

    public function testRankByUnitsThenIdWithUnsoldLast(): void
    {
        $this->assertSame([100, 200, 3, 7], NextPicks::rank([7, 200, 3, 100, 3], [100 => 8.0, 200 => 5.0]));
    }

    public function testEligibleNeedsEnabledVisibleAndStock(): void
    {
        $parents = [1, 2, 3, 4, 5, 6];
        $types = [1 => 'simple', 2 => 'simple', 3 => 'simple', 4 => 'configurable', 5 => 'configurable', 6 => 'configurable'];
        $children = [4 => [41, 42], 5 => [51], 6 => [61]];
        $enabled = array_flip([1, 2, 4, 41, 42, 5, 51, 6]); // 3 disabled, 61 disabled
        $visible = array_flip([1, 3, 4, 5, 6]);             // 2 not visible
        $inStock = array_flip([1, 2, 3, 42, 61]);           // 41, 51 below minimum stock; 61 in stock but disabled
        $this->assertSame([1, 4], NextPicks::eligible($parents, $types, $children, $enabled, $visible, $inStock));
    }
}
