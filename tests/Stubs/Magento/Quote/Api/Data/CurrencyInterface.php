<?php

declare(strict_types=1);

namespace Magento\Quote\Api\Data;

interface CurrencyInterface
{
    public function getQuoteCurrencyCode();
    public function getBaseCurrencyCode();
}
