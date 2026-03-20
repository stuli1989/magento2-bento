<?php

declare(strict_types=1);

namespace Magento\Checkout\Model;

class Session
{
    private ?int $quoteId = null;

    public function setQuoteId($quoteId): void { $this->quoteId = (int)$quoteId; }
    public function getQuoteId(): ?int { return $this->quoteId; }
    public function getLastRealOrder() { return null; }
}
