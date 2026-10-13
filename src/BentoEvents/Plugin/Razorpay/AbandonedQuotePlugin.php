<?php
/**
 * After Razorpay\Magento\Controller\OneClick\AbandonedQuote::execute succeeds (popup dismissed;
 * Razorpay has written a pending_payment order with the buyer's email/phone/address)
 * → $checkout_abandoned (source "dismissed"). Cancels any pending contact-capture timeout
 * for the same attempt. Never throws.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Plugin\Razorpay;

use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Razorpay\CheckoutContextInterface;
use ArtLounge\BentoEvents\Model\Razorpay\ResultReader;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Controller\OneClick\AbandonedQuote;

class AbandonedQuotePlugin
{
    public function __construct(
        private readonly CheckoutContextInterface $context,
        private readonly ResultReader $reader,
        private readonly Enqueuer $enqueuer,
        private readonly ContactStore $contacts,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Shared with the contact-capture timeout so one checkout attempt yields one event. */
    public static function dedupKey(int $quoteId, string $rzpOrderId): string
    {
        return 'checkout_abandoned:' . $quoteId . ':' . $rzpOrderId;
    }

    public function afterExecute(AbandonedQuote $subject, $result)
    {
        try {
            $response = $this->reader->read($result);
            if ($response['code'] !== 200 || ($response['data']['status'] ?? '') !== 'success') {
                return $result;
            }
            $rzpOrderId = (string)$subject->getRequest()->getParam('rzp_order_id', '');
            if ($rzpOrderId === '') {
                return $result;
            }
            $order = $this->context->orderForRzpOrder($rzpOrderId);
            if ($order === null || $order['state'] !== 'pending_payment') {
                return $result;
            }
            $this->enqueuer->enqueue(
                'checkout_abandoned',
                'order',
                (int)$order['order_id'],
                (string)$order['email'],
                (int)$order['store_id'],
                self::dedupKey((int)$order['quote_id'], $rzpOrderId),
                ['source' => 'dismissed', 'rzp_order_id' => $rzpOrderId, 'quote_id' => (int)$order['quote_id']]
            );
            $this->contacts->cancelForRzpOrder($rzpOrderId);
        } catch (\Throwable $e) {
            $this->logger->error('Bento checkout_abandoned (dismiss) capture failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }
}
