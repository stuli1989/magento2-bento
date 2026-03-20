<?php

declare(strict_types=1);

namespace Magento\Framework\Controller\Result;

class Json
{
    private array $data = [];

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
