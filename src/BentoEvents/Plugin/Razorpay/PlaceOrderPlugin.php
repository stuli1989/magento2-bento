<?php
/**
 * After Razorpay\Magento\Controller\OneClick\PlaceOrder::execute succeeds (the Magic Checkout
 * popup is about to open) → $checkout_started, once per masked quote id, logged-in only.
 * Never touches or delays the response; never throws.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Plugin\Razorpay;

use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Razorpay\CheckoutContextInterface;
use ArtLounge\BentoEvents\Model\Razorpay\ResultReader;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Controller\OneClick\PlaceOrder;

class PlaceOrderPlugin
{
    public function __construct(
        private readonly CheckoutContextInterface $context,
        private readonly ResultReader $reader,
        private readonly Enqueuer $enqueuer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterExecute(PlaceOrder $subject, $result)
    {
        try {
            $response = $this->reader->read($result);
            $data = $response['data'];
            $rzpOrderId = (string)($data['rzp_order_id'] ?? '');
            if (($data['status'] ?? '') !== 'success' || $rzpOrderId === '') {
                return $result;
            }
            $email = $this->context->loggedInEmail();
            if ($email === null || $email === '') {
                return $result; // guests: Bento learns the email from contact capture / dismissal
            }
            $quoteId = $this->context->sessionQuoteIdForRzpOrder($rzpOrderId);
            if ($quoteId === null) {
                return $result;
            }
            $masked = $this->context->maskedQuoteId($quoteId) ?: (string)$quoteId;
            $this->enqueuer->enqueue(
                'checkout_started',
                'quote',
                $quoteId,
                $email,
                $this->context->storeId(),
                'checkout_started:' . $masked,
                ['rzp_order_id' => $rzpOrderId]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Bento checkout_started capture failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }
}
