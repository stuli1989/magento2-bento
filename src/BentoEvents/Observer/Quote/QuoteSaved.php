<?php
/**
 * Quote Saved Observer
 *
 * Schedules abandoned cart check and publishes checkout started event when quote is saved.
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
    private const EVENT_NAME = 'bento.checkout.started';
    private const QUEUE_TOPIC = 'event.trigger';
    private const CHECKOUT_EVENT_TYPE = '$checkoutStarted';

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

        if (!$this->isQuoteBasicEligible($quote)) {
            return;
        }

        // Abandoned cart scheduling
        if ($this->config->isAbandonedCartEnabled($storeId) && $this->isAbandonedCartEligible($quote, $storeId)) {
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
        if ($this->isCheckoutContext()
            && $this->config->isEnabled($storeId)
            && $this->config->isTrackCheckoutEnabled($storeId)
            && !empty($quote->getCustomerEmail())
        ) {
            $quoteId = (int)$quote->getId();
            $inserted = $this->eventDeduplicator->tryMarkSent($quoteId, self::CHECKOUT_EVENT_TYPE);
            if ($inserted) {
                $arguments = $this->serializer->serialize(['id' => $quoteId]);
                try {
                    $this->publisher->publish(self::QUEUE_TOPIC, [self::EVENT_NAME, $arguments]);

                    $this->config->debug('Checkout started event queued', [
                        'quote_id' => $quoteId,
                    ], $storeId);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to queue checkout event', [
                        'quote_id' => $quoteId,
                        'error' => $e->getMessage()
                    ]);
                    $this->outboxWriter->save(self::EVENT_NAME, $arguments, $storeId);
                }
            }
        }
    }

    /**
     * Shared guards: active quote with items.
     */
    private function isQuoteBasicEligible(Quote $quote): bool
    {
        return $quote->getIsActive() && $quote->getItemsCount() >= 1;
    }

    /**
     * Abandoned-cart-specific guards (min value, email requirement, excluded groups).
     */
    private function isAbandonedCartEligible(Quote $quote, int $storeId): bool
    {
        if ($this->config->isAbandonedCartEmailRequired($storeId)) {
            if (empty($quote->getCustomerEmail())) {
                return false;
            }
        }

        $minValue = $this->config->getAbandonedCartMinValue($storeId);
        if ((float)$quote->getGrandTotal() < $minValue) {
            return false;
        }

        $excludedGroups = $this->config->getExcludedCustomerGroups($storeId);
        if (!empty($excludedGroups) && in_array((int)$quote->getCustomerGroupId(), $excludedGroups)) {
            return false;
        }

        return true;
    }

    private function isCheckoutContext(): bool
    {
        try {
            $moduleName = $this->request->getModuleName();
            if ($moduleName === 'checkout') {
                return true;
            }

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
}
