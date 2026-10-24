<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Picks;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\SendResult;
use ArtLounge\BentoCore\Model\TestMode;
use ArtLounge\BentoEvents\Cron\RefreshNextPicks;
use ArtLounge\BentoEvents\Model\Picks\Refresh;
use ArtLounge\BentoEvents\Model\Profile\CategoryTree;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;
use ArtLounge\BentoEvents\Model\Profile\ProfileLoader;
use ArtLounge\BentoEvents\Model\Profile\StatsCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RefreshTest extends TestCase
{
    private array $recent = ['a@x.com', 'b+bentotest@x.com', 'c@x.com', 'd+bentotest1@x.com', 'e@x.com'];
    /** every buyer (cursor order) => their orders */
    private array $history = [];
    private array $imports = [];
    private array $results = [];
    private ?string $since = null;
    private TestMode $testMode;
    private ConfigInterface $config;

    protected function setUp(): void
    {
        $this->testMode = new TestMode([]);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getTestMode')->willReturnCallback(fn () => $this->testMode);
        $this->config->method('getVipLifetimeSpend')->willReturn(15000.0);
        $this->config->method('getVipOrderCount')->willReturn(5);
        $order = static fn (float $total, string $state = 'complete') => [
            'increment_id' => '1', 'state' => $state, 'created_at' => '2025-01-01 00:00:00',
            'grand_total' => $total, 'total_refunded' => 0.0, 'purchased_at' => null,
        ];
        $this->history = [
            'a@x.com' => [$order(20000.0)],                              // recent AND vip (spend)
            'old-big@x.com' => [$order(15000.0)],                        // vip: spend exactly at threshold
            'old-loyal@x.com' => array_fill(0, 5, $order(100.0)),        // vip: 5 orders
            'old-small@x.com' => array_fill(0, 4, $order(1000.0)),       // 4 orders, ₹4,000: not vip
            'old-canceled@x.com' => array_fill(0, 6, $order(9000.0, 'canceled')), // canceled orders don't count
        ];
    }

    private function refresh(): Refresh
    {
        $loader = $this->createMock(ProfileLoader::class);
        $loader->method('buyerEmailsSince')->willReturnCallback(function (string $since) {
            $this->since = $since;
            return $this->recent;
        });
        $loader->method('buyerEmailsAfter')->willReturnCallback(function (string $after, int $limit) {
            $all = array_keys($this->history);
            sort($all);
            return array_slice(array_values(array_filter($all, fn ($e) => strcmp($e, $after) > 0)), 0, $limit);
        });
        $loader->method('orders')->willReturnCallback(fn (array $emails) => array_intersect_key($this->history, array_flip($emails)));
        $loader->method('categoryTree')->willReturn(new CategoryTree([], []));
        $provider = $this->createMock(ProfileFieldsProvider::class);
        $provider->method('subscriberRecords')->willReturnCallback(function (array $emails) {
            $out = [];
            foreach ($emails as $e) {
                $out[$e] = ['email' => $e, 'next_pick_1_name' => 'Liquin'];
            }
            return $out;
        });
        $client = $this->createMock(BentoClientInterface::class);
        $client->method('importSubscribers')->willReturnCallback(function (array $subs) {
            $this->imports[] = array_column($subs, 'email');
            return array_shift($this->results) ?? new SendResult(200, '{}', null);
        });

        return new Refresh($loader, $provider, new StatsCalculator(), $client, $this->config);
    }

    public function testRecentBuyersAreSentInBatches(): void
    {
        $report = $this->refresh()->run(null, true, false, 30, false, 2);
        $this->assertSame([['a@x.com', 'b+bentotest@x.com'], ['c@x.com', 'd+bentotest1@x.com'], ['e@x.com']], $this->imports);
        $this->assertSame(['recent' => 5, 'vips' => 0, 'audience' => 5, 'sent' => 5, 'batches' => 3, 'failed_batches' => 0],
            array_intersect_key($report, array_flip(['recent', 'vips', 'audience', 'sent', 'batches', 'failed_batches'])));
        $this->assertEqualsWithDelta(time() - 30 * 86400, strtotime($this->since . ' UTC'), 5, 'window = last 30 days');
    }

    public function testVipsAreSpendOrOrderCountFromStatsCalculator(): void
    {
        $this->assertSame(['a@x.com', 'old-big@x.com', 'old-loyal@x.com'], $this->refresh()->vipEmails());
    }

    public function testVipScanPagesThroughEveryBuyer(): void
    {
        $order = ['increment_id' => '1', 'state' => 'complete', 'created_at' => '2025-01-01 00:00:00',
            'grand_total' => 20000.0, 'total_refunded' => 0.0, 'purchased_at' => null];
        $this->history = [];
        foreach (range(1, 1001) as $i) {
            $this->history[sprintf('v%04d@x.com', $i)] = [$order];
        }
        $this->assertCount(1001, $this->refresh()->vipEmails());
    }

    public function testCronAudienceIsRecentPlusVipsDeduplicated(): void
    {
        $report = $this->refresh()->run(null, true, true, 30);
        $sent = array_merge(...$this->imports);
        $this->assertSame(['recent' => 5, 'vips' => 3, 'audience' => 7], array_intersect_key($report, array_flip(['recent', 'vips', 'audience'])));
        $this->assertCount(7, $sent);
        $this->assertSame($sent, array_values(array_unique($sent)), 'a@x.com is recent and VIP but sent once');
        $this->assertContains('old-loyal@x.com', $sent);
        $this->assertNotContains('old-small@x.com', $sent);
    }

    public function testVipsOnly(): void
    {
        $this->refresh()->run(null, false, true, 30);
        $this->assertSame([['a@x.com', 'old-big@x.com', 'old-loyal@x.com']], $this->imports);
        $this->assertNull($this->since, 'recent buyers are not loaded');
    }

    public function testBatchSizeIsCappedAtTheBentoMaximum(): void
    {
        $this->recent = array_map(static fn ($i) => "u$i@x.com", range(1, 1001));
        $this->refresh()->run(null, true, false, 30, false, 5000);
        $this->assertSame([1000, 1], array_map('count', $this->imports));
    }

    public function testFailedBatchIsReportedAndTheRestStillGo(): void
    {
        $this->results = [new SendResult(503, 'down', null)];
        $report = $this->refresh()->run(null, true, false, 30, false, 2);
        $this->assertCount(3, $this->imports);
        $this->assertSame(3, $report['sent']);
        $this->assertSame(1, $report['failed_batches']);
        $this->assertNotNull($report['error']);
    }

    public function testTestModeRestrictsRecipients(): void
    {
        $this->testMode = new TestMode(['*+bentotest*@*']);
        $report = $this->refresh()->run(null, true, true, 30);
        $this->assertSame([['b+bentotest@x.com', 'd+bentotest1@x.com']], $this->imports);
        $this->assertSame(5, $report['filtered_out']);
    }

    public function testOneEmailOnly(): void
    {
        $report = $this->refresh()->run(' C@X.com ', true, true, 30);
        $this->assertSame([['c@x.com']], $this->imports);
        $this->assertNull($this->since, 'no audience is loaded');
        $this->assertSame(0, $report['vips']);
    }

    public function testDryRunSendsNothingAndKeepsASample(): void
    {
        $report = $this->refresh()->run(null, true, false, 30, true, 2);
        $this->assertSame([], $this->imports);
        $this->assertCount(3, $report['sample']);
        $this->assertSame('Liquin', $report['sample'][0]['next_pick_1_name']);
    }

    public function testCronRefreshesRecentAndVipsAndLogsCountsWithoutEmails(): void
    {
        $refresh = $this->createMock(Refresh::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->config->method('isConfigured')->willReturnOnConsecutiveCalls(false, true);
        $refresh->expects($this->once())->method('run')->with(null, true, true, RefreshNextPicks::DAYS)
            ->willReturn(['recent' => 2, 'vips' => 1, 'sent' => 2, 'sample' => [['email' => 'a@x.com']]]);
        $logger->expects($this->once())->method('info')
            ->with('Bento product lists refreshed', ['recent' => 2, 'vips' => 1, 'sent' => 2]);

        $cron = new RefreshNextPicks($refresh, $this->config, $logger);
        $cron->execute(); // not configured: nothing
        $cron->execute();
    }
}
