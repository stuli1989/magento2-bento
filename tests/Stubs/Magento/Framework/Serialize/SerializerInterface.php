<?php

declare(strict_types=1);

namespace Magento\Framework\Serialize;

interface SerializerInterface
{
    public function serialize($data);
    public function unserialize($string);
}
