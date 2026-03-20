<?php

declare(strict_types=1);

namespace Magento\Framework\Setup;

interface ModuleDataSetupInterface
{
    public function startSetup();
    public function endSetup();
}
