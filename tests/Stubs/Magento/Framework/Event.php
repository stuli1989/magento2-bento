<?php

declare(strict_types=1);

namespace Magento\Framework;

class Event
{
    public function getOrder() { return null; }
    public function getCustomer() { return null; }
    public function getSubscriber() { return null; }
    public function getQuote() { return null; }
    public function getObject() { return null; }
    public function getData($key = null) { return null; }
    public function getCreditmemo() { return null; }
    public function getShipment() { return null; }
    public function getCustomerDataObject() { return null; }
    public function getOrigCustomerDataObject() { return null; }
}
