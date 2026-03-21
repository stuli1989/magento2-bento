<?php

declare(strict_types=1);

namespace Magento\SalesRule\Model\ResourceModel\Coupon;

class Collection
{
    public function addFieldToFilter($field, $condition = null) { return $this; }
    public function setPageSize($size) { return $this; }
    public function getFirstItem() { return null; }
    public function getSize() { return 0; }
}
