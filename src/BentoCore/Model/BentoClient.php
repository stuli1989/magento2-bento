<?php
/**
 * Bento Client
 *
 * HTTP client for communicating with the Bento API.
 * Uses the official Bento API endpoint with Basic Auth as documented at:
 * https://bentonow.com/docs/developer_guides/introduction
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoCore
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\MissingEmailException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class BentoClient implements BentoClientInterface
{
    /**
     * Bento API base URL
     */
    private const API_BASE_URL = 'https://app.bentonow.com/api/v1';

    /**
     * User agent string (required by Cloudflare)
     */
    private const USER_AGENT = 'ArtLounge-Magento/1.0';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Json $json,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function sendEvent(string $eventType, array $data, ?int $storeId = null): array
    {
        if (!$this->config->isEnabled($storeId)) {
            return [
                'success' => false,
                'retryable' => false,
                'message' => 'Bento integration is disabled'
            ];
        }

        $siteUuid = $this->config->getSiteUuid($storeId);
        if (!$siteUuid) {
            return [
                'success' => false,
                'retryable' => false,
                'message' => 'Bento Site UUID is not configured'
            ];
        }

        $eventUuid = Uuid::uuid4()->toString();

        // Build event in Bento's expected format
        // See: https://bentonow.com/docs/developer_guides/introduction
        $event = $this->formatEventForBento($eventType, $data, $eventUuid, $storeId);

        // Bento expects: { "events": [...], "site_uuid": "..." }
        // Note: site_uuid goes in the body, not the URL (per official bento-php-sdk)
        $payload = [
            'events' => [$event],
            'site_uuid' => $siteUuid
        ];

        $jsonPayload = $this->json->serialize($payload);
        $apiUrl = $this->getApiUrl();

        // Log debug info
        $this->config->debug('Sending event to Bento API', [
            'event_type' => $eventType,
            'url' => $apiUrl,
            'payload_size' => strlen($jsonPayload)
        ], $storeId);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Basic ' . $this->getBasicAuthCredentials($storeId),
                'User-Agent: ' . self::USER_AGENT
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->config->getTimeout());

            $responseBody = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                throw new \RuntimeException('Connection failed: ' . curl_error($ch));
            }
            curl_close($ch);

            // Log response
            $this->config->debug('Bento API response received', [
                'status_code' => $statusCode,
                'response' => substr($responseBody, 0, 500)
            ], $storeId);

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'message' => 'Event sent successfully',
                    'status_code' => $statusCode,
                    'response' => $responseBody,
                    'uuid' => $eventUuid
                ];
            }

            // Check if retryable
            $retryable = in_array($statusCode, [429, 500, 502, 503, 504]);

            return [
                'success' => false,
                'message' => sprintf('Bento API returned status code %d', $statusCode),
                'status_code' => $statusCode,
                'response' => $responseBody,
                'retryable' => $retryable,
                'uuid' => $eventUuid
            ];

        } catch (\Exception $e) {
            $this->logger->error('Bento API request failed', [
                'exception' => $e->getMessage(),
                'event_type' => $eventType,
                'url' => $apiUrl
            ]);

            return [
                'success' => false,
                'message' => 'Request failed: ' . $e->getMessage(),
                'retryable' => true,
                'uuid' => $eventUuid
            ];
        }
    }

    /**
     * @inheritdoc
     */
    public function testConnection(?int $storeId = null): array
    {
        $startTime = microtime(true);

        if (!$this->config->isEnabled($storeId)) {
            return [
                'success' => false,
                'message' => 'Bento integration is disabled'
            ];
        }

        $siteUuid = $this->config->getSiteUuid($storeId);
        $publishableKey = $this->config->getPublishableKey($storeId);
        $secretKey = $this->config->getSecretKey($storeId);

        if (!$siteUuid) {
            return [
                'success' => false,
                'message' => 'Site UUID is not configured'
            ];
        }

        if (!$publishableKey) {
            return [
                'success' => false,
                'message' => 'Publishable Key is not configured'
            ];
        }

        if (!$secretKey) {
            return [
                'success' => false,
                'message' => 'Secret Key is not configured'
            ];
        }

        // Send a test event to verify connection
        // Using a harmless internal event type that won't trigger automations
        // Note: site_uuid in body per official SDK
        $testPayload = [
            'events' => [[
                'type' => '$custom.connection_test',
                'email' => 'test@example.com',
                'details' => [
                    'test' => true,
                    'timestamp' => $this->dateTime->gmtDate('c'),
                    'source' => $this->config->getSource($storeId)
                ]
            ]],
            'site_uuid' => $siteUuid
        ];

        $jsonPayload = $this->json->serialize($testPayload);
        $apiUrl = $this->getApiUrl();

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Basic ' . $this->getBasicAuthCredentials($storeId),
                'User-Agent: ' . self::USER_AGENT
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $responseBody = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                throw new \RuntimeException('Connection failed: ' . curl_error($ch));
            }
            curl_close($ch);

            $endTime = microtime(true);
            $responseTimeMs = round(($endTime - $startTime) * 1000);

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'message' => 'Successfully connected to Bento API',
                    'api_url' => $apiUrl,
                    'response_time_ms' => $responseTimeMs
                ];
            }
            $errorMessage = sprintf('Bento API returned status code %d', $statusCode);

            if ($statusCode === 401) {
                $errorMessage = 'Authentication failed. Check your Publishable Key and Secret Key.';
            } elseif ($statusCode === 403) {
                $errorMessage = 'Access forbidden. Check your Site UUID.';
            }

            return [
                'success' => false,
                'message' => $errorMessage,
                'status_code' => $statusCode,
                'response' => $responseBody,
                'response_time_ms' => $responseTimeMs
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get the Bento API URL for batch events
     *
     * Note: site_uuid is passed in the JSON body, not the URL
     * (matching official bento-php-sdk behavior)
     *
     * @return string
     */
    private function getApiUrl(): string
    {
        return self::API_BASE_URL . '/batch/events';
    }

    /**
     * Get Basic Auth credentials (Base64 encoded publishable_key:secret_key)
     *
     * @param int|null $storeId
     * @return string
     * @throws \RuntimeException
     */
    private function getBasicAuthCredentials(?int $storeId = null): string
    {
        $publishableKey = $this->config->getPublishableKey($storeId);
        $secretKey = $this->config->getSecretKey($storeId);

        if (!$publishableKey || !$secretKey) {
            throw new \RuntimeException('Bento API credentials are not configured');
        }

        return base64_encode($publishableKey . ':' . $secretKey);
    }

    /**
     * Format event data for Bento's expected structure
     *
     * Bento expects:
     * {
     *   "type": "$purchase",
     *   "email": "user@example.com",
     *   "fields": { "first_name": "...", "last_name": "..." },
     *   "details": { ... event-specific data ... }
     * }
     *
     * @param string $eventType
     * @param array $data
     * @param string $eventUuid
     * @param int|null $storeId
     * @return array
     * @throws MissingEmailException When email is not present in event data
     */
    private function formatEventForBento(
        string $eventType,
        array $data,
        string $eventUuid,
        ?int $storeId = null
    ): array {
        // Extract email from various possible locations in the data
        $email = $this->extractEmail($data);

        if (!$email) {
            throw new MissingEmailException('Email is required for Bento events');
        }

        // Extract subscriber fields (name, etc.) for the "fields" property
        $fields = $this->extractSubscriberFields($data);

        // Everything else goes into "details" for event-specific data
        $details = $this->buildEventDetails($eventType, $data, $eventUuid, $storeId);

        $event = [
            'type' => $eventType,
            'email' => $email,
        ];

        // Only include fields if we have any
        if (!empty($fields)) {
            $event['fields'] = $fields;
        }

        // Only include details if we have any
        if (!empty($details)) {
            $event['details'] = $details;
        }

        return $event;
    }

    /**
     * Extract email from event data
     *
     * @param array $data
     * @return string|null
     */
    private function extractEmail(array $data): ?string
    {
        // Check common locations for email
        if (!empty($data['email'])) {
            return $data['email'];
        }

        if (!empty($data['customer']['email'])) {
            return $data['customer']['email'];
        }

        if (!empty($data['subscriber']['email'])) {
            return $data['subscriber']['email'];
        }

        return null;
    }

    /**
     * Extract subscriber fields (first_name, last_name, etc.)
     *
     * These are stored on the subscriber record in Bento
     *
     * @param array $data
     * @return array
     */
    private function extractSubscriberFields(array $data): array
    {
        $fields = [];

        // Extract from customer data
        $customer = $data['customer'] ?? [];

        if (!empty($customer['firstname'])) {
            $fields['first_name'] = $customer['firstname'];
        }

        if (!empty($customer['lastname'])) {
            $fields['last_name'] = $customer['lastname'];
        }

        // Extract from subscriber data (for newsletter events)
        $subscriber = $data['subscriber'] ?? [];

        if (!empty($subscriber['firstname']) && empty($fields['first_name'])) {
            $fields['first_name'] = $subscriber['firstname'];
        }

        if (!empty($subscriber['lastname']) && empty($fields['last_name'])) {
            $fields['last_name'] = $subscriber['lastname'];
        }

        // Include source if present (e.g. footer_newsletter, customer_account, popup)
        if (!empty($subscriber['source'])) {
            $fields['source'] = $subscriber['source'];
        }

        // Include tags if present
        if (!empty($data['tags'])) {
            $fields['tags'] = is_array($data['tags']) ? implode(',', $data['tags']) : $data['tags'];
        }

        return $fields;
    }

    /**
     * Build event details (event-specific data)
     *
     * Format follows official Bento SDK structure:
     * - unique: { key: "uuid" } for deduplication
     * - value: { amount: cents, currency: "USD" } for LTV tracking
     * - cart: { items: [...] } for product data
     *
     * @see https://github.com/bentonow/bento-php-sdk
     * @see https://github.com/bentonow/bento-node-sdk
     *
     * @param string $eventType
     * @param array $data
     * @param string $eventUuid
     * @param int|null $storeId
     * @return array
     */
    private function buildEventDetails(
        string $eventType,
        array $data,
        string $eventUuid,
        ?int $storeId = null
    ): array
    {
        $uniqueKey = $this->resolveUniqueKey($eventType, $data, $eventUuid);

        // Start with metadata
        // unique.key format as per official SDK: unique: { key: "order-123" }
        $details = [
            'unique' => [
                'key' => $uniqueKey
            ],
            'source' => $this->config->getSource($storeId),
            'timestamp' => $this->dateTime->gmtDate('c')
        ];

        // Include order data if present (order ID, increment ID, etc.)
        if (!empty($data['order'])) {
            $details['order'] = $data['order'];

        }

        // Include financial data if present (for purchases)
        // Bento SDK expects value: { amount: cents, currency: "USD" }
        // Also include currency as a sibling for convenience in segments/conditions
        if (!empty($data['financials'])) {
            $details['value'] = [
                'amount' => $data['financials']['total_value'] ?? 0,
                'currency' => $data['financials']['currency_code'] ?? 'USD'
            ];
            $details['currency'] = $data['financials']['currency_code'] ?? 'USD';
        }

        // Include items/cart data
        // Format as per official SDK: cart: { items: [...] }
        // Merge cart metadata with items to preserve both
        if (!empty($data['cart']) || !empty($data['items'])) {
            $cartData = [];

            // Start with cart metadata if present
            if (!empty($data['cart'])) {
                $cartData = $data['cart'];
            }

            // Add/merge items - items from data['items'] take precedence if cart doesn't have them
            if (!empty($data['items']) && empty($cartData['items'])) {
                $cartData['items'] = $data['items'];
            }

            $details['cart'] = $cartData;
        }

        // Include shipment data
        if (!empty($data['shipment'])) {
            $details['shipment'] = $data['shipment'];
        }

        // Include refund data
        if (!empty($data['refund'])) {
            $details['refund'] = $data['refund'];
        }

        // Include summary data (product names, categories, etc.)
        if (!empty($data['summary'])) {
            $details['summary'] = $data['summary'];
        }

        // Include addresses if present
        if (!empty($data['addresses'])) {
            $details['addresses'] = $data['addresses'];
        }

        // Include store info
        if (!empty($data['store'])) {
            $details['store'] = $data['store'];
        }

        // Include any flags
        if (!empty($data['flags'])) {
            $details['flags'] = $data['flags'];
        }

        // Include financials breakdown (subtotal, shipping, discount, tax)
        // value.amount/currency is derived from financials above; this preserves the full breakdown
        if (!empty($data['financials'])) {
            $details['financials'] = $data['financials'];
        }

        // Include customer data (email, name, customer_id, group)
        // Email and name are also extracted to event.email and event.fields,
        // but the nested object is useful for Liquid templates and flow conditions
        if (!empty($data['customer'])) {
            $details['customer'] = $data['customer'];
        }

        // Include subscriber data (for newsletter subscribe/unsubscribe events)
        if (!empty($data['subscriber'])) {
            $details['subscriber'] = $data['subscriber'];
        }

        // Include payment method
        if (!empty($data['payment'])) {
            $details['payment'] = $data['payment'];
        }

        // Include shipping method
        if (!empty($data['shipping'])) {
            $details['shipping_method'] = $data['shipping'];
        }

        // Include cart_id at top level for Bento cart lifecycle matching
        // Used to link $cart_created → $cart_updated → $cart_abandoned → $purchase
        if (!empty($data['cart_id'])) {
            $details['cart_id'] = (string)$data['cart_id'];
        } elseif (!empty($data['order']['quote_id'])) {
            // For purchase events: promote order.quote_id to top-level cart_id
            $details['cart_id'] = (string)$data['order']['quote_id'];
        }

        // Include recovery URL for abandoned carts
        if (!empty($data['recovery_url'])) {
            $details['recovery_url'] = $data['recovery_url'];
        } elseif (!empty($data['recovery']['cart_url'])) {
            // Backward compatibility with legacy nested recovery payload
            $details['recovery_url'] = $data['recovery']['cart_url'];
        }

        if (!empty($data['coupon'])) {
            $details['coupon'] = $data['coupon'];
        }

        return $details;
    }

    /**
     * Resolve a deterministic unique key for Bento deduplication.
     *
     * @param string $eventType
     * @param array $data
     * @param string $eventUuid
     * @return string
     */
    private function resolveUniqueKey(string $eventType, array $data, string $eventUuid): string
    {
        if (!empty($data['shipment']['increment_id'])) {
            return 'shipment:' . $data['shipment']['increment_id'];
        }

        if (!empty($data['refund']['increment_id'])) {
            return 'refund:' . $data['refund']['increment_id'];
        }

        if (!empty($data['order']['increment_id'])) {
            // Preserve purchase compatibility with frontend fallback dedupe key
            if ($eventType === '$purchase') {
                return (string)$data['order']['increment_id'];
            }

            return ltrim(strtolower($eventType), '$') . ':' . $data['order']['increment_id'];
        }

        if ($eventType === '$cart_abandoned' && !empty($data['cart']['quote_id'])) {
            return 'cart_abandoned:' . $data['cart']['quote_id'];
        }

        return $eventUuid;
    }
}
