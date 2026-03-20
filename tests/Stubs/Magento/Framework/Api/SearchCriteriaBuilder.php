<?php

declare(strict_types=1);

namespace Magento\Framework\Api;

class SearchCriteriaBuilder
{
    public function addFilter($field, $value = null, $conditionType = null): self
    {
        return $this;
    }

    public function setPageSize($size): self
    {
        return $this;
    }

    public function create(): SearchCriteriaInterface
    {
        return new class implements SearchCriteriaInterface {};
    }
}
