<?php

declare(strict_types=1);

namespace Magento\Framework\MessageQueue;

interface PublisherInterface
{
    public function publish($topicName, $data): void;
}
