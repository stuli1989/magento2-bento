<?php

declare(strict_types=1);

namespace Magento\Framework\Message;

interface ManagerInterface
{
    public function addNoticeMessage($message): void;
    public function addSuccessMessage($message): void;
    public function addErrorMessage($message): void;
}
