<?php

declare(strict_types=1);

namespace Magento\Framework;

interface UrlInterface
{
    public function getUrl(string $routePath = null, array $routeParams = null): string;
    public function getBaseUrl(array $params = []): string;
}
