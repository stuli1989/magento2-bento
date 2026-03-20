<?php
/**
 * Bento Client Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Model;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\BentoClient;
use ArtLounge\BentoCore\Model\MissingEmailException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BentoClientTest extends TestCase
{
    private BentoClient $client;
    private MockObject $config;
    private MockObject $curlFactory;
    private MockObject $curl;
    private MockObject $json;
    private MockObject $dateTime;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->curl = $this->createMock(Curl::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curlFactory->method('create')->willReturn($this->curl);
        $this->json = $this->createMock(Json::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->client = new BentoClient(
            $this->config,
            $this->curlFactory,
            $this->json,
            $this->dateTime,
            $this->logger
        );
    }

    public function testSendEventReturnsFailureWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $result = $this->client->sendEvent('$purchase', ['email' => 'a@b.com']);

        $this->assertFalse($result['success']);
        $this->assertSame('Bento integration is disabled', $result['message']);
    }

    public function testSendEventReturnsFailureWhenMissingSiteUuid(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn(null);

        $result = $this->client->sendEvent('$purchase', ['email' => 'a@b.com']);

        $this->assertFalse($result['success']);
        $this->assertSame('Bento Site UUID is not configured', $result['message']);
    }

    public function testSendEventThrowsMissingEmailException(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');

        $this->expectException(MissingEmailException::class);

        $this->client->sendEvent('$purchase', ['order' => ['increment_id' => '1001']]);
    }

    public function testSendEventReturnsSuccessOn2xx(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $this->json->method('serialize')->willReturn('{"events":[],"site_uuid":"site-uuid"}');

        $this->curl->expects($this->once())->method('setHeaders');
        $this->curl->expects($this->once())->method('setTimeout')->with(30);
        $this->curl->expects($this->once())->method('post');
        $this->curl->method('getStatus')->willReturn(202);
        $this->curl->method('getBody')->willReturn('{"status":"ok"}');

        $result = $this->client->sendEvent('$purchase', ['email' => 'a@b.com']);

        $this->assertTrue($result['success']);
        $this->assertSame(202, $result['status_code']);
        $this->assertArrayHasKey('uuid', $result);
    }

    public function testSendEventMarksRetryableErrors(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');
        $this->json->method('serialize')->willReturn('{"events":[],"site_uuid":"site-uuid"}');

        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('error');

        $result = $this->client->sendEvent('$purchase', ['email' => 'a@b.com']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
        $this->assertSame(500, $result['status_code']);
    }

    public function testSendEventMarksNonRetryableErrors(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');
        $this->json->method('serialize')->willReturn('{"events":[],"site_uuid":"site-uuid"}');

        $this->curl->method('getStatus')->willReturn(400);
        $this->curl->method('getBody')->willReturn('bad');

        $result = $this->client->sendEvent('$purchase', ['email' => 'a@b.com']);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
        $this->assertSame(400, $result['status_code']);
    }

    public function testSendEventReturnsRetryableOnException(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');
        $this->json->method('serialize')->willReturn('{"events":[],"site_uuid":"site-uuid"}');

        $this->curl->method('post')->willThrowException(new \Exception('network'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->client->sendEvent('$purchase', ['email' => 'a@b.com']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
        $this->assertStringContainsString('network', $result['message']);
    }

    public function testTestConnectionReturnsAuthFailures(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24T00:00:00Z');
        $this->json->method('serialize')->willReturn('{"events":[],"site_uuid":"site-uuid"}');

        $this->curl->method('getStatus')->willReturn(401);
        $this->curl->method('getBody')->willReturn('unauthorized');

        $result = $this->client->testConnection(1);

        $this->assertFalse($result['success']);
        $this->assertSame(401, $result['status_code']);
        $this->assertStringContainsString('Authentication failed', $result['message']);
    }

    /**
     * @dataProvider retryableStatusCodeProvider
     */
    public function testSendEventRetryableStatusCodes(int $statusCode): void
    {
        $this->setUpSendEventMocks();
        $this->curl->method('getStatus')->willReturn($statusCode);
        $this->curl->method('getBody')->willReturn('error');

        $result = $this->client->sendEvent('$purchase', ['email' => 'a@b.com']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['retryable']);
        $this->assertSame($statusCode, $result['status_code']);
    }

    public static function retryableStatusCodeProvider(): array
    {
        return [
            '429 Rate Limit' => [429],
            '500 Server Error' => [500],
            '502 Bad Gateway' => [502],
            '503 Service Unavailable' => [503],
            '504 Gateway Timeout' => [504],
        ];
    }

    /**
     * @dataProvider nonRetryableStatusCodeProvider
     */
    public function testSendEventNonRetryableStatusCodes(int $statusCode): void
    {
        $this->setUpSendEventMocks();
        $this->curl->method('getStatus')->willReturn($statusCode);
        $this->curl->method('getBody')->willReturn('error');

        $result = $this->client->sendEvent('$purchase', ['email' => 'a@b.com']);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['retryable']);
        $this->assertSame($statusCode, $result['status_code']);
    }

    public static function nonRetryableStatusCodeProvider(): array
    {
        return [
            '400 Bad Request' => [400],
            '401 Unauthorized' => [401],
            '403 Forbidden' => [403],
            '404 Not Found' => [404],
            '422 Unprocessable' => [422],
        ];
    }

    public function testSendEventExtractsEmailFromCustomerKey(): void
    {
        $this->setUpSendEventMocks();
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"status":"ok"}');

        $result = $this->client->sendEvent('$purchase', [
            'customer' => ['email' => 'customer@example.com', 'firstname' => 'John']
        ]);

        $this->assertTrue($result['success']);
    }

    public function testSendEventExtractsEmailFromSubscriberKey(): void
    {
        $this->setUpSendEventMocks();
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"status":"ok"}');

        $result = $this->client->sendEvent('$subscribe', [
            'subscriber' => ['email' => 'sub@example.com', 'firstname' => 'Jane']
        ]);

        $this->assertTrue($result['success']);
    }

    public function testSendEventThrowsMissingEmailWhenNoEmailAnywhere(): void
    {
        $this->setUpSendEventMocks();

        $this->expectException(MissingEmailException::class);

        $this->client->sendEvent('$purchase', ['order' => ['id' => 1]]);
    }

    public function testSendEventUsesOrderIncrementIdAsUniqueKey(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $capturedPayload = null;
        $this->json->method('serialize')->willReturnCallback(function ($data) use (&$capturedPayload) {
            $capturedPayload = $data;
            return json_encode($data);
        });

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"status":"ok"}');

        $this->client->sendEvent('$purchase', [
            'email' => 'test@example.com',
            'order' => ['increment_id' => '100000123']
        ]);

        $this->assertNotNull($capturedPayload);
        $event = $capturedPayload['events'][0];
        $this->assertSame('100000123', $event['details']['unique']['key']);
    }

    public function testSendEventIncludesFinancialDataInDetails(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $capturedPayload = null;
        $this->json->method('serialize')->willReturnCallback(function ($data) use (&$capturedPayload) {
            $capturedPayload = $data;
            return json_encode($data);
        });

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"status":"ok"}');

        $this->client->sendEvent('$purchase', [
            'email' => 'test@example.com',
            'financials' => ['total_value' => 9999, 'currency_code' => 'INR']
        ]);

        $event = $capturedPayload['events'][0];
        $this->assertSame(9999, $event['details']['value']['amount']);
        $this->assertSame('INR', $event['details']['value']['currency']);
    }

    public function testSendEventIncludesSubscriberFieldsFromCustomerData(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $capturedPayload = null;
        $this->json->method('serialize')->willReturnCallback(function ($data) use (&$capturedPayload) {
            $capturedPayload = $data;
            return json_encode($data);
        });

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"status":"ok"}');

        $this->client->sendEvent('$purchase', [
            'customer' => ['email' => 'a@b.com', 'firstname' => 'John', 'lastname' => 'Doe'],
            'tags' => ['lead', 'vip']
        ]);

        $event = $capturedPayload['events'][0];
        $this->assertSame('John', $event['fields']['first_name']);
        $this->assertSame('Doe', $event['fields']['last_name']);
        $this->assertSame('lead,vip', $event['fields']['tags']);
    }

    public function testSendEventIncludesRecoveryUrlInDetails(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $capturedPayload = null;
        $this->json->method('serialize')->willReturnCallback(function ($data) use (&$capturedPayload) {
            $capturedPayload = $data;
            return json_encode($data);
        });

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"status":"ok"}');

        $this->client->sendEvent('$abandoned', [
            'email' => 'a@b.com',
            'recovery_url' => 'https://example.com/recover?token=abc'
        ]);

        $event = $capturedPayload['events'][0];
        $this->assertSame('https://example.com/recover?token=abc', $event['details']['recovery_url']);
    }

    public function testSendEventIncludesNestedRecoveryCartUrlInDetails(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $capturedPayload = null;
        $this->json->method('serialize')->willReturnCallback(function ($data) use (&$capturedPayload) {
            $capturedPayload = $data;
            return json_encode($data);
        });

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{\"status\":\"ok\"}');

        $this->client->sendEvent('$abandoned', [
            'email' => 'a@b.com',
            'recovery' => [
                'cart_url' => 'https://example.com/recover?token=nested'
            ]
        ]);

        $event = $capturedPayload['events'][0];
        $this->assertSame('https://example.com/recover?token=nested', $event['details']['recovery_url']);
    }

    public function testSendEventUsesShipmentIncrementIdAsUniqueKey(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $capturedPayload = null;
        $this->json->method('serialize')->willReturnCallback(function ($data) use (&$capturedPayload) {
            $capturedPayload = $data;
            return json_encode($data);
        });

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{\"status\":\"ok\"}');

        $this->client->sendEvent('$OrderShipped', [
            'email' => 'a@b.com',
            'order' => ['increment_id' => '100000123'],
            'shipment' => ['increment_id' => '000000501']
        ]);

        $event = $capturedPayload['events'][0];
        $this->assertSame('shipment:000000501', $event['details']['unique']['key']);
    }

    public function testSendEventUsesRefundIncrementIdAsUniqueKey(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $capturedPayload = null;
        $this->json->method('serialize')->willReturnCallback(function ($data) use (&$capturedPayload) {
            $capturedPayload = $data;
            return json_encode($data);
        });

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{\"status\":\"ok\"}');

        $this->client->sendEvent('$OrderRefunded', [
            'email' => 'a@b.com',
            'order' => ['increment_id' => '100000123'],
            'refund' => ['increment_id' => '000000701']
        ]);

        $event = $capturedPayload['events'][0];
        $this->assertSame('refund:000000701', $event['details']['unique']['key']);
    }

    public function testTestConnectionReturnsDisabledWhenModuleOff(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $result = $this->client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame('Bento integration is disabled', $result['message']);
    }

    public function testTestConnectionReturnsErrorWhenMissingPublishableKey(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('uuid');
        $this->config->method('getPublishableKey')->willReturn(null);

        $result = $this->client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Publishable Key', $result['message']);
    }

    public function testTestConnectionReturnsErrorWhenMissingSecretKey(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn(null);

        $result = $this->client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Secret Key', $result['message']);
    }

    public function testTestConnectionReturnsSuccessOn2xx(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24T00:00:00Z');
        $this->json->method('serialize')->willReturn('{}');

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('ok');

        $result = $this->client->testConnection();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('response_time_ms', $result);
    }

    public function testTestConnectionReturns403ForbiddenMessage(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24T00:00:00Z');
        $this->json->method('serialize')->willReturn('{}');

        $this->curl->method('getStatus')->willReturn(403);
        $this->curl->method('getBody')->willReturn('forbidden');

        $result = $this->client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Access forbidden', $result['message']);
    }

    public function testTestConnectionHandlesNetworkException(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->dateTime->method('gmtDate')->willReturn('2026-01-24T00:00:00Z');
        $this->json->method('serialize')->willReturn('{}');

        $this->curl->method('post')->willThrowException(new \Exception('Connection timed out'));

        $result = $this->client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection timed out', $result['message']);
    }

    public function testSendEventMergesCartDataWithItems(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');

        $capturedPayload = null;
        $this->json->method('serialize')->willReturnCallback(function ($data) use (&$capturedPayload) {
            $capturedPayload = $data;
            return json_encode($data);
        });

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('ok');

        $this->client->sendEvent('$purchase', [
            'email' => 'a@b.com',
            'cart' => ['quote_id' => 5],
            'items' => [['sku' => 'ABC']]
        ]);

        $event = $capturedPayload['events'][0];
        // cart already has items key? No — cart has quote_id, items go into cart.items
        $this->assertSame(5, $event['details']['cart']['quote_id']);
        $this->assertSame([['sku' => 'ABC']], $event['details']['cart']['items']);
    }

    private function setUpSendEventMocks(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-uuid');
        $this->config->method('getPublishableKey')->willReturn('pub');
        $this->config->method('getSecretKey')->willReturn('sec');
        $this->config->method('getSource')->willReturn('artlounge');
        $this->config->method('getTimeout')->willReturn(30);
        $this->dateTime->method('gmtDate')->with('c')->willReturn('2026-01-24T00:00:00Z');
        $this->json->method('serialize')->willReturn('{"events":[],"site_uuid":"site-uuid"}');
    }
}
