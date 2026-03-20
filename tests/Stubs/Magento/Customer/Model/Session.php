<?php

declare(strict_types=1);

namespace Magento\Customer\Model;

class Session
{
    private bool $loggedIn = false;
    private ?int $customerId = null;
    private array $data = [];

    public function setLoggedIn(bool $v): void { $this->loggedIn = $v; }
    public function isLoggedIn(): bool { return $this->loggedIn; }

    public function setCustomerId(?int $id): void { $this->customerId = $id; }
    public function getCustomerId() { return $this->customerId; }

    public function setBeforeAuthUrl(string $url): void { $this->data['before_auth_url'] = $url; }
    public function setAfterAuthUrl(string $url): void { $this->data['after_auth_url'] = $url; }

    public function setData(string $key, $value): void { $this->data[$key] = $value; }
    public function getData(string $key) { return $this->data[$key] ?? null; }
}
