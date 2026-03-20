<?php
/**
 * Abandoned Cart Checker
 *
 * Verifies if a cart is still abandoned and triggers the event.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\AbandonedCart;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use ArtLounge\BentoEvents\Model\Outbox\Writer as OutboxWriter;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;

class Checker
{
    private const EVENT_NAME = 'bento.cart.abandoned';
    private const QUEUE_TOPIC = 'event.trigger';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer,
        private readonly Scheduler $scheduler,
        private readonly OutboxWriter $outboxWriter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check if cart is abandoned and trigger event
     *
     * @param int $quoteId
     * @param string|null $originalUpdatedAt
     * @return bool True if event was triggered
     */
    public function checkAndTrigger(int $quoteId, ?string $originalUpdatedAt = null): bool
    {
        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (\Exception $e) {
            $this->logger->debug('Quote not found for abandoned cart check', [
                'quote_id' => $quoteId
            ]);
            $this->scheduler->markProcessed($quoteId, 'not_found');
            return false;
        }

        $storeId = (int)$quote->getStoreId();

        // Check if quote is still active (not converted to order)
        if (!$quote->getIsActive()) {
            $this->scheduler->markProcessed($quoteId, 'converted');
            return false;
        }

        // Check if quote was modified since scheduled (customer returned)
        if ($originalUpdatedAt && $quote->getUpdatedAt() !== $originalUpdatedAt) {
            // Reschedule with new timestamp
            $this->scheduler->scheduleCheck($quote);
            return false;
        }

        // Check if an order already exists for this quote
        if ($this->hasOrderForQuote($quoteId)) {
            $this->scheduler->markProcessed($quoteId, 'ordered');
            return false;
        }

        // Check for duplicate prevention - verify not already sent
        if ($this->config->preventDuplicates($storeId)) {
            $windowHours = $this->config->getAbandonedCartDuplicateWindow($storeId);
            $normalizedEmail = strtolower(trim((string)$quote->getCustomerEmail()));

            if (
                $windowHours > 0
                && $normalizedEmail !== ''
                && $this->scheduler->hasRecentSentForCustomer($normalizedEmail, $storeId, $windowHours)
            ) {
                $this->logger->debug('Abandoned cart suppressed by duplicate window', [
                    'quote_id' => $quoteId,
                    'email' => $normalizedEmail,
                    'window_hours' => $windowHours
                ]);
                $this->scheduler->markProcessed($quoteId, 'duplicate_window');
                return false;
            }
            if ($this->scheduler->isAlreadySent($quoteId)) {
                $this->logger->debug('Abandoned cart already sent, skipping', [
                    'quote_id' => $quoteId
                ]);
                $this->scheduler->markProcessed($quoteId, 'duplicate_window');
                return false;
            }
        }

        // Check email is present (required by Bento API)
        if (empty($quote->getCustomerEmail())) {
            $this->logger->debug('Quote has no email, cannot send abandoned cart event', [
                'quote_id' => $quoteId
            ]);
            $this->scheduler->markProcessed($quoteId, 'no_email');
            return false;
        }

        // All checks passed - trigger the abandoned cart event
        try {
            $arguments = $this->serializer->serialize(['id' => $quoteId]);

            $this->publisher->publish(
                self::QUEUE_TOPIC,
                [self::EVENT_NAME, $arguments]
            );

            $this->scheduler->markProcessed($quoteId, 'sent');

            $this->config->debug('Abandoned cart event triggered', [
                'quote_id' => $quoteId,
                'email' => $quote->getCustomerEmail(),
                'grand_total' => $quote->getGrandTotal()
            ], $storeId);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to trigger abandoned cart event', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage()
            ]);
            $this->outboxWriter->save(
                self::EVENT_NAME,
                $arguments ?? '{}',
                $storeId
            );
            $this->scheduler->markProcessed($quoteId, 'error');
            return false;
        }
    }

    /**
     * Check if an order exists for the quote
     */
    private function hasOrderForQuote(int $quoteId): bool
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('quote_id', $quoteId)
                ->setPageSize(1)
                ->create();

            $orders = $this->orderRepository->getList($searchCriteria);

            return $orders->getTotalCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
