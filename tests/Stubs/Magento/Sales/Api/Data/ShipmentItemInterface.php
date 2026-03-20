<?php

declare(strict_types=1);

namespace Magento\Sales\Api\Data;

interface ShipmentItemInterface
{
    public function getSku();
    public function getName();
    public function getQty();
}
