<?php
/**
 * Quote Saved Observer
 *
 * Schedules abandoned cart check when quote is saved.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer\Quote;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use ArtLounge\BentoEvents\Model\EventDeduplicator;
use ArtLounge\BentoEvents\Model\Outbox\Writer as OutboxWriter;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class QuoteSaved implements ObserverInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Scheduler $scheduler,
        private readonly LoggerInterface $logger,
        private readonly EventDeduplicator $eventDeduplicator,
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer,
        private readonly OutboxWriter $outboxWriter,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var Quote $quote */
        $quote = $observer->getEvent()->getQuote();

        if (!$quote) {
            return;
        }

        $storeId = (int)$quote->getStoreId();

        // Abandoned cart scheduling (existing behavior, unchanged)
        if ($this->config->isAbandonedCartEnabled($storeId) && $this->isQuoteEligible($quote, $storeId)) {
            try {
                $this->scheduler->scheduleCheck($quote);
                $this->config->debug('Abandoned cart check scheduled', [
                    'quote_id' => $quote->getId(),
                    'email' => $quote->getCustomerEmail(),
                    'grand_total' => $quote->getGrandTotal()
                ], $storeId);
            } catch (\Exception $e) {
                $this->logger->error('Failed to schedule abandoned cart check', [
                    'quote_id' => $quote->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Checkout started event (independent of abandoned cart config)
        if ($this->isCheckoutContext() && $this->isCheckoutEligible($quote, $storeId)) {
            $quoteId = (int)$quote->getId();
            $inserted = $this->eventDeduplicator->tryMarkSent($quoteId, '$checkoutStarted');
            if ($inserted) {
                try {
                    $arguments = $this->serializer->serialize(['id' => $quoteId]);
                    $this->publisher->publish('event.trigger', ['bento.checkout.started', $arguments]);

                    $this->config->debug('Checkout started event queued', [
                        'quote_id' => $quoteId,
                    ], $storeId);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to queue checkout event', [
                        'quote_id' => $quoteId,
                        'error' => $e->getMessage()
                    ]);
                    $this->outboxWriter->save('bento.checkout.started', $arguments ?? '{}', $storeId);
                }
            }
        }
    }

    /**
     * Check if quote is eligible for abandoned cart tracking
     */
    private function isQuoteEligible(Quote $quote, int $storeId): bool
    {
        // Must be active
        if (!$quote->getIsActive()) {
            return false;
        }

        // Must have items
        if ($quote->getItemsCount() < 1) {
            return false;
        }

        // Check email requirement
        if ($this->config->isAbandonedCartEmailRequired($storeId)) {
            if (empty($quote->getCustomerEmail())) {
                return false;
            }
        }

        // Check minimum value
        $minValue = $this->config->getAbandonedCartMinValue($storeId);
        if ((float)$quote->getGrandTotal() < $minValue) {
            return false;
        }

        // Check excluded customer groups
        $excludedGroups = $this->config->getExcludedCustomerGroups($storeId);
        if (!empty($excludedGroups) && in_array((int)$quote->getCustomerGroupId(), $excludedGroups)) {
            return false;
        }

        return true;
    }

    /**
     * Detect if the current request is in a checkout context.
     */
    private function isCheckoutContext(): bool
    {
        try {
            $moduleName = $this->request->getModuleName();
            if ($moduleName === 'checkout') {
                return true;
            }

            // REST API checkout calls (shipping-information, payment-information, etc.)
            if ($moduleName === 'rest' || $moduleName === 'webapi_rest') {
                $uri = (string)$this->request->getRequestUri();
                if (str_contains($uri, '/carts/') || str_contains($uri, '/guest-carts/')) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if quote is eligible for checkout tracking.
     * Lighter guards than abandoned cart — no min value or excluded groups.
     */
    private function isCheckoutEligible(Quote $quote, int $storeId): bool
    {
        if (!$this->config->isEnabled($storeId)) {
            return false;
        }

        if (!$this->config->isTrackCheckoutEnabled($storeId)) {
            return false;
        }

        if (!$quote->getIsActive()) {
            return false;
        }

        if ($quote->getItemsCount() < 1) {
            return false;
        }

        if (empty($quote->getCustomerEmail())) {
            return false;
        }

        return true;
    }
}
