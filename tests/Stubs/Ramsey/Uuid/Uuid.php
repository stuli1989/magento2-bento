<?php

declare(strict_types=1);

namespace Ramsey\Uuid;

class Uuid
{
    public static function uuid4(): self
    {
        return new self();
    }

    public function toString(): string
    {
        return '00000000-0000-0000-0000-000000000000';
    }
}
