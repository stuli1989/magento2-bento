<?php

declare(strict_types=1);

namespace Magento\Sales\Api\Data;

interface ShipmentTrackInterface
{
    public function getCarrierCode();
    public function getTitle();
    public function getTrackNumber();
}
