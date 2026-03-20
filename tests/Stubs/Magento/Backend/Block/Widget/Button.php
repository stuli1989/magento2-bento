<?php

declare(strict_types=1);

namespace Magento\Backend\Block\Widget;

class Button
{
    public function setData(array $data): self
    {
        return $this;
    }

    public function toHtml(): string
    {
        return '<button>Test Connection</button>';
    }
}
