<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoEvents\Model\Payload\CustomerSnapshotLoader;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use PHPUnit\Framework\TestCase;

class CustomerSnapshotLoaderTest extends TestCase
{
    private function address(bool $default, ?string $phone): AddressInterface
    {
        $a = $this->createMock(AddressInterface::class);
        $a->method('isDefaultBilling')->willReturn($default);
        $a->method('getTelephone')->willReturn($phone);
        $a->method('getRegion')->willReturn(null);

        return $a;
    }

    private function loader(array $addresses, ?\Throwable $error = null): CustomerSnapshotLoader
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(77);
        $customer->method('getAddresses')->willReturn($addresses);
        $repo = $this->createMock(CustomerRepositoryInterface::class);
        if ($error) {
            $repo->method('getById')->willThrowException($error);
        } else {
            $repo->method('getById')->willReturn($customer);
        }

        return new CustomerSnapshotLoader($repo, $this->createMock(GroupRepositoryInterface::class));
    }

    public function testDefaultPhoneIsTheDefaultBillingTelephone(): void
    {
        $this->assertSame(
            '9820055555',
            $this->loader([$this->address(false, '9000000000'), $this->address(true, '9820055555')])->defaultPhone(77)
        );
    }

    public function testDefaultPhoneIsNullWithoutAddressesBlankTelephoneOrOnError(): void
    {
        $this->assertNull($this->loader([])->defaultPhone(77));
        $this->assertNull($this->loader([$this->address(true, '')])->defaultPhone(77));
        $this->assertNull($this->loader([], new \RuntimeException('db down'))->defaultPhone(77));
    }
}
