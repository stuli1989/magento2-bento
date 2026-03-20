<?php

declare(strict_types=1);

namespace Magento\Newsletter\Model;

class SubscriberFactory
{
    public function create(): Subscriber
    {
        return new Subscriber();
    }
}
