<?php
/**
 * Event Type Mapper Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model;

use ArtLounge\BentoEvents\Model\EventTypeMapper;
use PHPUnit\Framework\TestCase;

class EventTypeMapperTest extends TestCase
{
    public function testGetBentoEventTypeReturnsMappedType(): void
    {
        $mapper = new EventTypeMapper();

        $this->assertSame('$purchase', $mapper->getBentoEventType('bento.order.placed'));
    }

    public function testGetBentoEventTypeReturnsOriginalWhenUnknown(): void
    {
        $mapper = new EventTypeMapper();

        $this->assertSame('bento.unknown.event', $mapper->getBentoEventType('bento.unknown.event'));
    }

    public function testIsBentoEventDetectsPrefix(): void
    {
        $mapper = new EventTypeMapper();

        $this->assertTrue($mapper->isBentoEvent('bento.order.placed'));
        $this->assertFalse($mapper->isBentoEvent('sales.order.place'));
    }

    public function testGetAllMappingsContainsExpectedKeys(): void
    {
        $mapper = new EventTypeMapper();
        $mappings = $mapper->getAllMappings();

        $this->assertArrayHasKey('bento.order.placed', $mappings);
        $this->assertSame('$abandoned', $mappings['bento.cart.abandoned']);
    }

    /**
     * @dataProvider allEventMappingsProvider
     */
    public function testAllEventMappings(string $asyncEvent, string $expectedBento): void
    {
        $mapper = new EventTypeMapper();
        $this->assertSame($expectedBento, $mapper->getBentoEventType($asyncEvent));
    }

    public static function allEventMappingsProvider(): array
    {
        return [
            'order placed' => ['bento.order.placed', '$purchase'],
            'order shipped' => ['bento.order.shipped', '$OrderShipped'],
            'order cancelled' => ['bento.order.cancelled', '$OrderCanceled'],
            'order refunded' => ['bento.order.refunded', '$OrderRefunded'],
            'customer created' => ['bento.customer.created', '$Subscriber'],
            'customer updated' => ['bento.customer.updated', '$CustomerUpdated'],
            'newsletter subscribed' => ['bento.newsletter.subscribed', '$subscribe'],
            'newsletter unsubscribed' => ['bento.newsletter.unsubscribed', '$unsubscribe'],
            'cart abandoned' => ['bento.cart.abandoned', '$abandoned'],
        ];
    }

    public function testGetAllMappingsCountIs9(): void
    {
        $mapper = new EventTypeMapper();
        $this->assertCount(9, $mapper->getAllMappings());
    }

    public function testIsBentoEventReturnsFalseForEmptyString(): void
    {
        $mapper = new EventTypeMapper();
        $this->assertFalse($mapper->isBentoEvent(''));
    }

    public function testIsBentoEventReturnsTrueForUnknownBentoEvent(): void
    {
        $mapper = new EventTypeMapper();
        $this->assertTrue($mapper->isBentoEvent('bento.custom.event'));
    }
}
