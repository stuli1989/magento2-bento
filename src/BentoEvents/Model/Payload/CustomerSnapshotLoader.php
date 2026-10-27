<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class CustomerSnapshotLoader
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    /** The telephone on the customer's default billing address (else their first address); null if none or on any error. */
    public function defaultPhone(int $customerId): ?string
    {
        try {
            $phone = $this->load($customerId)['default_billing']['telephone'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return $phone !== null && $phone !== '' ? (string)$phone : null;
    }

    public function load(int $customerId): ?array
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return null;
        }
        $billing = null;
        foreach ((array)$customer->getAddresses() as $address) {
            if ($address->isDefaultBilling() || $billing === null) {
                $billing = [
                    'street' => $address->getStreet(),
                    'city' => $address->getCity(),
                    'region' => $address->getRegion()?->getRegion(),
                    'postcode' => $address->getPostcode(),
                    'country_id' => $address->getCountryId(),
                    'telephone' => $address->getTelephone(),
                ];
                if ($address->isDefaultBilling()) {
                    break;
                }
            }
        }
        try {
            $group = $this->groupRepository->getById((int)$customer->getGroupId())->getCode();
        } catch (\Throwable) {
            $group = null;
        }

        return [
            'customer_id' => (int)$customer->getId(),
            'email' => strtolower((string)$customer->getEmail()),
            'firstname' => $customer->getFirstname(),
            'lastname' => $customer->getLastname(),
            'created_at' => $customer->getCreatedAt(),
            'group' => $group,
            'default_billing' => $billing,
        ];
    }
}
