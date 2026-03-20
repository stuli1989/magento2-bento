<?php

declare(strict_types=1);

namespace Magento\Customer\Api\Data;

interface CustomerInterface
{
    public function getId();
    public function getEmail();
    public function getFirstname();
    public function getLastname();
    public function getCreatedAt();
    public function getDob();
    public function getGender();
    public function getGroupId();
    public function getStoreId();
    public function getWebsiteId();
    public function getAddresses();
}
