<?php
/**
 * Integration Test: Event Pipeline
 *
 * Tests the full notifier pipeline with real collaborating objects:
 * BentoNotifier → EventTypeMapper (real) → BentoClient (mock HTTP only).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Integration;

use Aligent\AsyncEvents\Api\Data\AsyncEventInterface;
use Aligent\AsyncEvents\Helper\NotifierResult;
use Aligent\AsyncEvents\Helper\NotifierResultFactory;
use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\MissingEmailException;
use ArtLounge\BentoEvents\Model\BentoNotifier;
use ArtLounge\BentoEvents\Model\EventTypeMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EventPipelineTest extends TestCase
{
    private BentoNotifier $notifier;
    private EventTypeMapper $mapper;
    private ConfigInterface $config;
    private BentoClientInterface $bentoClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->mapper = new EventTypeMapper(); // real instance
        $this->config = $this->createMock(ConfigInterface::class);
        $this->bentoClient = $this->createMock(BentoClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $resultFactory = new NotifierResultFactory(); // real instance

        $this->notifier = new BentoNotifier(
            $this->bentoClient,
            $this->config,
            $this->mapper,
            $resultFactory,
            $this->logger
        );
    }

    /**
     * Verify all 9 event types are correctly mapped through the full pipeline.
     *
     * @dataProvider eventMappingProvider
     */
    public function testEventTypeFlowsThroughPipelineCorrectly(
        string $asyncEventName,
        string $expectedBentoType
    ): void {
        $event = $this->createAsyncEvent($asyncEventName, 1);
        $this->config->method('isEnabled')->willReturn(true);

        $this->bentoClient
            ->expects($this->once())
            ->method('sendEvent')
            ->with($expectedBentoType, $this->anything(), 1)
            ->willReturn(['success' => true, 'uuid' => 'test-uuid']);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);
        $this->assertTrue($result->isSuccessful());
    }

    public static function eventMappingProvider(): array
    {
        return [
            'order placed'          => ['bento.order.placed', '$purchase'],
            'order shipped'         => ['bento.order.shipped', '$OrderShipped'],
            'order cancelled'       => ['bento.order.cancelled', '$OrderCanceled'],
            'order refunded'        => ['bento.order.refunded', '$OrderRefunded'],
            'customer created'      => ['bento.customer.created', '$Subscriber'],
            'customer updated'      => ['bento.customer.updated', '$CustomerUpdated'],
            'newsletter subscribed' => ['bento.newsletter.subscribed', '$subscribe'],
            'newsletter unsub'      => ['bento.newsletter.unsubscribed', '$unsubscribe'],
            'cart abandoned'        => ['bento.cart.abandoned', '$abandoned'],
        ];
    }

    /**
     * Test the retry classification chain: success → retryable → permanent failure.
     */
    public function testRetryClassificationChain(): void
    {
        $event = $this->createAsyncEvent('bento.order.placed', 1);
        $this->config->method('isEnabled')->willReturn(true);

        // 1. Success
        $this->bentoClient->expects($this->exactly(3))
            ->method('sendEvent')
            ->willReturnOnConsecutiveCalls(
                ['success' => true, 'uuid' => 'ok'],
                ['success' => false, 'retryable' => true, 'status_code' => 503, 'message' => 'Unavailable'],
                ['success' => false, 'retryable' => false, 'status_code' => 400, 'message' => 'Bad payload']
            );

        // Success path
        $result1 = $this->notifier->notify($event, ['email' => 'a@b.com']);
        $this->assertTrue($result1->isSuccessful());
        $resp1 = json_decode($result1->getResponseData(), true);
        $this->assertTrue($resp1['success']);

        // Retryable failure
        $result2 = $this->notifier->notify($event, ['email' => 'a@b.com']);
        $this->assertFalse($result2->isSuccessful());

        // Permanent failure (success=true to prevent Aligent retry)
        $result3 = $this->notifier->notify($event, ['email' => 'a@b.com']);
        $this->assertTrue($result3->isSuccessful());
        $resp3 = json_decode($result3->getResponseData(), true);
        $this->assertTrue($resp3['permanent_failure']);
        $this->assertSame('http_400', $resp3['failure_code']);
    }

    /**
     * Test that disabled module produces permanent failure without calling BentoClient.
     */
    public function testDisabledModuleShortCircuits(): void
    {
        $event = $this->createAsyncEvent('bento.order.placed', 1);
        $this->config->method('isEnabled')->willReturn(false);

        $this->bentoClient->expects($this->never())->method('sendEvent');

        $result = $this->notifier->notify($event, ['email' => 'a@b.com']);
        $this->assertTrue($result->isSuccessful()); // permanent failure
        $resp = json_decode($result->getResponseData(), true);
        $this->assertSame('module_disabled', $resp['failure_code']);
    }

    /**
     * Test MissingEmailException flows through correctly.
     */
    public function testMissingEmailPropagatesThroughPipeline(): void
    {
        $event = $this->createAsyncEvent('bento.customer.created', 1);
        $this->config->method('isEnabled')->willReturn(true);
        $this->bentoClient
            ->method('sendEvent')
            ->willThrowException(new MissingEmailException('No email on entity'));

        $result = $this->notifier->notify($event, []);
        $this->assertTrue($result->isSuccessful());
        $resp = json_decode($result->getResponseData(), true);
        $this->assertSame('missing_email', $resp['failure_code']);
        $this->assertStringContainsString('No email', $resp['message']);
    }

    /**
     * Test unknown event name passes through the mapper as-is.
     */
    public function testUnknownEventPassesThroughAsIs(): void
    {
        $event = $this->createAsyncEvent('some.custom.event', 1);
        $this->config->method('isEnabled')->willReturn(true);
        $this->bentoClient
            ->expects($this->once())
            ->method('sendEvent')
            ->with('some.custom.event', $this->anything(), 1)
            ->willReturn(['success' => true]);

        $this->notifier->notify($event, ['email' => 'a@b.com']);
    }

    private function createAsyncEvent(string $name, int $storeId): AsyncEventInterface
    {
        $event = $this->createMock(AsyncEventInterface::class);
        $event->method('getEventName')->willReturn($name);
        $event->method('getStoreId')->willReturn($storeId);
        $event->method('getSubscriptionId')->willReturn(1);
        return $event;
    }
}
