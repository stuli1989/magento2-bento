<?php

declare(strict_types=1);

namespace Magento\Store\Model\App;

class Emulation
{
    public function startEnvironmentEmulation($storeId, $area = 'frontend', $force = false)
    {
        return $this;
    }

    public function stopEnvironmentEmulation()
    {
        return $this;
    }
}
