<?php
/**
 * Bento HTTP client (v2). Thin: formats nothing, classifies results.
 * Payloads are built by BentoEvents PayloadBuilders.
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\Http\TransportInterface;
use Psr\Log\LoggerInterface;

class BentoClient implements BentoClientInterface
{
    private const API_BASE_URL = 'https://app.bentonow.com/api/v1';
    private const USER_AGENT = 'ArtLounge-Magento/2.0';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly TransportInterface $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    public function postEvents(array $events, ?int $storeId = null): SendResult
    {
        return $this->post('/batch/events', ['events' => array_values($events)], $storeId);
    }

    public function importSubscribers(array $subscribers, ?int $storeId = null): SendResult
    {
        return $this->post('/batch/subscribers', ['subscribers' => array_values($subscribers)], $storeId);
    }

    public function fetchSubscriberByUuid(string $uuid, ?int $storeId = null): SendResult
    {
        if (!$this->config->isConfigured($storeId)) {
            return SendResult::notConfigured();
        }
        $url = self::API_BASE_URL . '/fetch/subscribers?site_uuid=' . rawurlencode((string)$this->config->getSiteUuid($storeId))
            . '&uuid=' . rawurlencode($uuid);
        try {
            $raw = $this->transport->request('GET', $url, $this->headers($storeId), null, $this->config->getTimeout());
        } catch (\Throwable $e) {
            $raw = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
        $result = new SendResult((int)$raw['status'], (string)$raw['body'], $raw['error'] ?? null);
        $this->config->debug('Bento GET /fetch/subscribers (uuid)', ['result' => $result->describe()], $storeId);

        return $result;
    }

    public function testConnection(?int $storeId = null): array
    {
        if (!$this->config->isConfigured($storeId)) {
            return ['success' => false, 'message' => 'Bento is disabled or Site UUID / keys are missing'];
        }
        $start = microtime(true);
        // Read-only endpoint: verifies credentials without creating any event or subscriber.
        $url = self::API_BASE_URL . '/fetch/tags?site_uuid=' . rawurlencode((string)$this->config->getSiteUuid($storeId));
        $raw = $this->transport->request('GET', $url, $this->headers($storeId), null, $this->config->getTimeout());
        $result = new SendResult((int)$raw['status'], (string)$raw['body'], $raw['error'] ?? null);
        $ms = (int)round((microtime(true) - $start) * 1000);

        if ($result->isSuccess()) {
            return ['success' => true, 'message' => 'Connected to Bento', 'response_time_ms' => $ms];
        }
        $message = match ($result->getStatus()) {
            401 => 'Authentication failed — check Publishable Key and Secret Key.',
            403 => 'Access forbidden — check Site UUID.',
            default => 'Bento API error: ' . $result->describe(),
        };

        return ['success' => false, 'message' => $message, 'response_time_ms' => $ms];
    }

    private function post(string $path, array $payload, ?int $storeId): SendResult
    {
        if (!$this->config->isConfigured($storeId)) {
            return SendResult::notConfigured();
        }
        $payload['site_uuid'] = (string)$this->config->getSiteUuid($storeId);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($body === false) {
            return new SendResult(0, '', 'JSON encode failed: ' . json_last_error_msg());
        }

        try {
            $raw = $this->transport->request(
                'POST',
                self::API_BASE_URL . $path,
                $this->headers($storeId),
                $body,
                $this->config->getTimeout()
            );
        } catch (\Throwable $e) {
            $raw = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
        $result = new SendResult((int)$raw['status'], (string)$raw['body'], $raw['error'] ?? null);

        $this->config->debug('Bento POST ' . $path, [
            'bytes' => strlen($body),
            'result' => $result->describe(),
        ], $storeId);
        if (!$result->isSuccess()) {
            $this->logger->warning('Bento API call failed', ['path' => $path, 'result' => $result->describe()]);
        }

        return $result;
    }

    /** @return string[] */
    private function headers(?int $storeId): array
    {
        $auth = base64_encode($this->config->getPublishableKey($storeId) . ':' . $this->config->getSecretKey($storeId));

        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . $auth,
            'User-Agent: ' . self::USER_AGENT,
        ];
    }
}
