<?php

declare(strict_types=1);

namespace Aligent\AsyncEvents\Helper;

class NotifierResultFactory
{
    public function create(): NotifierResult
    {
        return new NotifierResult();
    }
}
