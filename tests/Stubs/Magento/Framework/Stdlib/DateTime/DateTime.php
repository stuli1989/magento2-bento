<?php

declare(strict_types=1);

namespace Magento\Framework\Stdlib\DateTime;

class DateTime
{
    public function gmtDate(string $format = 'Y-m-d H:i:s', $timestamp = null): string
    {
        return '1970-01-01 00:00:00';
    }

    public function gmtTimestamp(): int
    {
        return time();
    }
}
