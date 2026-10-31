<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Abandonment;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Abandonment\AbandonmentQueriesInterface;
use ArtLounge\BentoEvents\Model\Abandonment\ContactTimeoutProcessor;
use ArtLounge\BentoEvents\Model\Abandonment\IdleCartProcessor;
use ArtLounge\BentoEvents\Model\Abandonment\RecoveredCartProcessor;
use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Test\Unit\Fake\InMemoryOutboxStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AbandonmentTest extends TestCase
{
    private InMemoryOutboxStore $outbox;
    private Enqueuer $enqueuer;
    private array $paidQuotes = [];
    private array $idleCarts = [];
    private array $contactRows = [];
    private array $statuses = [];
    private AbandonmentQueriesInterface $queries;
    private ConfigInterface $config;

    protected function setUp(): void
    {
        $this->outbox = new InMemoryOutboxStore();
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAbandonedCartEnabled')->willReturn(true);
        $this->config->method('getCartDelayMinutes')->willReturn(60);
        $this->config->method('getCartMinValue')->willReturn(500.0);
        $this->enqueuer = new Enqueuer($this->outbox, $this->config, $this->createMock(LoggerInterface::class));
        $this->queries = $this->createMock(AbandonmentQueriesInterface::class);
        $this->queries->method('quoteHasPaidOrder')->willReturnCallback(fn ($q) => in_array($q, $this->paidQuotes, true));
        $this->queries->method('idleCarts')->willReturnCallback(fn () => $this->idleCarts);
    }

    private function contactProcessor(): ContactTimeoutProcessor
    {
        $contacts = $this->createMock(ContactStore::class);
        $contacts->method('findDue')->willReturnCallback(fn () => $this->contactRows);
        $contacts->method('setStatus')->willReturnCallback(function ($id, $status) {
            $this->statuses[$id] = $status;
        });

        return new ContactTimeoutProcessor($contacts, $this->queries, $this->outbox, $this->enqueuer, $this->createMock(LoggerInterface::class));
    }

    private function contact(int $id, int $quoteId, string $rzp): array
    {
        return ['id' => $id, 'quote_id' => $quoteId, 'rzp_order_id' => $rzp, 'email' => 'c+bentotest@x.com',
            'phone' => '+919000000001', 'store_id' => 1];
    }

    public function testDueContactWithoutPaymentOrDismissBecomesCheckoutAbandoned(): void
    {
        $this->contactRows = [$this->contact(3, 9001, 'order_A1')];
        $this->contactProcessor()->run();

        $this->assertSame('enqueued', $this->statuses[3]);
        $row = $this->outbox->rows[1];
        $this->assertSame('checkout_abandoned', $row['event_type']);
        $this->assertSame('contact', $row['entity_type']);
        $this->assertSame('checkout_abandoned:9001:order_A1', $row['dedup_key']);
        $this->assertSame('contact_captured', json_decode($row['context'], true)['source']);
    }

    public function testPaidOrderCancelsContactTimeout(): void
    {
        $this->paidQuotes = [9001];
        $this->contactRows = [$this->contact(3, 9001, 'order_A1')];
        $this->contactProcessor()->run();
        $this->assertSame('cancelled', $this->statuses[3]);
        $this->assertCount(0, $this->outbox->rows);
    }

    public function testEarlierDismissOfAnotherAttemptCancelsContactTimeout(): void
    {
        $this->enqueuer->enqueue('checkout_abandoned', 'order', 45, 'c@x.com', 1, 'checkout_abandoned:9001:order_OLD');
        $this->contactRows = [$this->contact(3, 9001, 'order_A1')];
        $this->contactProcessor()->run();
        $this->assertSame('cancelled', $this->statuses[3]);
        $this->assertCount(1, $this->outbox->rows);
    }

    private function cart(int $id, string $email = 'cart+bentotest@x.com'): array
    {
        return ['quote_id' => $id, 'email' => $email, 'store_id' => 1, 'updated_at' => '2026-10-13 08:00:00'];
    }

    private function idleProcessor(): IdleCartProcessor
    {
        return new IdleCartProcessor($this->queries, $this->outbox, $this->enqueuer, $this->config, $this->createMock(LoggerInterface::class));
    }

    public function testIdleCartIsQueuedOncePerIdlePeriod(): void
    {
        $this->idleCarts = [$this->cart(9001)];
        $this->idleProcessor()->run();
        $this->idleProcessor()->run();
        $this->assertCount(1, $this->outbox->rows);
        $this->assertSame('cart_abandoned:9001:' . strtotime('2026-10-13 08:00:00 UTC'), $this->outbox->rows[1]['dedup_key']);
    }

    public function testCartCoveredByCheckoutAbandonedIn24hIsNotQueued(): void
    {
        $this->enqueuer->enqueue('checkout_abandoned', 'order', 45, 'c@x.com', 1, 'checkout_abandoned:9001:order_A1');
        $this->idleCarts = [$this->cart(9001)];
        $this->idleProcessor()->run();
        $this->assertCount(1, $this->outbox->rows);
    }

    public function testModifiedCartAbandonedAgainWithin24hIsNotRequeued(): void
    {
        $this->idleCarts = [$this->cart(9001)];
        $this->idleProcessor()->run();
        $this->idleCarts = [['updated_at' => '2026-10-13 09:30:00'] + $this->cart(9001)];
        $this->idleProcessor()->run();
        $this->assertCount(1, $this->outbox->rows);
    }

    public function testQueryReceivesDelayAndMinimum(): void
    {
        $queries = $this->createMock(AbandonmentQueriesInterface::class);
        $queries->expects($this->once())->method('idleCarts')
            ->with($this->callback(fn ($idleBefore) => abs(strtotime($idleBefore . ' UTC') - (time() - 3600)) < 5), 500.0)
            ->willReturn([]);
        (new IdleCartProcessor($queries, $this->outbox, $this->enqueuer, $this->config, $this->createMock(LoggerInterface::class)))->run();
    }

    private function recovered(array $abandonRows, array $paidEmails): int
    {
        $queries = $this->createMock(AbandonmentQueriesInterface::class);
        $queries->method('sentAbandonRows')->willReturn($abandonRows);
        $queries->method('paidOrderEmails')->willReturnCallback(
            fn (array $quoteIds) => array_intersect_key($paidEmails, array_flip($quoteIds))
        );

        return (new RecoveredCartProcessor($queries, $this->enqueuer))->run();
    }

    private function abandonRow(string $dedupKey, string $email): array
    {
        return ['email' => $email, 'store_id' => 1, 'dedup_key' => $dedupKey];
    }

    public function testPaidUnderAnotherEmailTellsTheOlderEmailOnce(): void
    {
        $rows = [$this->abandonRow('cart_abandoned:9001:1761000000', 'a@x.com')];

        $this->assertSame(1, $this->recovered($rows, [9001 => ['b@y.com']]));
        $this->assertSame(0, $this->recovered($rows, [9001 => ['b@y.com']]), 'the next cron run adds nothing');

        $this->assertCount(1, $this->outbox->rows);
        $row = $this->outbox->rows[1];
        $this->assertSame('cart_recovered', $row['event_type']);
        $this->assertSame('quote', $row['entity_type']);
        $this->assertSame(9001, $row['entity_id']);
        $this->assertSame('a@x.com', $row['email']);
        $this->assertSame('cart_recovered:9001:a@x.com', $row['dedup_key']);
    }

    public function testCheckoutRowsMatchOnTheQuoteInTheirKeyForBothSources(): void
    {
        // Contact rows hold the contact id and dismissed rows the order id; the key always holds the quote.
        $this->recovered([
            $this->abandonRow('checkout_abandoned:9001:order_A1', 'a@x.com'),
            $this->abandonRow('checkout_abandoned:9002:order_B1', 'c@x.com'),
        ], [9001 => ['b@y.com'], 9002 => ['d@y.com']]);

        $this->assertSame(
            ['cart_recovered:9001:a@x.com', 'cart_recovered:9002:c@x.com'],
            array_values(array_map(fn ($r) => $r['dedup_key'], $this->outbox->rows))
        );
    }

    public function testSameBuyerEmailIsLeftToThePurchaseItself(): void
    {
        $this->assertSame(0, $this->recovered(
            [$this->abandonRow('cart_abandoned:9001:1761000000', 'a@x.com')],
            [9001 => [' A@X.com ', 'b@y.com']]
        ));
        $this->assertSame([], $this->outbox->rows);
    }

    public function testUnpaidCartSendsNothing(): void
    {
        $this->assertSame(0, $this->recovered([$this->abandonRow('cart_abandoned:9001:1761000000', 'a@x.com')], []));
    }

    public function testOnlyTheNewestCartPerEmailCounts(): void
    {
        // a@x.com abandoned a newer cart after the order: that run is genuine and must not be ended.
        $this->assertSame(0, $this->recovered([
            $this->abandonRow('cart_abandoned:9002:1761100000', 'a@x.com'),
            $this->abandonRow('cart_abandoned:9001:1761000000', 'a@x.com'),
        ], [9001 => ['b@y.com']]));
    }
}
