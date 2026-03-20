<?php

declare(strict_types=1);

namespace Magento\Backend\App;

use Magento\Backend\App\Action\Context;

abstract class Action
{
    protected Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function getRequest()
    {
        return $this->context->getRequest();
    }
}
