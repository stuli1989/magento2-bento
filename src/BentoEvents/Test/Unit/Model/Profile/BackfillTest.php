<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Profile;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\SendResult;
use ArtLounge\BentoCore\Model\TestMode;
use ArtLounge\BentoEvents\Model\Profile\Backfill;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;
use ArtLounge\BentoEvents\Model\Profile\ProfileLoader;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;

class BackfillTest extends TestCase
{
    private array $buyers = [];
    private array $imports = [];
    private ?SendResult $importResult = null;
    private TestMode $testMode;
    private FlagManager $flags;

    protected function setUp(): void
    {
        $this->testMode = new TestMode([]);
        $this->flags = new FlagManager();
        $this->buyers = ['a@x.com', 'b+bentotest@x.com', 'c@x.com', 'd+bentotest1@x.com', 'e@x.com'];
    }

    private function backfill(): Backfill
    {
        $loader = $this->createMock(ProfileLoader::class);
        $loader->method('buyerEmailsAfter')->willReturnCallback(function (string $after, int $limit) {
            $rest = array_values(array_filter($this->buyers, fn ($e) => strcmp($e, $after) > 0));
            return array_slice($rest, 0, $limit);
        });
        $provider = $this->createMock(ProfileFieldsProvider::class);
        $provider->method('subscriberRecords')->willReturnCallback(function (array $emails) {
            $out = [];
            foreach ($emails as $e) {
                $out[$e] = ['email' => $e, 'order_count' => 1];
            }
            return $out;
        });
        $client = $this->createMock(BentoClientInterface::class);
        $client->method('importSubscribers')->willReturnCallback(function (array $subs) {
            $this->imports[] = array_column($subs, 'email');
            return $this->importResult ?? new SendResult(200, '{}', null);
        });
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getTestMode')->willReturnCallback(fn () => $this->testMode);

        return new Backfill($loader, $provider, $client, $config, $this->flags);
    }

    public function testBatchesAndSendsEveryBuyer(): void
    {
        $report = $this->backfill()->run(null, false, 2, false);
        $this->assertSame([['a@x.com', 'b+bentotest@x.com'], ['c@x.com', 'd+bentotest1@x.com'], ['e@x.com']], $this->imports);
        $this->assertSame(5, $report['sent']);
        $this->assertSame(3, $report['batches']);
        $this->assertNull($this->flags->getFlagData(Backfill::CURSOR_FLAG), 'completed run clears the cursor');
    }

    public function testEmailPatternRestrictsRecipients(): void
    {
        $report = $this->backfill()->run('*+bentotest*@*', false, 1000, false);
        $this->assertSame([['b+bentotest@x.com', 'd+bentotest1@x.com']], $this->imports);
        $this->assertSame(2, $report['sent']);
        $this->assertSame(3, $report['filtered_out']);
    }

    public function testConfiguredTestModeAlsoRestricts(): void
    {
        $this->testMode = new TestMode(['d+*@*']);
        $this->backfill()->run(null, false, 1000, false);
        $this->assertSame([['d+bentotest1@x.com']], $this->imports);
    }

    public function testDryRunSendsNothingAndReportsSample(): void
    {
        $report = $this->backfill()->run(null, true, 2, false);
        $this->assertSame([], $this->imports);
        $this->assertSame(5, $report['eligible']);
        $this->assertCount(3, $report['sample']);
        $this->assertSame(0, $report['sent']);
    }

    public function testFailureStopsAndResumeContinuesFromCursor(): void
    {
        $bf = $this->backfill();
        $this->importResult = new SendResult(503, 'down', null);
        $first = $bf->run(null, false, 2, false);
        $this->assertSame(0, $first['sent']);
        $this->assertNotNull($first['error']);
        $this->assertCount(1, $this->imports);

        $this->importResult = null;
        $this->imports = [];
        // first batch succeeded? no — failure happened on batch 1, so resume starts from the beginning
        $bf->run(null, false, 2, true);
        $this->assertSame(['a@x.com', 'b+bentotest@x.com'], $this->imports[0]);
    }

    public function testResumeSkipsCompletedBatches(): void
    {
        $this->flags->saveFlag(Backfill::CURSOR_FLAG, 'b+bentotest@x.com');
        $this->backfill()->run(null, false, 2, true);
        $this->assertSame([['c@x.com', 'd+bentotest1@x.com'], ['e@x.com']], $this->imports);
    }
}
