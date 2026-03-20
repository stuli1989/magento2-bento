<?php

declare(strict_types=1);

namespace Aligent\AsyncEvents\Helper;

class NotifierResult
{
    protected bool $success = false;
    protected string $responseData = '';
    protected ?int $subscriptionId = null;
    protected array $asyncEventData = [];
    protected ?string $uuid = null;

    public function setSuccess(bool $success): void
    {
        $this->success = $success;
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function setResponseData(string $data): void
    {
        $this->responseData = $data;
    }

    public function getResponseData(): string
    {
        return $this->responseData;
    }

    public function setSubscriptionId($id): void
    {
        $this->subscriptionId = $id;
    }

    public function setAsyncEventData($data): void
    {
        $this->asyncEventData = $data;
    }

    public function setUuid($uuid): void
    {
        $this->uuid = $uuid;
    }
}
