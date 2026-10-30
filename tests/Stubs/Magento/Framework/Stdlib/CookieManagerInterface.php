<?php

declare(strict_types=1);

namespace Magento\Framework\Stdlib;

interface CookieManagerInterface
{
    public function getCookie($name, $default = null);
    public function deleteCookie($name, $metadata = null);
}
