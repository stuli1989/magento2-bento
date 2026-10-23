<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Profile;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Picks\NextPicks;
use ArtLounge\BentoEvents\Model\Picks\NextPicksProvider;
use ArtLounge\BentoEvents\Model\Profile\CategoryTree;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;
use ArtLounge\BentoEvents\Model\Profile\ProfileLoader;
use ArtLounge\BentoEvents\Model\Profile\StatsCalculator;
use PHPUnit\Framework\TestCase;

class ProfileFieldsProviderTest extends TestCase
{
    private array $mediumsAsked = [];

    private function provider(array $picks): ProfileFieldsProvider
    {
        $loader = $this->createMock(ProfileLoader::class);
        $loader->method('orders')->willReturn([]);
        $loader->method('items')->willReturn([
            'oil@x.com' => [['category_ids' => [93], 'spend' => 500.0]],
        ]);
        $loader->method('accounts')->willReturn([]);
        $loader->method('contactDetails')->willReturn([]);
        $loader->method('categoryTree')->willReturn(new CategoryTree([93 => '1/2/84/93'], [93 => 'Oil']));
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getCategoryMediumMap')->willReturn([93 => 'oil']);
        $nextPicks = $this->createMock(NextPicksProvider::class);
        $nextPicks->method('fieldsFor')->willReturnCallback(function (array $mediums) use ($picks) {
            $this->mediumsAsked = $mediums;
            return $picks;
        });

        return new ProfileFieldsProvider($loader, new StatsCalculator(), $config, $nextPicks);
    }

    public function testPickFieldsAreAddedPerCustomerFromTheirPrimaryMedium(): void
    {
        $fields = NextPicks::fields([1], [1 => ['name' => 'Liquin', 'url' => 'https://s/l', 'image' => 'https://m/l.jpg']]);
        $records = $this->provider(['oil@x.com' => $fields])->subscriberRecords(['OIL@x.com', 'new@x.com']);

        $this->assertSame(['oil@x.com' => 'oil', 'new@x.com' => null], $this->mediumsAsked);
        $this->assertSame('Liquin', $records['oil@x.com']['next_pick_1_name']);
        $this->assertSame('', $records['oil@x.com']['next_pick_3_url'], 'blank slots are sent so they clear older picks');
        $this->assertArrayNotHasKey('next_pick_1_name', $records['new@x.com']);
    }

    public function testFailedPicksSendNoPickFieldsAtAll(): void
    {
        $record = $this->provider([])->subscriberRecord('oil@x.com');
        $this->assertSame('oil', $record['primary_medium']);
        $this->assertSame([], preg_grep('/^next_pick_/', array_keys($record)));
    }
}
