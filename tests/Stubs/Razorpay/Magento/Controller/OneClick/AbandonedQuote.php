<?php

declare(strict_types=1);

namespace Razorpay\Magento\Controller\OneClick;

class AbandonedQuote
{
    public function __construct(private array $params = [])
    {
    }

    public function getRequest(): object
    {
        $params = $this->params;
        return new class ($params) {
            public function __construct(private array $p)
            {
            }

            public function getParam($k, $d = null)
            {
                return $this->p[$k] ?? $d;
            }
        };
    }
}
