<?php

declare(strict_types=1);

namespace Magento\Customer\Model;

interface AuthenticationInterface
{
    public function isLocked($customerId);
}
