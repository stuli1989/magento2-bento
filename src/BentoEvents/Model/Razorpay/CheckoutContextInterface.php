<?php
/**
 * Everything the checkout plugins / contact endpoint need from the session and Razorpay
 * tables, behind one seam so the decision logic is unit-testable.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Razorpay;

interface CheckoutContextInterface
{
    /** Logged-in customer's email, null for guests. */
    public function loggedInEmail(): ?string;

    /**
     * Quote id for a Razorpay order created IN THIS SESSION (PlaceOrder stores
     * quote_linked_razorpay_order_id_<maskedQuoteId> => rzp order id in the checkout session).
     */
    public function sessionQuoteIdForRzpOrder(string $rzpOrderId): ?int;

    public function maskedQuoteId(int $quoteId): ?string;

    /**
     * The Magento order Razorpay linked to this rzp order (via razorpay_sales_order).
     * @return array{order_id:int, increment_id:string, quote_id:int, email:string, store_id:int, state:string}|null
     */
    public function orderForRzpOrder(string $rzpOrderId): ?array;

    public function storeId(): int;
}
