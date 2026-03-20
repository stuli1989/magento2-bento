<?php

declare(strict_types=1);

namespace Magento\Catalog\Helper;

class Image
{
    public function init($product, $imageId): self
    {
        return $this;
    }

    public function getUrl(): string
    {
        return '';
    }
}
