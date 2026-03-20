<?php
/**
 * Customer Service
 *
 * Provides customer data for Bento events.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Psr\Log\LoggerInterface;

class CustomerService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get customer data for async event
     *
     * @param int $id Customer ID
     * @return array
     */
    public function getCustomerData(int $id): array
    {
        try {
            $customer = $this->customerRepository->getById($id);
            return $this->formatCustomerData($customer);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get customer data for Bento', [
                'customer_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Format customer data for Bento
     *
     * @param CustomerInterface $customer
     * @return array
     */
    public function formatCustomerData(CustomerInterface $customer): array
    {
        $storeId = (int)$customer->getStoreId();
        $tags = $this->config->getDefaultTags($storeId);

        $data = [
            'event_type' => '$Subscriber',

            'customer' => [
                'customer_id' => (int)$customer->getId(),
                'email' => $customer->getEmail(),
                'firstname' => $customer->getFirstname(),
                'lastname' => $customer->getLastname(),
                'created_at' => $customer->getCreatedAt(),
                'dob' => $customer->getDob(),
                'gender' => $this->getGenderLabel((int)$customer->getGender()),
                'group_id' => (int)$customer->getGroupId(),
                'group_name' => $this->getGroupName((int)$customer->getGroupId())
            ],

            'tags' => $tags,

            'store' => [
                'store_id' => $storeId,
                'website_id' => (int)$customer->getWebsiteId()
            ]
        ];

        // Include address if configured
        if ($this->config->includeCustomerAddress($storeId)) {
            $addresses = $customer->getAddresses();
            if (!empty($addresses)) {
                $defaultBilling = null;
                foreach ($addresses as $address) {
                    if ($address->isDefaultBilling()) {
                        $defaultBilling = $address;
                        break;
                    }
                }

                if ($defaultBilling) {
                    $data['addresses'] = [
                        'default_billing' => [
                            'street' => $defaultBilling->getStreet(),
                            'city' => $defaultBilling->getCity(),
                            'region' => $defaultBilling->getRegion()?->getRegion(),
                            'postcode' => $defaultBilling->getPostcode(),
                            'country_id' => $defaultBilling->getCountryId(),
                            'telephone' => $defaultBilling->getTelephone()
                        ]
                    ];
                }
            }
        }

        return $data;
    }

    /**
     * Get gender label
     */
    private function getGenderLabel(int $gender): ?string
    {
        return match ($gender) {
            1 => 'Male',
            2 => 'Female',
            default => null
        };
    }

    /**
     * Get customer group name
     */
    private function getGroupName(int $groupId): string
    {
        try {
            $group = $this->groupRepository->getById($groupId);
            return $group->getCode();
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }
}
