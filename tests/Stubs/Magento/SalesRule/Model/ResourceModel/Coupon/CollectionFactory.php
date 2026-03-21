<?php

declare(strict_types=1);

namespace Magento\SalesRule\Model\ResourceModel\Coupon;

class CollectionFactory
{
    public function create(): Collection
    {
        return new Collection();
    }
}
