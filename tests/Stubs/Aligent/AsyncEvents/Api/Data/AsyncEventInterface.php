<?php

declare(strict_types=1);

namespace Aligent\AsyncEvents\Api\Data;

interface AsyncEventInterface
{
    public function getSubscriptionId();
    public function getStoreId();
    public function getEventName();
    public function setEventName(string $eventName);
    public function setRecipientUrl(string $url);
    public function setVerificationToken(string $token);
    public function setMetadata(string $metadata);
    public function setStatus(bool $status);
    public function setSubscribedAt(string $date);
    public function setStoreId(int $storeId);
}
