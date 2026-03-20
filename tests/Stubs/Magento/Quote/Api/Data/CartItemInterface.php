<?php

declare(strict_types=1);

namespace Magento\Quote\Api\Data;

interface CartItemInterface
{
    public function getItemId();
    public function getProductId();
    public function getSku();
    public function getName();
    public function getQty();
    public function getPrice();
    public function getRowTotal();
}
