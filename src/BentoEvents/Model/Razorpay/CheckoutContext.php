<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Razorpay;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Store\Model\StoreManagerInterface;

class CheckoutContext implements CheckoutContextInterface
{
    /** Session key prefix written by Razorpay\Magento\Controller\OneClick\PlaceOrder. */
    private const SESSION_KEY_PREFIX = 'quote_linked_razorpay_order_id_';

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly ResourceConnection $resource,
        private readonly QuoteIdToMaskedQuoteIdInterface $maskedQuoteId,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function loggedInEmail(): ?string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }
        $email = (string)$this->customerSession->getCustomer()->getEmail();

        return $email !== '' ? strtolower($email) : null;
    }

    public function sessionQuoteIdForRzpOrder(string $rzpOrderId): ?int
    {
        $data = $this->checkoutSession->getData();
        if (!is_array($data)) {
            return null;
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, self::SESSION_KEY_PREFIX) && $value === $rzpOrderId) {
                $masked = substr($key, strlen(self::SESSION_KEY_PREFIX));
                $conn = $this->resource->getConnection();
                $quoteId = $conn->fetchOne(
                    $conn->select()->from($this->resource->getTableName('quote_id_mask'), ['quote_id'])
                        ->where('masked_id = ?', $masked)
                );
                return $quoteId ? (int)$quoteId : null;
            }
        }

        return null;
    }

    public function maskedQuoteId(int $quoteId): ?string
    {
        try {
            $masked = $this->maskedQuoteId->execute($quoteId);
            return $masked !== '' ? $masked : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function orderForRzpOrder(string $rzpOrderId): ?array
    {
        $conn = $this->resource->getConnection();
        $row = $conn->fetchRow(
            $conn->select()
                ->from(['l' => $this->resource->getTableName('razorpay_sales_order')], [])
                ->join(
                    ['so' => $this->resource->getTableName('sales_order')],
                    'so.increment_id = l.order_id',
                    ['entity_id', 'increment_id', 'quote_id', 'customer_email', 'store_id', 'state']
                )
                ->where('l.rzp_order_id = ?', $rzpOrderId)
                ->order('so.entity_id DESC')
                ->limit(1)
        );
        if (!$row) {
            return null;
        }

        return [
            'order_id' => (int)$row['entity_id'],
            'increment_id' => (string)$row['increment_id'],
            'quote_id' => (int)$row['quote_id'],
            'email' => strtolower((string)$row['customer_email']),
            'store_id' => (int)$row['store_id'],
            'state' => (string)$row['state'],
        ];
    }

    public function storeId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }
}
