<?php

declare(strict_types=1);

namespace Magento\Framework\Controller\Result;

/**
 * Mirrors the real class's storage: protected $json (string) and $httpResponseCode.
 */
class Json
{
    protected $json;
    protected $httpResponseCode;

    public function setData($data, $cycleCheck = false, $options = []): self
    {
        $this->json = json_encode($data);
        return $this;
    }

    public function setJsonData($jsonData): self
    {
        $this->json = (string)$jsonData;
        return $this;
    }

    public function setHttpResponseCode($httpCode): self
    {
        $this->httpResponseCode = $httpCode;
        return $this;
    }

    public function getData(): array
    {
        return (array)json_decode((string)$this->json, true);
    }
}
