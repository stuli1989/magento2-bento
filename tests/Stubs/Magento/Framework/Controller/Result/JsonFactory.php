<?php

declare(strict_types=1);

namespace Magento\Framework\Controller\Result;

class JsonFactory
{
    public function create(): Json
    {
        return new Json();
    }
}
