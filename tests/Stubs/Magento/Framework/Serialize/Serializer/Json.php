<?php

declare(strict_types=1);

namespace Magento\Framework\Serialize\Serializer;

class Json
{
    public function serialize($data): string
    {
        return json_encode($data);
    }

    public function unserialize(string $string)
    {
        return json_decode($string, true);
    }
}
