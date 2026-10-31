<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\CartRecoveredBuilder;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use PHPUnit\Framework\TestCase;

class CartRecoveredBuilderTest extends TestCase
{
    public function testEventGoesToTheOlderEmailWithoutTouchingAnyField(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');

        $result = (new CartRecoveredBuilder(new EventEnvelope($config)))->build(OutboxRow::fromArray([
            'id' => 1, 'event_type' => 'cart_recovered', 'entity_type' => 'quote', 'entity_id' => 9001,
            'email' => 'a@x.com', 'store_id' => 1, 'dedup_key' => 'cart_recovered:9001:a@x.com',
            'created_at' => '2026-11-01 10:00:00',
        ]));

        $this->assertFalse($result->isSkip());
        $this->assertNull($result->subscriber);
        $this->assertSame('$cart_recovered', $result->event['type']);
        $this->assertSame('a@x.com', $result->event['email']);
        $this->assertSame(9001, $result->event['details']['quote_id']);
        $this->assertSame('cart_recovered:9001:a@x.com', $result->event['details']['unique']['key']);
        $this->assertArrayNotHasKey('fields', $result->event);
    }
}
