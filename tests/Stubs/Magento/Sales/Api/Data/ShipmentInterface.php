<?php

declare(strict_types=1);

namespace Magento\Sales\Api\Data;

interface ShipmentInterface
{
    public function getTracks();
    public function getItems();
    public function getEntityId();
    public function getIncrementId();
    public function getCreatedAt();
    public function getStoreId();
    public function getOrderId();
}
