<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Picks;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\SendResult;
use ArtLounge\BentoCore\Model\TestMode;
use ArtLounge\BentoEvents\Cron\RefreshNextPicks;
use ArtLounge\BentoEvents\Model\Picks\Refresh;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;
use ArtLounge\BentoEvents\Model\Profile\ProfileLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RefreshTest extends TestCase
{
    private array $buyers = ['a@x.com', 'b+bentotest@x.com', 'c@x.com', 'd+bentotest1@x.com', 'e@x.com'];
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
    }

    private function refresh(): Refresh
    {
        $loader = $this->createMock(ProfileLoader::class);
        $loader->method('buyerEmailsSince')->willReturnCallback(function (string $since) {
            $this->since = $since;
            return $this->buyers;
        });
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

        return new Refresh($loader, $provider, $client, $this->config);
    }

    public function testRecentBuyersAreSentInBatches(): void
    {
        $report = $this->refresh()->run(30, null, false, 2);
        $this->assertSame([['a@x.com', 'b+bentotest@x.com'], ['c@x.com', 'd+bentotest1@x.com'], ['e@x.com']], $this->imports);
        $this->assertSame(['eligible' => 5, 'sent' => 5, 'batches' => 3, 'failed_batches' => 0], array_intersect_key(
            $report,
            array_flip(['eligible', 'sent', 'batches', 'failed_batches'])
        ));
        $this->assertEqualsWithDelta(time() - 30 * 86400, strtotime($this->since . ' UTC'), 5, 'window = last 30 days');
    }

    public function testBatchSizeIsCappedAtTheBentoMaximum(): void
    {
        $this->buyers = array_map(static fn ($i) => "u$i@x.com", range(1, 1001));
        $this->refresh()->run(30, null, false, 5000);
        $this->assertSame([1000, 1], array_map('count', $this->imports));
    }

    public function testFailedBatchIsReportedAndTheRestStillGo(): void
    {
        $this->results = [new SendResult(503, 'down', null)];
        $report = $this->refresh()->run(30, null, false, 2);
        $this->assertCount(3, $this->imports);
        $this->assertSame(3, $report['sent']);
        $this->assertSame(1, $report['failed_batches']);
        $this->assertNotNull($report['error']);
    }

    public function testTestModeRestrictsRecipients(): void
    {
        $this->testMode = new TestMode(['*+bentotest*@*']);
        $report = $this->refresh()->run(30);
        $this->assertSame([['b+bentotest@x.com', 'd+bentotest1@x.com']], $this->imports);
        $this->assertSame(3, $report['filtered_out']);
    }

    public function testOneEmailOnly(): void
    {
        $this->refresh()->run(30, ' C@X.com ');
        $this->assertSame([['c@x.com']], $this->imports);
        $this->assertNull($this->since, 'recent buyers are not loaded');
    }

    public function testDryRunSendsNothingAndKeepsASample(): void
    {
        $report = $this->refresh()->run(30, null, true, 2);
        $this->assertSame([], $this->imports);
        $this->assertCount(3, $report['sample']);
        $this->assertSame('Liquin', $report['sample'][0]['next_pick_1_name']);
    }

    public function testCronSkipsWhenBentoIsNotConfiguredAndLogsCountsWithoutEmails(): void
    {
        $refresh = $this->createMock(Refresh::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->config->method('isConfigured')->willReturnOnConsecutiveCalls(false, true);
        $refresh->expects($this->once())->method('run')->with(RefreshNextPicks::DAYS)
            ->willReturn(['eligible' => 2, 'sent' => 2, 'sample' => [['email' => 'a@x.com']]]);
        $logger->expects($this->once())->method('info')
            ->with('Bento next picks refreshed', ['eligible' => 2, 'sent' => 2]);

        $cron = new RefreshNextPicks($refresh, $this->config, $logger);
        $cron->execute(); // not configured: nothing
        $cron->execute();
    }
}
