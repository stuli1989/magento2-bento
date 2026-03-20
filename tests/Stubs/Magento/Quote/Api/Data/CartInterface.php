<?php

declare(strict_types=1);

namespace Magento\Quote\Api\Data;

interface CartInterface
{
    public function getStoreId();
    public function getId();
    public function getCreatedAt();
    public function getUpdatedAt();
    public function getGrandTotal();
    public function getSubtotal();
    public function getSubtotalWithDiscount();
    public function getQuoteCurrencyCode();
    public function getCustomerEmail();
    public function getCustomerFirstname();
    public function getCustomerLastname();
    public function getCustomerIsGuest();
    public function getAllVisibleItems();
    public function getIsActive();
    public function getItemsCount();
    public function getCustomerGroupId();
    public function getCustomerId();
}
