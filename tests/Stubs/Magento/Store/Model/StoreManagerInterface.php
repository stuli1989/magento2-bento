<?php

declare(strict_types=1);

namespace Magento\Store\Model;

interface StoreManagerInterface
{
    public function getStore();
    public function getDefaultStoreView();
}
