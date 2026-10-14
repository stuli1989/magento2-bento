<?php

declare(strict_types=1);

namespace Magento\Catalog\Model\ResourceModel\Product;

class CollectionFactory
{
    public function create(array $data = []) { return new Collection(); }
}
