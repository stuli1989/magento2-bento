<?php
/**
 * Service Data Resolver
 *
 * Re-fetches original associative data from service classes.
 *
 * Aligent's AsyncEventTriggerHandler runs service output through Magento's
 * ServiceOutputProcessor, which strips associative keys from arrays. This
 * resolver re-invokes the service method using the entity ID extracted from
 * the flattened data to get the original associative structure that
 * BentoClient expects.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model;

use ArtLounge\BentoEvents\Service\AbandonedCartService;
use ArtLounge\BentoEvents\Service\CustomerService;
use ArtLounge\BentoEvents\Service\NewsletterService;
use ArtLounge\BentoEvents\Service\OrderService;
use ArtLounge\BentoEvents\Service\RefundService;
use ArtLounge\BentoEvents\Service\ShipmentService;
use Psr\Log\LoggerInterface;

class ServiceDataResolver
{
    /**
     * Map of async event names to [service, method, idExtractor].
     * The idExtractor is a callable that extracts the entity ID from the
     * flattened (positional) data array produced by ServiceOutputProcessor.
     */
    private const EVENT_SERVICE_MAP = [
        'bento.cart.abandoned' => [
            'service' => 'abandoned_cart',
            'method' => 'getAbandonedCartData',
            'id_type' => 'int',
        ],
        'bento.order.placed' => [
            'service' => 'order',
            'method' => 'getOrderDataByIncrementId',
            'id_type' => 'string',
        ],
        'bento.order.cancelled' => [
            'service' => 'order',
            'method' => 'getOrderData',
            'id_type' => 'int',
        ],
        'bento.order.shipped' => [
            'service' => 'shipment',
            'method' => 'getShipmentData',
            'id_type' => 'int',
        ],
        'bento.order.refunded' => [
            'service' => 'refund',
            'method' => 'getRefundData',
            'id_type' => 'int',
        ],
        'bento.customer.created' => [
            'service' => 'customer',
            'method' => 'getCustomerData',
            'id_type' => 'int',
        ],
        'bento.customer.updated' => [
            'service' => 'customer',
            'method' => 'getCustomerData',
            'id_type' => 'int',
        ],
        'bento.newsletter.subscribed' => [
            'service' => 'newsletter',
            'method' => 'getSubscriberData',
            'id_type' => 'int',
        ],
        'bento.newsletter.unsubscribed' => [
            'service' => 'newsletter',
            'method' => 'getSubscriberData',
            'id_type' => 'int',
        ],
    ];

    public function __construct(
        private readonly AbandonedCartService $abandonedCartService,
        private readonly OrderService $orderService,
        private readonly CustomerService $customerService,
        private readonly NewsletterService $newsletterService,
        private readonly ShipmentService $shipmentService,
        private readonly RefundService $refundService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resolve original associative data for a given async event.
     *
     * Extracts the entity ID from the flattened positional array and
     * re-invokes the service method to get proper associative keys.
     *
     * @param string $asyncEventName e.g. "bento.cart.abandoned"
     * @param array $flatData The positional array from ServiceOutputProcessor
     * @return array Original associative data from the service method
     */
    public function resolve(string $asyncEventName, array $flatData): array
    {
        // If data already has associative keys (e.g. 'customer', 'email'),
        // it wasn't flattened — return as-is
        if ($this->isAssociative($flatData)) {
            return $flatData;
        }

        $config = self::EVENT_SERVICE_MAP[$asyncEventName] ?? null;
        if ($config === null) {
            $this->logger->warning('ServiceDataResolver: unknown event, passing data through', [
                'event' => $asyncEventName
            ]);
            return $flatData;
        }

        $entityId = $this->extractEntityId($asyncEventName, $flatData, $config);
        if ($entityId === null) {
            $this->logger->error('ServiceDataResolver: could not extract entity ID', [
                'event' => $asyncEventName,
                'data_count' => count($flatData)
            ]);
            return $flatData;
        }

        $service = $this->getService($config['service']);
        $method = $config['method'];

        $this->logger->debug('ServiceDataResolver: re-fetching data from service', [
            'event' => $asyncEventName,
            'service' => get_class($service),
            'method' => $method,
            'entity_id' => $entityId
        ]);

        return $service->$method($entityId);
    }

    private function getService(string $key): object
    {
        return match ($key) {
            'abandoned_cart' => $this->abandonedCartService,
            'order' => $this->orderService,
            'customer' => $this->customerService,
            'newsletter' => $this->newsletterService,
            'shipment' => $this->shipmentService,
            'refund' => $this->refundService,
        };
    }

    /**
     * Extract entity ID from flattened data based on event type.
     *
     * ServiceOutputProcessor strips keys, so the structure is positional.
     * Each service returns a different structure:
     * - AbandonedCartService: [event_type, cart_id(int), cart{}, financials{}, items[], customer{}, ...]
     * - OrderService (formatOrderData): [event_type, order{id,increment_id,...}, financials{}, ...]
     * - CustomerService: [event_type, customer{customer_id,...}, ...]
     * - NewsletterService: [event_type, subscriber{subscriber_id,...}, ...]
     * - ShipmentService: [event_type, shipment{shipment_id,...}, ...] (merged with order data)
     * - RefundService: [event_type, refund{creditmemo_id,...}, ...] (merged with order data)
     *
     * @return int|string|null
     */
    private function extractEntityId(string $eventName, array $flatData, array $config): int|string|null
    {
        $secondElement = $flatData[1] ?? null;
        if ($secondElement === null) {
            return null;
        }

        return match ($eventName) {
            // cart_id is a scalar at index 1
            'bento.cart.abandoned' => (int)$secondElement,

            // order{} array at index 1 — extract order.id or order.increment_id
            'bento.order.placed' => is_array($secondElement)
                ? (string)($secondElement['increment_id'] ?? '')
                : (string)$secondElement,
            'bento.order.cancelled' => is_array($secondElement)
                ? (int)($secondElement['id'] ?? 0)
                : (int)$secondElement,

            // These events merge order data first, so order{} is at index 1
            // and shipment{}/refund{} follows. We need order.id to look up the
            // shipment/refund, but the service methods take shipment_id/creditmemo_id.
            // Search for the shipment/refund sub-array deeper in the flattened data.
            'bento.order.shipped' => $this->findNestedId($flatData, 'shipment_id'),
            'bento.order.refunded' => $this->findNestedId($flatData, 'creditmemo_id'),

            // customer{} at index 1
            'bento.customer.created',
            'bento.customer.updated' => is_array($secondElement)
                ? (int)($secondElement['customer_id'] ?? 0)
                : (int)$secondElement,

            // subscriber{} at index 1
            'bento.newsletter.subscribed',
            'bento.newsletter.unsubscribed' => is_array($secondElement)
                ? (int)($secondElement['subscriber_id'] ?? 0)
                : (int)$secondElement,

            default => null,
        };
    }

    /**
     * Search flattened array for a sub-array containing the given key
     */
    private function findNestedId(array $flatData, string $key): ?int
    {
        foreach ($flatData as $element) {
            if (is_array($element) && isset($element[$key])) {
                return (int)$element[$key];
            }
        }
        return null;
    }

    /**
     * Check if an array has associative (string) keys
     */
    private function isAssociative(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        return !array_is_list($data);
    }
}
