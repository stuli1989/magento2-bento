<?php

declare(strict_types=1);

namespace Magento\Framework\Encryption;

interface EncryptorInterface
{
    public function decrypt($value);
}
