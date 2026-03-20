<?php

declare(strict_types=1);

namespace Magento\Sales\Api\Data;

interface OrderPaymentInterface
{
    public function getMethod();
    public function getMethodInstance();
    public function getTitle();
}
