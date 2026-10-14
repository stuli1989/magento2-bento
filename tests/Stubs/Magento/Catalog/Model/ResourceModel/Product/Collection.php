<?php

declare(strict_types=1);

namespace Magento\Catalog\Model\ResourceModel\Product;

/** Minimal fluent product collection for unit tests: iterate whatever setItems() was given. */
class Collection implements \IteratorAggregate
{
    public array $items = [];
    public array $calls = [];

    public function setItems(array $items): self { $this->items = $items; return $this; }
    public function setStoreId($storeId): self { $this->calls[] = ['setStoreId', $storeId]; return $this; }
    public function addAttributeToSelect($attributes): self { $this->calls[] = ['addAttributeToSelect', $attributes]; return $this; }
    public function addAttributeToFilter($attribute, $condition = null): self { return $this; }
    public function addIdFilter($ids): self { $this->calls[] = ['addIdFilter', $ids]; return $this; }
    public function addUrlRewrite(): self { return $this; }
    public function addCategoryIds(): self { return $this; }
    public function addIsActiveFilter(): self { return $this; }
    public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
}
