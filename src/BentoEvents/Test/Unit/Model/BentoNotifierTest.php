<?php
/**
 * Bento Notifier Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model;

use Aligent\AsyncEvents\Api\Data\AsyncEventInterface;
use Aligent\AsyncEvents\Helper\NotifierResult;
use Aligent\AsyncEvents\Helper\NotifierResultFactory;
use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\MissingEmailException;
use ArtLounge\BentoEvents\Model\BentoNotifier;
use ArtLounge\BentoEvents\Model\EventTypeMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BentoNotifierTest extends TestCase
{
    private BentoNotifier $notifier;
    private MockObject $bentoClient;
    private MockObject $config;
    private MockObject $mapper;
    private MockObject $resultFactory;
    private MockObject $logger;
    private NotifierResult $result;

    protected function setUp(): void
    {
        $this->bentoClient = $this->createMock(BentoClientInterface::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->mapper = $this->createMock(EventTypeMapper::class);
        $this->resultFactory = $this->createMock(NotifierResultFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->result = new class() extends NotifierResult {
            public function setSubscriptionId($id): void
            {
                $this->subscriptionId = $id;
            }

            public function setAsyncEventData($data): void
            {
                $this->asyncEventData = $data;
            }

            public function setUuid($uuid): void
            {
                $this->uuid = $uuid;
            }
        };

        $this->resultFactory
            ->method('create')
            ->willReturn($this->result);

        $this->notifier = new BentoNotifier(
            $this->bentoClient,
            $this->config,
            $this->mapper,
            $this->resultFactory,
            $this->logger
        );
    }

    public function testNotifyReturnsPermanentFailureWhenDisabled(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(false);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $this->assertTrue($result->isSuccessful());
        $response = json_decode($result->getResponseData(), true);
        $this->assertTrue($response['permanent_failure']);
        $this->assertSame('module_disabled', $response['failure_code']);
    }

    public function testNotifyReturnsPermanentFailureOnMissingEmail(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willThrowException(new MissingEmailException('Missing email'));

        $result = $this->notifier->notify($event, []);

        $this->assertTrue($result->isSuccessful());
        $response = json_decode($result->getResponseData(), true);
        $this->assertTrue($response['permanent_failure']);
        $this->assertSame('missing_email', $response['failure_code']);
    }

    public function testNotifyReturnsRetryableFailureOnException(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willThrowException(new \RuntimeException('boom'));

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $this->assertFalse($result->isSuccessful());
        $response = json_decode($result->getResponseData(), true);
        $this->assertTrue($response['retryable']);
    }

    public function testNotifyMarksNonRetryableResponseAsPermanentFailure(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => false,
                'message' => 'Bad Request',
                'status_code' => 400,
                'retryable' => false
            ]);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $this->assertTrue($result->isSuccessful());
        $response = json_decode($result->getResponseData(), true);
        $this->assertTrue($response['permanent_failure']);
        $this->assertSame('http_400', $response['failure_code']);
    }

    public function testNotifyPassesThroughRetryableResponses(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => false,
                'message' => 'Server error',
                'status_code' => 500,
                'retryable' => true
            ]);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $this->assertFalse($result->isSuccessful());
    }

    public function testNotifySuccessPersistsResponse(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => true,
                'uuid' => 'bento-uuid'
            ]);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $this->assertTrue($result->isSuccessful());
        $response = json_decode($result->getResponseData(), true);
        $this->assertTrue($response['success']);
    }

    public function testNotifyReturns401PermanentFailure(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => false,
                'message' => 'Unauthorized',
                'status_code' => 401,
                'retryable' => false
            ]);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $this->assertTrue($result->isSuccessful());
        $response = json_decode($result->getResponseData(), true);
        $this->assertTrue($response['permanent_failure']);
        $this->assertSame('http_401', $response['failure_code']);
        $this->assertFalse($response['retryable']);
    }

    public function testNotifyReturns403PermanentFailure(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => false,
                'message' => 'Forbidden',
                'status_code' => 403,
                'retryable' => false
            ]);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $this->assertTrue($result->isSuccessful());
        $response = json_decode($result->getResponseData(), true);
        $this->assertTrue($response['permanent_failure']);
        $this->assertSame('http_403', $response['failure_code']);
    }

    public function testNotifyReturns429RetryableFailure(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => false,
                'message' => 'Rate limited',
                'status_code' => 429,
                'retryable' => true
            ]);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $this->assertFalse($result->isSuccessful());
        $response = json_decode($result->getResponseData(), true);
        $this->assertArrayNotHasKey('permanent_failure', $response);
    }

    public function testNotifyPermanentFailureIncludesNote(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(false);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $response = json_decode($result->getResponseData(), true);
        $this->assertArrayHasKey('note', $response);
        $this->assertStringContainsString('prevent retry', $response['note']);
    }

    public function testNotifyPreservesOriginalResponseInPermanentFailure(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => false,
                'message' => 'Bad Request',
                'status_code' => 400,
                'retryable' => false,
                'response' => '{"error":"invalid payload"}'
            ]);

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $response = json_decode($result->getResponseData(), true);
        $this->assertArrayHasKey('original_response', $response);
        $this->assertSame(400, $response['original_response']['status_code']);
    }

    public function testNotifyLogsErrorOnMissingEmail(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willThrowException(new MissingEmailException('No email'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Missing email'),
                $this->anything()
            );

        $this->notifier->notify($event, []);
    }

    public function testNotifyLogsWarningOnRetryableFailure(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => false,
                'message' => 'Server error',
                'status_code' => 500,
                'retryable' => true
            ]);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('will retry'),
                $this->anything()
            );

        $this->notifier->notify($event, ['email' => 'test@example.com']);
    }

    public function testNotifyLogsPermanentFailureError(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willReturn([
                'success' => false,
                'message' => 'Bad Request',
                'status_code' => 400,
                'retryable' => false
            ]);

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->stringContains('PERMANENT FAILURE'),
                $this->anything()
            );

        $this->notifier->notify($event, ['email' => 'test@example.com']);
    }

    public function testNotifyMapsEventTypeCorrectly(): void
    {
        $event = $this->createAsyncEventMock('bento.newsletter.subscribed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->expects($this->once())
            ->method('getBentoEventType')
            ->with('bento.newsletter.subscribed')
            ->willReturn('$subscribe');
        $this->bentoClient
            ->expects($this->once())
            ->method('sendEvent')
            ->with('$subscribe', $this->anything(), 1)
            ->willReturn(['success' => true]);

        $this->notifier->notify($event, ['email' => 'test@example.com']);
    }

    public function testNotifyRetryableExceptionPreservesExceptionClass(): void
    {
        $event = $this->createAsyncEventMock('bento.order.placed', 1, 10);
        $this->config->method('isEnabled')->willReturn(true);
        $this->mapper->method('getBentoEventType')->willReturn('$purchase');
        $this->bentoClient
            ->method('sendEvent')
            ->willThrowException(new \RuntimeException('Network timeout'));

        $result = $this->notifier->notify($event, ['email' => 'test@example.com']);

        $response = json_decode($result->getResponseData(), true);
        $this->assertSame('RuntimeException', $response['exception']);
        $this->assertSame('Network timeout', $response['message']);
    }

    private function createAsyncEventMock(string $eventName, int $storeId, int $subscriptionId): AsyncEventInterface
    {
        $asyncEvent = $this->createMock(AsyncEventInterface::class);
        $asyncEvent->method('getSubscriptionId')->willReturn($subscriptionId);
        $asyncEvent->method('getStoreId')->willReturn($storeId);
        $asyncEvent->method('getEventName')->willReturn($eventName);

        return $asyncEvent;
    }
}
