<?php

declare(strict_types=1);

namespace Magento\Framework\DB\Sql;

class Expression
{
    private string $expression;

    public function __construct(string $expression)
    {
        $this->expression = $expression;
    }

    public function __toString(): string
    {
        return $this->expression;
    }
}
