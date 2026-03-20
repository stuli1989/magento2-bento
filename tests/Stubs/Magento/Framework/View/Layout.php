<?php

declare(strict_types=1);

namespace Magento\Framework\View;

class Layout
{
    public function createBlock($class)
    {
        return new $class();
    }
}
