<?php

declare(strict_types=1);

namespace Magento\Framework;

class Registry
{
    public function registry(string $key)
    {
        return null;
    }

    public function register(string $key, $value, bool $graceful = false): void
    {
    }

    public function unregister(string $key): void
    {
    }
}
