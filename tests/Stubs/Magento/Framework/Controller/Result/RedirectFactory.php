<?php

declare(strict_types=1);

namespace Magento\Framework\Controller\Result;

class RedirectFactory
{
    public function create(): Redirect
    {
        return new Redirect();
    }
}
