<?php
/**
 * Customer Service Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Service\CustomerService;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\RegionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomerServiceTest extends TestCase
{
    private CustomerService $service;
    private MockObject $customerRepository;
    private MockObject $groupRepository;
    private MockObject $config;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new CustomerService(
            $this->customerRepository,
            $this->groupRepository,
            $this->config,
            $this->logger
        );
    }

    public function testGetCustomerDataFormatsCustomer(): void
    {
        $customer = $this->createCustomerMock();
        $customer->method('getGender')->willReturn(1);

        $this->customerRepository->method('getById')->willReturn($customer);
        $this->config->method('getDefaultTags')->willReturn(['lead']);
        $this->config->method('includeCustomerAddress')->willReturn(true);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $result = $this->service->getCustomerData(1);

        $this->assertSame('$Subscriber', $result['event_type']);
        $this->assertSame('test@example.com', $result['customer']['email']);
        $this->assertSame(['lead'], $result['tags']);
        $this->assertArrayHasKey('addresses', $result);
    }

    public function testGetCustomerDataThrowsOnRepositoryError(): void
    {
        $this->customerRepository
            ->method('getById')
            ->willThrowException(new \RuntimeException('fail'));

        $this->expectException(\RuntimeException::class);

        $this->service->getCustomerData(99);
    }

    public function testFormatCustomerDataHandlesUnknownGender(): void
    {
        $customer = $this->createCustomerMock();
        $customer->method('getGender')->willReturn(3);
        $this->config->method('getDefaultTags')->willReturn([]);
        $this->config->method('includeCustomerAddress')->willReturn(false);

        $this->groupRepository
            ->method('getById')
            ->willThrowException(new \RuntimeException('missing'));

        $result = $this->service->formatCustomerData($customer);

        $this->assertNull($result['customer']['gender']);
        $this->assertSame('Unknown', $result['customer']['group_name']);
        $this->assertArrayNotHasKey('addresses', $result);
    }

    public function testFormatCustomerDataFemaleGender(): void
    {
        $customer = $this->createCustomerMock();
        $customer->method('getGender')->willReturn(2);
        $this->config->method('getDefaultTags')->willReturn([]);
        $this->config->method('includeCustomerAddress')->willReturn(false);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $result = $this->service->formatCustomerData($customer);

        $this->assertSame('Female', $result['customer']['gender']);
    }

    public function testFormatCustomerDataOmitsAddressesWhenDisabled(): void
    {
        $customer = $this->createCustomerMock();
        $customer->method('getGender')->willReturn(1);
        $this->config->method('getDefaultTags')->willReturn([]);
        $this->config->method('includeCustomerAddress')->willReturn(false);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $result = $this->service->formatCustomerData($customer);

        $this->assertArrayNotHasKey('addresses', $result);
    }

    public function testFormatCustomerDataIncludesAddressWhenEnabled(): void
    {
        $customer = $this->createCustomerMock();
        $customer->method('getGender')->willReturn(1);
        $this->config->method('getDefaultTags')->willReturn([]);
        $this->config->method('includeCustomerAddress')->willReturn(true);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $result = $this->service->formatCustomerData($customer);

        $this->assertArrayHasKey('addresses', $result);
        $this->assertSame('City', $result['addresses']['default_billing']['city']);
        $this->assertSame('12345', $result['addresses']['default_billing']['postcode']);
        $this->assertSame('IN', $result['addresses']['default_billing']['country_id']);
    }

    public function testFormatCustomerDataIncludesStoreInfo(): void
    {
        $customer = $this->createCustomerMock();
        $customer->method('getGender')->willReturn(1);
        $this->config->method('getDefaultTags')->willReturn([]);
        $this->config->method('includeCustomerAddress')->willReturn(false);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $result = $this->service->formatCustomerData($customer);

        $this->assertSame(1, $result['store']['store_id']);
        $this->assertSame(1, $result['store']['website_id']);
    }

    public function testFormatCustomerDataIncludesTags(): void
    {
        $customer = $this->createCustomerMock();
        $customer->method('getGender')->willReturn(1);
        $this->config->method('getDefaultTags')->willReturn(['lead', 'vip']);
        $this->config->method('includeCustomerAddress')->willReturn(false);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $result = $this->service->formatCustomerData($customer);

        $this->assertSame(['lead', 'vip'], $result['tags']);
    }

    public function testGetCustomerDataLogsErrorOnFailure(): void
    {
        $this->customerRepository
            ->method('getById')
            ->willThrowException(new \RuntimeException('fail'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to get customer data for Bento', $this->anything());

        $this->expectException(\RuntimeException::class);
        $this->service->getCustomerData(99);
    }

    public function testFormatCustomerDataIncludesBasicFields(): void
    {
        $customer = $this->createCustomerMock();
        $customer->method('getGender')->willReturn(1);
        $this->config->method('getDefaultTags')->willReturn([]);
        $this->config->method('includeCustomerAddress')->willReturn(false);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $result = $this->service->formatCustomerData($customer);

        $this->assertSame(1, $result['customer']['customer_id']);
        $this->assertSame('test@example.com', $result['customer']['email']);
        $this->assertSame('Test', $result['customer']['firstname']);
        $this->assertSame('User', $result['customer']['lastname']);
        $this->assertSame('2026-01-24', $result['customer']['created_at']);
        $this->assertSame('1990-01-01', $result['customer']['dob']);
        $this->assertSame(1, $result['customer']['group_id']);
        $this->assertSame('General', $result['customer']['group_name']);
    }

    private function createCustomerMock(): MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $address = $this->createMock(AddressInterface::class);
        $region = $this->createMock(RegionInterface::class);

        $region->method('getRegion')->willReturn('Region');

        $address->method('isDefaultBilling')->willReturn(true);
        $address->method('getStreet')->willReturn(['Street']);
        $address->method('getCity')->willReturn('City');
        $address->method('getRegion')->willReturn($region);
        $address->method('getPostcode')->willReturn('12345');
        $address->method('getCountryId')->willReturn('IN');
        $address->method('getTelephone')->willReturn('123');

        $customer->method('getId')->willReturn(1);
        $customer->method('getEmail')->willReturn('test@example.com');
        $customer->method('getFirstname')->willReturn('Test');
        $customer->method('getLastname')->willReturn('User');
        $customer->method('getCreatedAt')->willReturn('2026-01-24');
        $customer->method('getDob')->willReturn('1990-01-01');
        $customer->method('getGroupId')->willReturn(1);
        $customer->method('getStoreId')->willReturn(1);
        $customer->method('getWebsiteId')->willReturn(1);
        $customer->method('getAddresses')->willReturn([$address]);

        return $customer;
    }
}
