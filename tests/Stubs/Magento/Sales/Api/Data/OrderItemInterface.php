<?php

declare(strict_types=1);

namespace Magento\Sales\Api\Data;

interface OrderItemInterface
{
    public function getParentItemId();
    public function getItemId();
    public function getProductId();
    public function getSku();
    public function getName();
    public function getQtyOrdered();
    public function getPrice();
    public function getRowTotal();
}
