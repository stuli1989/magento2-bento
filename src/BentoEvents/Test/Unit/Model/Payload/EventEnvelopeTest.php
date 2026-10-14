<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use ArtLounge\BentoEvents\Model\Payload\Phone;
use PHPUnit\Framework\TestCase;

class EventEnvelopeTest extends TestCase
{
    private function row(array $o = []): OutboxRow
    {
        return OutboxRow::fromArray($o + [
            'id' => 1, 'event_type' => 'purchase', 'entity_type' => 'order', 'entity_id' => 45,
            'email' => 'a+bentotest@x.com', 'store_id' => 1, 'dedup_key' => 'purchase:100045',
            'created_at' => '2026-10-13 10:00:00',
        ]);
    }

    private function envelope(): EventEnvelope
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');
        return new EventEnvelope($config);
    }

    public function testEveryEventGetsUniqueKeySourceAndIstTimestamp(): void
    {
        $e = $this->envelope()->wrap($this->row(), '$purchase', ['order' => ['id' => 45]], '100045', ['first_name' => 'K']);

        $this->assertSame('$purchase', $e['type']);
        $this->assertSame('a+bentotest@x.com', $e['email']);
        $this->assertSame(['first_name' => 'K'], $e['fields']);
        $this->assertSame(['key' => '100045'], $e['details']['unique']);
        $this->assertSame('artlounge', $e['details']['source']);
        $this->assertSame('2026-10-13T15:30:00+05:30', $e['details']['timestamp']);
        $this->assertSame(['id' => 45], $e['details']['order']);
    }

    public function testUniqueKeyDefaultsToDedupKeyAndEmptyFieldsOmitted(): void
    {
        $e = $this->envelope()->wrap($this->row(), '$cart_abandoned', ['a' => 1]);
        $this->assertSame('purchase:100045', $e['details']['unique']['key']);
        $this->assertArrayNotHasKey('fields', $e);
    }

    public function testSnapshotFieldsKeepEmptyStringsSoStaleSlotsAreCleared(): void
    {
        $e = $this->envelope()->wrap($this->row(), '$cart_abandoned', [], null, ['first_name' => '', 'phone' => '+91'],
            ['last_cart_item_2_name' => '', 'last_cart_item_count' => 1, 'last_cart_item_1_name' => 'A']);

        $this->assertSame(['phone' => '+91', 'last_cart_item_2_name' => '', 'last_cart_item_count' => 1,
            'last_cart_item_1_name' => 'A'], $e['fields'], 'profile blanks dropped, snapshot blanks kept');
    }

    public function testDetailsCannotOverrideEnvelopeKeys(): void
    {
        $e = $this->envelope()->wrap($this->row(), '$x', ['source' => 'dismissed', 'timestamp' => 'bogus']);
        $this->assertSame('artlounge', $e['details']['source']);
        $this->assertSame('2026-10-13T15:30:00+05:30', $e['details']['timestamp']);
    }

    /**
     * @dataProvider phones
     */
    public function testPhoneNormalisation(?string $in, ?string $out): void
    {
        $this->assertSame($out, Phone::normalize($in));
    }

    public static function phones(): array
    {
        return [
            ['98200 12345', '+919820012345'],
            ['+91-98200-12345', '+919820012345'],
            ['919820012345', '+919820012345'],
            ['09820012345', '+919820012345'],
            ['+44 20 7946 0958', '+442079460958'],
            ['022 2345 6789', '02223456789'],
            ['', null],
            [null, null],
            ['abc', null],
        ];
    }
}
