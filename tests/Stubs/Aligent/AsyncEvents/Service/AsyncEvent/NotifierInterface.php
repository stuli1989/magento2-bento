<?php

declare(strict_types=1);

namespace Aligent\AsyncEvents\Service\AsyncEvent;

use Aligent\AsyncEvents\Api\Data\AsyncEventInterface;
use Aligent\AsyncEvents\Helper\NotifierResult;

interface NotifierInterface
{
    public function notify(AsyncEventInterface $asyncEvent, array $data): NotifierResult;
}
