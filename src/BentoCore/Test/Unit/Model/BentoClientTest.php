<?php

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Model;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\BentoClient;
use ArtLounge\BentoCore\Model\Http\TransportInterface;
use ArtLounge\BentoCore\Model\SendResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BentoClientTest extends TestCase
{
    private array $calls = [];
    private array $nextResponse = ['status' => 200, 'body' => '{"results":1}', 'error' => null];
    private BentoClient $client;
    private ConfigInterface $config;

    protected function setUp(): void
    {
        $transport = new class ($this) implements TransportInterface {
            public function __construct(private BentoClientTest $test)
            {
            }

            public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                return $this->test->record($method, $url, $headers, $body, $timeout);
            }
        };
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getSiteUuid')->willReturn('site-123');
        $this->config->method('getPublishableKey')->willReturn('pk');
        $this->config->method('getSecretKey')->willReturn('sk');
        $this->config->method('getTimeout')->willReturn(10);
        $this->client = new BentoClient($this->config, $transport, $this->createMock(LoggerInterface::class));
    }

    public function record(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeout');
        return $this->nextResponse;
    }

    public function testPostEventsBodyAndAuth(): void
    {
        $result = $this->client->postEvents([['type' => '$purchase', 'email' => 'a@b.c']]);

        $this->assertTrue($result->isSuccess());
        $call = $this->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://app.bentonow.com/api/v1/batch/events', $call['url']);
        $this->assertSame(10, $call['timeout']);
        $this->assertContains('Authorization: Basic ' . base64_encode('pk:sk'), $call['headers']);
        $this->assertContains('Content-Type: application/json', $call['headers']);
        $this->assertSame(
            ['events' => [['type' => '$purchase', 'email' => 'a@b.c']], 'site_uuid' => 'site-123'],
            json_decode($call['body'], true)
        );
    }

    public function testRupeeSymbolIsNotEscaped(): void
    {
        $this->client->postEvents([['type' => 'x', 'email' => 'a@b.c', 'details' => ['d' => '₹1,488.60']]]);
        $this->assertStringContainsString('₹1,488.60', $this->calls[0]['body']);
    }

    public function testImportSubscribersBody(): void
    {
        $this->client->importSubscribers([['email' => 'a@b.c', 'order_count' => 2]]);
        $call = $this->calls[0];
        $this->assertSame('https://app.bentonow.com/api/v1/batch/subscribers', $call['url']);
        $this->assertSame(
            ['subscribers' => [['email' => 'a@b.c', 'order_count' => 2]], 'site_uuid' => 'site-123'],
            json_decode($call['body'], true)
        );
    }

    /**
     * @dataProvider classification
     */
    public function testResultClassification(int $status, ?string $error, bool $success, bool $retryable, bool $permanent): void
    {
        $this->nextResponse = ['status' => $status, 'body' => 'x', 'error' => $error];
        $r = $this->client->postEvents([['type' => 'x', 'email' => 'a@b.c']]);
        $this->assertSame($success, $r->isSuccess(), 'success');
        $this->assertSame($retryable, $r->isRetryable(), 'retryable');
        $this->assertSame($permanent, $r->isPermanent(), 'permanent');
    }

    public static function classification(): array
    {
        return [
            '200' => [200, null, true, false, false],
            '201' => [201, null, true, false, false],
            '401 auth' => [401, null, false, false, true],
            '422 invalid' => [422, null, false, false, true],
            '429 rate limited' => [429, null, false, true, false],
            '500' => [500, null, false, true, false],
            '503' => [503, null, false, true, false],
            'curl timeout' => [0, 'Operation timed out', false, true, false],
        ];
    }

    public function testNotConfiguredDoesNotCallTransport(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isConfigured')->willReturn(false);
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->never())->method('request');
        $client = new BentoClient($config, $transport, $this->createMock(LoggerInterface::class));

        $r = $client->postEvents([['type' => 'x', 'email' => 'a@b.c']]);
        $this->assertFalse($r->isSuccess());
        $this->assertTrue($r->isRetryable(), 'unconfigured is not the event\'s fault — keep it pending');
    }

    public function testTestConnectionUsesReadOnlyEndpoint(): void
    {
        $r = $this->client->testConnection();
        $this->assertTrue($r['success']);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertStringStartsWith('https://app.bentonow.com/api/v1/fetch/tags?site_uuid=site-123', $this->calls[0]['url']);
    }

    public function testSendResultExposesStatusAndBody(): void
    {
        $r = new SendResult(422, '{"error":"bad"}', null);
        $this->assertSame(422, $r->getStatus());
        $this->assertSame('{"error":"bad"}', $r->getBody());
        $this->assertStringContainsString('422', $r->describe());
    }

    public function testFetchSubscriberByUuidIsAReadOnlyGet(): void
    {
        $this->nextResponse = ['status' => 200, 'body' => '{"data":{"attributes":{"email":"a@b.c"}}}', 'error' => null];

        $result = $this->client->fetchSubscriberByUuid('9f1c-uuid', 1);

        $this->assertTrue($result->isSuccess());
        $call = $this->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://app.bentonow.com/api/v1/fetch/subscribers?site_uuid=site-123&uuid=9f1c-uuid', $call['url']);
        $this->assertNull($call['body']);
        $this->assertContains('Authorization: Basic ' . base64_encode('pk:sk'), $call['headers']);
        $this->assertSame('{"data":{"attributes":{"email":"a@b.c"}}}', $result->getBody());
    }

    public function testFetchSubscriberByUuidEncodesTheUuidAndClassifiesErrors(): void
    {
        $this->nextResponse = ['status' => 0, 'body' => '', 'error' => 'timeout'];

        $result = $this->client->fetchSubscriberByUuid('a b&c', null);

        $this->assertStringEndsWith('&uuid=a%20b%26c', $this->calls[0]['url']);
        $this->assertTrue($result->isRetryable());
    }
}
