<?php

declare(strict_types=1);

namespace Magento\SalesRule\Model;

class RuleFactory
{
    public function create(): Rule
    {
        return new Rule();
    }
}
