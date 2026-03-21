<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Service;

use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

class CheckoutService
{
    public function __construct(
        private readonly AbandonedCartService $abandonedCartService,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get checkout started data for async event.
     *
     * Delegates to AbandonedCartService for payload assembly,
     * then overrides event_type and removes abandoned-cart-specific fields.
     *
     * @param int $id Quote ID
     * @return array
     */
    public function getCheckoutStartedData(int $id): array
    {
        try {
            $quote = $this->cartRepository->get($id);
            $data = $this->abandonedCartService->formatAbandonedCartData($quote);

            $data['event_type'] = '$checkoutStarted';

            // Remove abandoned-cart-specific field
            unset($data['cart']['abandoned_duration_minutes']);

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get checkout started data for Bento', [
                'quote_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
