<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Review;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Model\SendResult;
use ArtLounge\BentoEvents\Model\Review\SubscriberEmailResolver;
use PHPUnit\Framework\TestCase;

class SubscriberEmailResolverTest extends TestCase
{
    private function resolver(SendResult $result): SubscriberEmailResolver
    {
        $client = $this->createMock(BentoClientInterface::class);
        $client->method('fetchSubscriberByUuid')->with('abcdef12', 1)->willReturn($result);

        return new SubscriberEmailResolver($client);
    }

    public function testFoundSubscriberGivesLowerCasedEmail(): void
    {
        $body = '{"data":{"id":"1","type":"visitors","attributes":{"uuid":"abcdef12","email":"Meera+BentoTest@Example.com"}}}';
        $this->assertSame('meera+bentotest@example.com', $this->resolver(new SendResult(200, $body, null))->emailForUuid('abcdef12', 1));
    }

    public function testUnknownUuidIsNull(): void
    {
        $this->assertNull($this->resolver(new SendResult(404, '{"error":"not found"}', null))->emailForUuid('abcdef12', 1));
        $this->assertNull($this->resolver(new SendResult(200, '{"data":null}', null))->emailForUuid('abcdef12', 1));
        $this->assertNull($this->resolver(new SendResult(200, '{"data":{"attributes":{"email":""}}}', null))->emailForUuid('abcdef12', 1));
    }

    public function testTransientFailureThrowsSoTheRowIsRetried(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver(new SendResult(503, 'busy', null))->emailForUuid('abcdef12', 1);
    }

    public function testAuthFailureAlsoThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver(new SendResult(401, 'nope', null))->emailForUuid('abcdef12', 1);
    }
}
