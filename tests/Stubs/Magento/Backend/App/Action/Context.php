<?php

declare(strict_types=1);

namespace Magento\Backend\App\Action;

use Magento\Framework\App\RequestInterface;

class Context
{
    public function __construct(private RequestInterface $request)
    {
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
