<?php

declare(strict_types=1);

namespace Magento\Framework\HTTP\Client;

class Curl
{
    public function setHeaders(array $headers): void {}
    public function setTimeout(int $timeout): void {}
    public function post(string $url, string $data): void {}
    public function getStatus(): int { return 200; }
    public function getBody(): string { return ''; }
}
