<?php

declare(strict_types=1);

namespace Magento\Framework\App;

class ResourceConnection
{
    public function getConnection() { return null; }
    public function getTableName($name): string { return $name; }
}
