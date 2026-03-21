<?php

declare(strict_types=1);

namespace Magento\Sales\Api\Data;

interface CreditmemoInterface
{
    public function getItems();
    public function getEntityId();
    public function getIncrementId();
    public function getCreatedAt();
    public function getGrandTotal();
    public function getAdjustmentPositive();
    public function getAdjustmentNegative();
    public function getOrderId();
    public function getStoreId();
}
