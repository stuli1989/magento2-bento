<?php

declare(strict_types=1);

namespace Magento\Catalog\Api;

interface CategoryRepositoryInterface
{
    public function get($categoryId, $storeId = null);
}
