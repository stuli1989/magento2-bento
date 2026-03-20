<?php

declare(strict_types=1);

namespace Magento\Customer\Api\Data;

interface AddressInterface
{
    public function isDefaultBilling();
    public function getStreet();
    public function getCity();
    public function getRegion();
    public function getPostcode();
    public function getCountryId();
    public function getTelephone();
}
