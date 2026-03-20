<?php

declare(strict_types=1);

namespace Magento\Framework\Event;

interface ObserverInterface
{
    public function execute(Observer $observer): void;
}
