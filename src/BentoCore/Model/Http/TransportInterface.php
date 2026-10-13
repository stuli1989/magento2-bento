<?php

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model\Http;

interface TransportInterface
{
    /**
     * @param string[] $headers "Name: value" lines
     * @return array{status:int, body:string, error:?string} status 0 + error on transport failure
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array;
}
