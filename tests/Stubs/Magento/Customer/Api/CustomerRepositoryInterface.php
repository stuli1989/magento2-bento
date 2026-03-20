<?php

declare(strict_types=1);

namespace Magento\Customer\Api;

interface CustomerRepositoryInterface
{
    public function getById($id);
}
