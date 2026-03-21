<?php

declare(strict_types=1);

namespace Magento\SalesRule\Model\ResourceModel;

class Coupon
{
    public function save($object) { return $this; }
    public function load($object, $value, $field = null) { return $this; }
    public function delete($object) { return $this; }
}
