<?php

declare(strict_types=1);

namespace Magento\Store\Api\Data;

interface StoreInterface
{
    public function getId();
    public function getCode();
    public function getWebsiteId();
    public function getCurrentCurrencyCode();
    public function getBaseUrl($type = 'link', $secure = null);
}
