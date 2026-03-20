<?php

declare(strict_types=1);

namespace Magento\Framework\Setup\Patch;

interface DataPatchInterface
{
    public function apply();
    public static function getDependencies(): array;
    public function getAliases(): array;
}
