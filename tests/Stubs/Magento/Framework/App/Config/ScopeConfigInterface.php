<?php

declare(strict_types=1);

namespace Magento\Framework\App\Config;

interface ScopeConfigInterface
{
    public function getValue($path, $scope = null, $scopeCode = null);
    public function isSetFlag($path, $scope = null, $scopeCode = null);
}
