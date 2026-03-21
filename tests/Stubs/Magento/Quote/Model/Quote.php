<?php

declare(strict_types=1);

namespace Magento\Quote\Model;

class Quote
{
    public function getId() { return 0; }
    public function getStoreId() { return 0; }
    public function getIsActive() { return true; }
    public function getItemsCount() { return 0; }
    public function getCustomerEmail() { return null; }
    public function getGrandTotal() { return 0.0; }
    public function getCustomerGroupId() { return 0; }
    public function getUpdatedAt() { return null; }
    public function getCreatedAt() { return null; }
    public function getCustomerId() { return null; }
    public function getCustomerFirstname() { return null; }
    public function getCustomerLastname() { return null; }
    public function getSubtotal() { return 0.0; }
    public function getCurrency() { return null; }
    public function isVirtual() { return false; }
    public function getShippingAddress() { return null; }
    public function getBillingAddress() { return null; }
    public function getCustomerIsGuest() { return true; }
    public function getAllVisibleItems() { return []; }
    public function getAllItems() { return []; }
}
