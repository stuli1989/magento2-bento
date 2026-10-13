<?php

declare(strict_types=1);

namespace Magento\Framework;

class FlagManager
{
    private array $flags = [];

    public function getFlagData($code)
    {
        return $this->flags[$code] ?? null;
    }

    public function saveFlag($code, $value)
    {
        $this->flags[$code] = $value;
        return true;
    }

    public function deleteFlag($code)
    {
        unset($this->flags[$code]);
        return true;
    }
}
