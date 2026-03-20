<?php

declare(strict_types=1);

namespace Magento\Quote\Api;

interface CartRepositoryInterface
{
    public function get($id);
}
