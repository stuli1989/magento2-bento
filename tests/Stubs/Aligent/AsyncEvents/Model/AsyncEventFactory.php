<?php

declare(strict_types=1);

namespace Aligent\AsyncEvents\Model;

class AsyncEventFactory
{
    public function create(): object
    {
        return new class {
            public function setEventName($value): void {}
            public function setRecipientUrl($value): void {}
            public function setVerificationToken($value): void {}
            public function setMetadata($value): void {}
            public function setStatus($value): void {}
            public function setSubscribedAt($value): void {}
            public function setStoreId($value): void {}
        };
    }
}
