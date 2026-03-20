<?php

declare(strict_types=1);

namespace Magento\Sales\Model;

class Order
{
    public const STATE_CANCELED = 'canceled';
    public const STATE_PROCESSING = 'processing';
    public const STATE_COMPLETE = 'complete';
    public const STATE_CLOSED = 'closed';
    public const STATE_NEW = 'new';

    public function getState() { return null; }
    public function getEntityId() { return null; }
    public function getStoreId() { return null; }
    public function getIncrementId() { return null; }
    public function getId() { return null; }
    public function dataHasChangedFor(string $field): bool { return false; }
    public function getGrandTotal() { return null; }
    public function getSubtotal() { return null; }
    public function getTaxAmount() { return null; }
    public function getShippingAmount() { return null; }
    public function getOrderCurrencyCode() { return null; }
    public function getCustomerEmail() { return null; }
    public function getCustomerFirstname() { return null; }
    public function getCustomerLastname() { return null; }
    public function getAllVisibleItems() { return []; }
    public function getItems() { return []; }
}
