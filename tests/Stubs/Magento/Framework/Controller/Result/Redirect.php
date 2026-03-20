<?php

declare(strict_types=1);

namespace Magento\Framework\Controller\Result;

class Redirect
{
    private string $path = '';
    private array $params = [];

    public function setPath($path, $params = []): self
    {
        $this->path = $path;
        $this->params = $params;
        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}
