<?php

declare(strict_types=1);

namespace Magento\Sales\Api\Data;

interface OrderAddressInterface
{
    public function getFirstname();
    public function getLastname();
    public function getStreet();
    public function getCity();
    public function getRegion();
    public function getPostcode();
    public function getCountryId();
    public function getTelephone();
}
