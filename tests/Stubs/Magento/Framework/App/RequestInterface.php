<?php

declare(strict_types=1);

namespace Magento\Framework\App;

interface RequestInterface
{
    public function getParam($key, $default = null);
}
