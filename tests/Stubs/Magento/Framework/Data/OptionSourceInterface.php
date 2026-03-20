<?php

declare(strict_types=1);

namespace Magento\Framework\Data;

interface OptionSourceInterface
{
    public function toOptionArray(): array;
}
