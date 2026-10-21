<?php

declare(strict_types=1);

namespace Magento\SalesRule\Model;

/** Data-bag stand-in: setFoo($v) / getFoo() over an array, like Magento's DataObject. */
class Rule
{
    public const COUPON_TYPE_SPECIFIC = 2;

    private array $data = [];
    private ?object $conditions = null;

    public function __call(string $method, array $args)
    {
        $key = lcfirst(substr($method, 3));
        if (str_starts_with($method, 'set')) {
            $this->data[$key] = $args[0] ?? null;
            return $this;
        }
        return $this->data[$key] ?? null;
    }

    public function getConditions(): object
    {
        return $this->conditions ??= new class {
            public array $loaded = [];
            public function loadArray(array $a) { $this->loaded = $a; return $this; }
        };
    }
}
