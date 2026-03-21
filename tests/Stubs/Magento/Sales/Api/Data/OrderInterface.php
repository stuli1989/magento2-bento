<?php

declare(strict_types=1);

namespace Magento\Sales\Api\Data;

interface OrderInterface
{
    public function getEntityId();
    public function getStoreId();
    public function getQuoteId();
    public function getIncrementId();
    public function getCreatedAt();
    public function getStatus();
    public function getState();
    public function getGrandTotal();
    public function getSubtotal();
    public function getShippingAmount();
    public function getDiscountAmount();
    public function getTaxAmount();
    public function getOrderCurrencyCode();
    public function getIsVirtual();
    public function getCustomerId();
    public function getCustomerEmail();
    public function getCustomerFirstname();
    public function getCustomerLastname();
    public function getCustomerGroupId();
    public function getItems();
    public function getAllVisibleItems();
    public function getPayment();
    public function getBillingAddress();
    public function getShippingAddress();
    public function getShippingMethod();
    public function getShippingDescription();
    public function getId();
}
