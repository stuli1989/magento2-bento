<?php

declare(strict_types=1);

namespace Magento\CatalogInventory\Api;

interface StockRegistryInterface
{
    public function getStockItem($productId);
}
