<?php

declare(strict_types=1);

namespace Magento\Framework;

interface UrlInterface
{
    public const URL_TYPE_LINK = 'link';
    public const URL_TYPE_DIRECT_LINK = 'direct_link';
    public const URL_TYPE_WEB = 'web';
    public const URL_TYPE_MEDIA = 'media';
    public const URL_TYPE_STATIC = 'static';
    public const URL_TYPE_JS = 'js';

    public function getUrl(string $routePath = null, array $routeParams = null): string;
    public function getBaseUrl(array $params = []): string;
}
