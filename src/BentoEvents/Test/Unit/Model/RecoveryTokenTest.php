<?php
/**
 * Recovery Token Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\RecoveryToken;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RecoveryTokenTest extends TestCase
{
    private MockObject $config;
    private MockObject $json;
    private MockObject $dateTime;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->json = $this->createMock(Json::class);
        $this->dateTime = $this->createMock(DateTime::class);

        $this->json->method('serialize')->willReturnCallback(static fn(array $data): string => json_encode($data) ?: '{}');
        $this->json->method('unserialize')->willReturnCallback(static fn(string $json): array => json_decode($json, true) ?: []);
    }

    public function testGenerateReturnsNullForInvalidInput(): void
    {
        $tokenService = new RecoveryToken($this->config, $this->json, $this->dateTime);

        $this->assertNull($tokenService->generate(0, 'test@example.com', 1));
        $this->assertNull($tokenService->generate(10, '   ', 1));
    }

    public function testGenerateAndParseRoundTrip(): void
    {
        $this->config->method('getSecretKey')->willReturn('secret-key');
        $this->dateTime->method('gmtTimestamp')->willReturnOnConsecutiveCalls(1700000000, 1700000100);

        $tokenService = new RecoveryToken($this->config, $this->json, $this->dateTime);
        $token = $tokenService->generate(10, 'Test@Example.com', 2);

        $this->assertNotNull($token);
        $parsed = $tokenService->parse((string)$token, 2);

        $this->assertSame(10, $parsed['quote_id']);
        $this->assertSame('test@example.com', $parsed['email']);
        $this->assertSame(2, $parsed['store_id']);
    }

    public function testParseThrowsOnTamperedSignature(): void
    {
        $this->config->method('getSecretKey')->willReturn('secret-key');
        $this->dateTime->method('gmtTimestamp')->willReturnOnConsecutiveCalls(1700000000, 1700000001);

        $tokenService = new RecoveryToken($this->config, $this->json, $this->dateTime);
        $token = (string)$tokenService->generate(10, 'test@example.com', 1);

        [$payload, $sig] = explode('.', $token, 2);
        $tampered = $payload . '.' . substr($sig, 1) . 'a';

        $this->expectException(\InvalidArgumentException::class);
        $tokenService->parse($tampered, 1);
    }

    public function testParseThrowsOnExpiredToken(): void
    {
        $this->config->method('getSecretKey')->willReturn('secret-key');
        $this->dateTime->method('gmtTimestamp')->willReturnOnConsecutiveCalls(1700000000, 1700604801);

        $tokenService = new RecoveryToken($this->config, $this->json, $this->dateTime);
        $token = (string)$tokenService->generate(10, 'test@example.com', 1);

        $this->expectException(\InvalidArgumentException::class);
        $tokenService->parse($token, 1);
    }
}
