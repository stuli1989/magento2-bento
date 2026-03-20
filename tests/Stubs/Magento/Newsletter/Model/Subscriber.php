<?php

declare(strict_types=1);

namespace Magento\Newsletter\Model;

class Subscriber
{
    public const STATUS_SUBSCRIBED = 1;
    public const STATUS_NOT_ACTIVE = 2;
    public const STATUS_UNSUBSCRIBED = 3;
    public const STATUS_UNCONFIRMED = 4;

    public function getStoreId() { return 0; }
    public function getSubscriberStatus() { return null; }
    public function getId() { return null; }
    public function getSubscriberEmail() { return null; }
    public function getEmail() { return null; }
    public function getCustomerId() { return null; }
    public function getChangeStatusAt() { return null; }
    public function load($id, $field = null) { return $this; }
    public function getFirstname() { return null; }
    public function getLastname() { return null; }
}
