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
use ArtLounge\BentoEvents\Service\CheckoutService;
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
        'bento.checkout.started' => [
            'service' => 'checkout',
            'method' => 'getCheckoutStartedData',
            'id_type' => 'int',
        ],
    ];

    public function __construct(
        private readonly AbandonedCartService $abandonedCartService,
        private readonly CheckoutService $checkoutService,
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
            'checkout' => $this->checkoutService,
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
     * Observers publish [eventName, jsonArguments] where jsonArguments is a
     * serialized JSON string like '{"id":123}' or '{"increment_id":"000000123"}'.
     * The second element must be JSON-decoded before extracting the ID key.
     *
     * @return int|string|null
     */
    private function extractEntityId(string $eventName, array $flatData, array $config): int|string|null
    {
        $secondElement = $flatData[1] ?? null;
        if ($secondElement === null) {
            return null;
        }

        // Arguments arrive as a JSON string from observer serialization — decode first
        if (is_string($secondElement)) {
            $decoded = json_decode($secondElement, true);
            if (is_array($decoded)) {
                $secondElement = $decoded;
            }
        }

        // After decoding: ['id' => N] for most events, ['increment_id' => 'N'] for order.placed
        if (is_array($secondElement)) {
            return match ($eventName) {
                'bento.order.placed' => (string)($secondElement['increment_id'] ?? ''),
                default => ($config['id_type'] === 'string')
                    ? (string)($secondElement['id'] ?? '')
                    : (int)($secondElement['id'] ?? 0),
            };
        }

        // Scalar fallback (pre-decoded plain value)
        return ($config['id_type'] === 'string') ? (string)$secondElement : (int)$secondElement;
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
