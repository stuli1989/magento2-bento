<?php
declare(strict_types=1);

namespace ArtLounge\BentoTracking\CustomerData;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;

class BentoSection implements SectionSourceInterface
{
    public function __construct(
        private readonly CurrentCustomer $currentCustomer,
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    public function getSectionData(): array
    {
        $data = [];

        // Include quote_id for cart lifecycle tracking (cart_id in Bento events)
        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote && $quote->getId()) {
                $data['quote_id'] = (int)$quote->getId();
            }
        } catch (\Exception $e) {
            // No active quote
        }

        $customerId = $this->currentCustomer->getCustomerId();
        if (!$customerId) {
            return $data;
        }

        try {
            $customer = $this->currentCustomer->getCustomer();
            $data['email'] = $customer->getEmail();
        } catch (\Exception $e) {
            // Customer not available
        }

        return $data;
    }
}
