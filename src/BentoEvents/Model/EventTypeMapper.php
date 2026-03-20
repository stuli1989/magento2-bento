<?php
/**
 * Event Type Mapper
 *
 * Maps async event names to Bento event types.
 * Async event names follow the format "bento.{entity}.{action}"
 * Bento event types follow their SDK conventions (e.g., $purchase, $subscribe)
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model;

class EventTypeMapper
{
    /**
     * Mapping of async event names to Bento event types
     *
     * @var array<string, string>
     */
    private const EVENT_TYPE_MAP = [
        // Order events
        'bento.order.placed' => '$purchase',
        'bento.order.shipped' => '$OrderShipped',
        'bento.order.cancelled' => '$OrderCanceled',
        'bento.order.refunded' => '$OrderRefunded',

        // Customer events
        'bento.customer.created' => '$Subscriber',
        'bento.customer.updated' => '$CustomerUpdated',

        // Newsletter events
        'bento.newsletter.subscribed' => '$subscribe',
        'bento.newsletter.unsubscribed' => '$unsubscribe',

        // Abandoned cart events
        'bento.cart.abandoned' => '$cart_abandoned',
    ];

    /**
     * Get Bento event type for a given async event name
     *
     * @param string $asyncEventName The async event name (e.g., "bento.order.placed")
     * @return string The Bento event type (e.g., "$purchase")
     */
    public function getBentoEventType(string $asyncEventName): string
    {
        return self::EVENT_TYPE_MAP[$asyncEventName] ?? $asyncEventName;
    }

    /**
     * Check if an async event name is a known Bento event
     *
     * @param string $asyncEventName
     * @return bool
     */
    public function isBentoEvent(string $asyncEventName): bool
    {
        return str_starts_with($asyncEventName, 'bento.');
    }

    /**
     * Get all registered event mappings
     *
     * @return array<string, string>
     */
    public function getAllMappings(): array
    {
        return self::EVENT_TYPE_MAP;
    }
}
