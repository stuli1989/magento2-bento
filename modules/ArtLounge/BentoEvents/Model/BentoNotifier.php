<?php
/**
 * Bento Notifier
 *
 * Custom notifier for Aligent Async Events that sends events to Bento API.
 * This bridges the async events framework with the BentoClient.
 *
 * Important: Aligent's async events framework retries ANY failure (success=false)
 * until max deaths. To prevent infinite retries of permanent failures (400 errors,
 * missing email, etc.), we return success=true for non-retryable failures but
 * include detailed failure information in the response data.
 *
 * Note on data flow: Aligent's AsyncEventTriggerHandler runs service output through
 * Magento's ServiceOutputProcessor, which strips associative keys from arrays
 * (convertValue iterates values only). The EventDispatcher then wraps this in
 * ['data' => $flattenedOutput]. To get proper associative data for BentoClient,
 * we use ServiceDataResolver to re-invoke the service method with the entity ID.
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoEvents
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model;

use Aligent\AsyncEvents\Api\Data\AsyncEventInterface;
use Aligent\AsyncEvents\Helper\NotifierResult;
use Aligent\AsyncEvents\Helper\NotifierResultFactory;
use Aligent\AsyncEvents\Service\AsyncEvent\NotifierInterface;
use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoCore\Model\MissingEmailException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class BentoNotifier implements NotifierInterface
{
    public function __construct(
        private readonly BentoClientInterface $bentoClient,
        private readonly ConfigInterface $config,
        private readonly EventTypeMapper $eventTypeMapper,
        private readonly ServiceDataResolver $serviceDataResolver,
        private readonly NotifierResultFactory $notifierResultFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send event to Bento API
     *
     * @param AsyncEventInterface $asyncEvent The subscription/event configuration
     * @param array $data The formatted event data from service class
     * @return NotifierResult
     */
    public function notify(AsyncEventInterface $asyncEvent, array $data): NotifierResult
    {
        /** @var NotifierResult $result */
        $result = $this->notifierResultFactory->create();
        $result->setSubscriptionId($asyncEvent->getSubscriptionId());
        $result->setAsyncEventData($data);

        $storeId = $asyncEvent->getStoreId();
        $asyncEventName = $asyncEvent->getEventName();

        // Generate UUID for tracking
        $uuid = Uuid::uuid4()->toString();
        $result->setUuid($uuid);

        // Check if module is enabled
        if (!$this->config->isEnabled($storeId)) {
            // Module disabled is a permanent state - don't retry
            return $this->createPermanentFailureResult(
                $result,
                'Bento integration is disabled',
                'module_disabled'
            );
        }

        // Map async event name to Bento event type
        $bentoEventType = $this->eventTypeMapper->getBentoEventType($asyncEventName);

        $this->logger->debug('BentoNotifier processing event', [
            'async_event' => $asyncEventName,
            'bento_event_type' => $bentoEventType,
            'subscription_id' => $asyncEvent->getSubscriptionId(),
            'store_id' => $storeId,
            'uuid' => $uuid
        ]);

        try {
            // Aligent's EventDispatcher wraps service output in ['data' => $output].
            // That output has been through ServiceOutputProcessor which strips
            // associative keys. Re-fetch original associative data from the service.
            $flatData = $data['data'] ?? $data;
            $eventData = $this->serviceDataResolver->resolve($asyncEventName, $flatData);

            // Send to Bento via BentoClient
            $response = $this->bentoClient->sendEvent($bentoEventType, $eventData, $storeId);

            return $this->handleBentoResponse(
                $result,
                $response,
                $asyncEventName,
                $bentoEventType,
                $uuid
            );

        } catch (MissingEmailException $e) {
            // Missing email is a permanent failure - data is invalid, retrying won't help
            $this->logger->error('BentoNotifier: Missing email - permanent failure, will not retry', [
                'async_event' => $asyncEventName,
                'uuid' => $uuid,
                'message' => $e->getMessage()
            ]);

            return $this->createPermanentFailureResult(
                $result,
                $e->getMessage(),
                'missing_email'
            );

        } catch (\Exception $e) {
            // Generic exceptions are treated as retryable (network issues, etc.)
            $this->logger->error('BentoNotifier exception - will retry', [
                'async_event' => $asyncEventName,
                'uuid' => $uuid,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);

            $result->setSuccess(false);
            $result->setResponseData(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'retryable' => true
            ]) ?: '');

            return $result;
        }
    }

    /**
     * Handle Bento API response and determine if retry is appropriate
     *
     * @param NotifierResult $result
     * @param array $response
     * @param string $asyncEventName
     * @param string $bentoEventType
     * @param string $uuid
     * @return NotifierResult
     */
    private function handleBentoResponse(
        NotifierResult $result,
        array $response,
        string $asyncEventName,
        string $bentoEventType,
        string $uuid
    ): NotifierResult {
        $success = $response['success'] ?? false;
        $retryable = $response['retryable'] ?? true;

        if ($success) {
            // Actual success
            $this->logger->info('Bento event sent successfully', [
                'async_event' => $asyncEventName,
                'bento_event_type' => $bentoEventType,
                'uuid' => $uuid,
                'bento_uuid' => $response['uuid'] ?? null
            ]);

            $result->setSuccess(true);
            $result->setResponseData(json_encode($response) ?: '');

            return $result;
        }

        // Failure case - check if retryable
        if ($retryable) {
            // Retryable failure (429, 5xx, network errors)
            $this->logger->warning('Bento event delivery failed - will retry', [
                'async_event' => $asyncEventName,
                'bento_event_type' => $bentoEventType,
                'uuid' => $uuid,
                'message' => $response['message'] ?? 'Unknown error',
                'status_code' => $response['status_code'] ?? null
            ]);

            $result->setSuccess(false);
            $result->setResponseData(json_encode($response) ?: '');

            return $result;
        }

        // Non-retryable failure (400, 401, 403, etc.)
        // Return success=true to prevent Aligent from retrying, but log the actual failure
        $this->logger->error('Bento event delivery failed - PERMANENT FAILURE, will not retry', [
            'async_event' => $asyncEventName,
            'bento_event_type' => $bentoEventType,
            'uuid' => $uuid,
            'message' => $response['message'] ?? 'Unknown error',
            'status_code' => $response['status_code'] ?? null,
            'response' => $response['response'] ?? null
        ]);

        return $this->createPermanentFailureResult(
            $result,
            $response['message'] ?? 'Unknown error',
            'http_' . ($response['status_code'] ?? 'unknown'),
            $response
        );
    }

    /**
     * Create a result for permanent failures
     *
     * Returns success=true to prevent Aligent retries, but includes detailed
     * failure information for audit/debugging purposes.
     *
     * @param NotifierResult $result
     * @param string $message
     * @param string $failureCode
     * @param array|null $originalResponse
     * @return NotifierResult
     */
    private function createPermanentFailureResult(
        NotifierResult $result,
        string $message,
        string $failureCode,
        ?array $originalResponse = null
    ): NotifierResult {
        // Set success=true to prevent Aligent from retrying
        // The actual failure is recorded in responseData
        $result->setSuccess(true);
        $result->setResponseData(json_encode([
            'success' => false,
            'permanent_failure' => true,
            'failure_code' => $failureCode,
            'message' => $message,
            'retryable' => false,
            'original_response' => $originalResponse,
            'note' => 'Marked as success to prevent retry - see failure_code and message for actual status'
        ]) ?: '');

        return $result;
    }
}
