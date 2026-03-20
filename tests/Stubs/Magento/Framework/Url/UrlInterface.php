<?php

declare(strict_types=1);

namespace Magento\Framework\Url;

interface UrlInterface
{
    public function getUrl($routePath = null, $routeParams = null);
}
