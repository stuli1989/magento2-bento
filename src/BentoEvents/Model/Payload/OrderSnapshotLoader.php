<?php
/**
 * Magento order → plain snapshot array consumed by OrderPayloadMapper.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrderSnapshotLoader
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly CatalogLookup $catalog,
        private readonly ConfigInterface $config
    ) {
    }

    /** @return array|null null when the order no longer exists */
    public function load(int $orderId): ?array
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $this->snapshot($order);
    }

    public function snapshot(OrderInterface $order): array
    {
        $storeId = (int)$order->getStoreId();
        $includeTax = $this->config->includeTaxInTotals($storeId);
        $grand = (float)$order->getGrandTotal() - ($includeTax ? 0.0 : (float)$order->getTaxAmount());

        $visible = [];
        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue; // configurable / bundle children
            }
            $visible[] = $item;
        }
        $catalog = $this->catalog->forProducts(array_map(static fn ($i) => (int)$i->getProductId(), $visible), $storeId);

        $items = [];
        foreach ($visible as $item) {
            $facts = $catalog[(int)$item->getProductId()] ?? [];
            $items[] = [
                'item_id' => (int)$item->getItemId(),
                'product_id' => (int)$item->getProductId(),
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (float)$item->getQtyOrdered(),
                'price' => (float)$item->getPrice(),
                'row_total' => (float)$item->getRowTotal(),
                'url' => $facts['url'] ?? null,
                'image' => $facts['image'] ?? null,
                'brand' => $facts['brand'] ?? null,
                'categories' => $facts['categories'] ?? [],
            ];
        }

        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        $payment = $order->getPayment();
        $paymentTitle = null;
        if ($payment) {
            $info = $payment->getAdditionalInformation();
            $paymentTitle = $info['method_title'] ?? null;
            if ($paymentTitle === null) {
                try {
                    $paymentTitle = $payment->getMethodInstance()->getTitle();
                } catch (\Throwable) {
                    $paymentTitle = null;
                }
            }
        }

        return [
            'order_id' => (int)$order->getEntityId(),
            'increment_id' => (string)$order->getIncrementId(),
            'quote_id' => (int)$order->getQuoteId(),
            'created_at' => $order->getCreatedAt(),
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'currency' => $order->getOrderCurrencyCode() ?: 'INR',
            'grand_total' => $grand,
            'subtotal' => (float)$order->getSubtotal(),
            'shipping_amount' => (float)$order->getShippingAmount(),
            'discount_amount' => abs((float)$order->getDiscountAmount()),
            'tax_amount' => (float)$order->getTaxAmount(),
            'email' => strtolower((string)$order->getCustomerEmail()),
            'firstname' => $order->getCustomerFirstname() ?: $billing?->getFirstname(),
            'lastname' => $order->getCustomerLastname() ?: $billing?->getLastname(),
            'customer_id' => $order->getCustomerId() ? (int)$order->getCustomerId() : null,
            'customer_group' => $this->groupName((int)$order->getCustomerGroupId()),
            'phone' => $billing?->getTelephone() ?: $shipping?->getTelephone(),
            'payment_method' => $payment?->getMethod(),
            'payment_title' => $paymentTitle,
            'shipping_method' => $order->getShippingMethod(),
            'shipping_title' => $order->getShippingDescription(),
            'is_virtual' => (bool)$order->getIsVirtual(),
            'billing' => $this->address($billing),
            'shipping_address' => $this->address($shipping),
            'items' => $items,
        ];
    }

    /** @return array|null creditmemo snapshot + its order snapshot under 'order' */
    public function loadCreditmemo(int $creditmemoId): ?array
    {
        try {
            $cm = $this->creditmemoRepository->get($creditmemoId);
        } catch (NoSuchEntityException) {
            return null;
        }
        $order = $this->load((int)$cm->getOrderId());
        if ($order === null) {
            return null;
        }
        $items = [];
        foreach ($cm->getItems() as $item) {
            if ($item->getOrderItem() && $item->getOrderItem()->getParentItemId()) {
                continue;
            }
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (float)$item->getQty(),
                'row_total' => (float)$item->getRowTotal(),
            ];
        }

        return [
            'creditmemo_id' => (int)$cm->getEntityId(),
            'increment_id' => (string)$cm->getIncrementId(),
            'created_at' => $cm->getCreatedAt(),
            'grand_total' => (float)$cm->getGrandTotal(),
            'adjustment_positive' => (float)$cm->getAdjustmentPositive(),
            'adjustment_negative' => (float)$cm->getAdjustmentNegative(),
            'items' => $items,
            'order' => $order,
        ];
    }

    private function address($address): ?array
    {
        if (!$address) {
            return null;
        }

        return [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
        ];
    }

    private function groupName(int $groupId): string
    {
        if ($groupId === 0) {
            return 'NOT LOGGED IN';
        }
        try {
            return (string)$this->groupRepository->getById($groupId)->getCode();
        } catch (\Throwable) {
            return 'Unknown';
        }
    }
}
