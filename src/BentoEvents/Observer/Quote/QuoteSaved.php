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
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class QuoteSaved implements ObserverInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Scheduler $scheduler,
        private readonly LoggerInterface $logger
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

        if (!$this->config->isAbandonedCartEnabled($storeId)) {
            return;
        }

        // Check basic conditions
        if (!$this->isQuoteEligible($quote, $storeId)) {
            return;
        }

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
}
