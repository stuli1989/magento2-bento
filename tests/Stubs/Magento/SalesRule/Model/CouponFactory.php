<?php

declare(strict_types=1);

namespace Magento\SalesRule\Model;

class CouponFactory
{
    public function create(): Coupon
    {
        return new Coupon();
    }
}
