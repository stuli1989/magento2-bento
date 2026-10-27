<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;

class QuoteSnapshotLoader
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CatalogLookup $catalog,
        private readonly ResourceConnection $resource
    ) {
    }

    public function load(int $quoteId): ?array
    {
        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (NoSuchEntityException) {
            return null;
        }
        $storeId = (int)$quote->getStoreId();
        $visible = $quote->getAllVisibleItems();
        $variants = [];
        foreach ($visible as $item) {
            if ($item->getProductType() === 'configurable') {
                foreach ((array)$item->getChildren() as $child) {
                    $variants[(int)$item->getItemId()] = (int)$child->getProductId();
                    break;
                }
            }
        }
        $catalog = $this->catalog->forProducts(
            array_merge(array_map(static fn ($i) => (int)$i->getProductId(), $visible), array_values($variants)),
            $storeId
        );

        $items = [];
        foreach ($visible as $item) {
            $facts = $catalog[(int)$item->getProductId()] ?? [];
            // The chosen variant's picture first (e.g. the colour bought), else the parent's.
            $variantImage = $catalog[$variants[(int)$item->getItemId()] ?? 0]['image'] ?? null;
            $items[] = [
                'item_id' => (int)$item->getItemId(),
                'product_id' => (int)$item->getProductId(),
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (float)$item->getQty(),
                'price' => (float)$item->getPrice(),
                'row_total' => (float)$item->getRowTotal(),
                'url' => $facts['url'] ?? null,
                'image' => $variantImage ?? $facts['image'] ?? null,
                'brand' => $facts['brand'] ?? null,
                'categories' => $facts['categories'] ?? [],
            ];
        }

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $billing = $quote->getBillingAddress();
        $conn = $this->resource->getConnection();
        $hasOrder = (bool)$conn->fetchOne(
            $conn->select()->from($this->resource->getTableName('sales_order'), ['entity_id'])
                ->where('quote_id = ?', $quoteId)->limit(1)
        );

        return [
            'quote_id' => (int)$quote->getId(),
            'store_id' => $storeId,
            'is_active' => (bool)$quote->getIsActive(),
            'items_count' => count($items),
            'has_order' => $hasOrder,
            'created_at' => $quote->getCreatedAt(),
            'updated_at' => $quote->getUpdatedAt(),
            'currency' => $quote->getQuoteCurrencyCode() ?: 'INR',
            'grand_total' => (float)$quote->getGrandTotal(),
            'subtotal' => (float)$quote->getSubtotal(),
            'discount_amount' => $address ? abs((float)$address->getDiscountAmount()) : 0.0,
            'shipping_amount' => $address ? (float)$address->getShippingAmount() : 0.0,
            'email' => $quote->getCustomerEmail() ? strtolower((string)$quote->getCustomerEmail()) : null,
            'firstname' => $quote->getCustomerFirstname() ?: $billing?->getFirstname(),
            'lastname' => $quote->getCustomerLastname() ?: $billing?->getLastname(),
            'phone' => $billing?->getTelephone() ?: $address?->getTelephone(),
            'customer_id' => $quote->getCustomerId() ? (int)$quote->getCustomerId() : null,
            'items' => $items,
        ];
    }
}
