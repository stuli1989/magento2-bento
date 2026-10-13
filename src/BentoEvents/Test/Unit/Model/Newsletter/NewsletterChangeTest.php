<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Newsletter;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Newsletter\NewsletterChange;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use ArtLounge\BentoEvents\Model\Payload\SubscribeBuilder;
use PHPUnit\Framework\TestCase;

class NewsletterChangeTest extends TestCase
{
    private const SUBSCRIBED = 1;
    private const NOT_ACTIVE = 2;
    private const UNSUBSCRIBED = 3;

    public function testNewSubscriberSubscribed(): void
    {
        $this->assertSame('subscribe', NewsletterChange::decide(null, self::SUBSCRIBED, 0, 0));
    }

    public function testUnsubscribe(): void
    {
        $this->assertSame('unsubscribe', NewsletterChange::decide(self::SUBSCRIBED, self::UNSUBSCRIBED, 5, 5));
    }

    public function testResubscribe(): void
    {
        $this->assertSame('subscribe', NewsletterChange::decide(self::UNSUBSCRIBED, self::SUBSCRIBED, 0, 0));
    }

    public function testNoStatusChangeIsNothing(): void
    {
        $this->assertNull(NewsletterChange::decide(self::SUBSCRIBED, self::SUBSCRIBED, 0, 0));
    }

    public function testPendingConfirmationIsNothing(): void
    {
        $this->assertNull(NewsletterChange::decide(null, self::NOT_ACTIVE, 0, 0));
    }

    public function testGuestToCustomerLinkingIsSkipped(): void
    {
        $this->assertNull(NewsletterChange::decide(self::SUBSCRIBED, self::UNSUBSCRIBED, 0, 77));
    }

    public function testDedupKeyCollapsesDoubleSavesWithinAMinute(): void
    {
        $a = NewsletterChange::dedupKey(10, 'subscribe', strtotime('2026-10-13 10:00:05 UTC'));
        $b = NewsletterChange::dedupKey(10, 'subscribe', strtotime('2026-10-13 10:00:55 UTC'));
        $c = NewsletterChange::dedupKey(10, 'subscribe', strtotime('2026-10-13 10:02:00 UTC'));
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertStringStartsWith('subscribe:10:', $a);
    }

    public function testBuilderMapsContextToBentoEvent(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');
        $builder = new SubscribeBuilder(new EventEnvelope($config));

        $row = OutboxRow::fromArray([
            'id' => 3, 'event_type' => 'unsubscribe', 'entity_type' => 'subscriber', 'entity_id' => 10,
            'email' => 'a+bentotest@x.com', 'store_id' => 1, 'dedup_key' => 'unsubscribe:10:202610131000',
            'created_at' => '2026-10-13 10:00:00',
            'context' => json_encode(['customer_id' => null, 'source' => 'footer_newsletter']),
        ]);
        $e = $builder->build($row)->event;

        $this->assertSame('$unsubscribe', $e['type']);
        $this->assertSame([
            'subscriber_id' => 10,
            'email' => 'a+bentotest@x.com',
            'status' => 'unsubscribed',
            'customer_id' => null,
            'changed_at' => '2026-10-13T15:30:00+05:30',
            'source' => 'footer_newsletter',
        ], $e['details']['subscriber']);
        $this->assertArrayNotHasKey('fields', $e, 'no tags, no optin field');
    }
}
