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
                $this->assertSame('', $f["next_pick_{$n}_{$k}"]);
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
        $this->assertSame(['Gamvar', 'https://s/gamvar', ''], [$f['next_pick_2_name'], $f['next_pick_2_url'], $f['next_pick_2_image_url']]);
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
