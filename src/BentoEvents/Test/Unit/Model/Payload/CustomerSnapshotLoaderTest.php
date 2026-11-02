<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoEvents\Model\Payload\CustomerSnapshotLoader;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
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

    private function loader(array $addresses, ?\Throwable $error = null, $accountPhone = false, bool $column = true): CustomerSnapshotLoader
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

        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('select')->willReturn(new Select());
        $conn->method('tableColumnExists')->willReturn($column);
        $conn->method('fetchOne')->willReturn($accountPhone);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        return new CustomerSnapshotLoader($repo, $this->createMock(GroupRepositoryInterface::class), $resource);
    }

    public function testOwnsPhoneMatchesTheAccountPhoneOrTheDefaultAddressInAnyFormat(): void
    {
        $this->assertTrue($this->loader([$this->address(true, '098200 55555')])->ownsPhone(77, '+919820055555'));
        $this->assertTrue($this->loader([], null, '+919820055555')->ownsPhone(77, '9820055555'));
    }

    public function testOwnsPhoneIsFalseForAnotherNumberNoNumberOrAStoreWithoutTheColumn(): void
    {
        $this->assertFalse($this->loader([$this->address(true, '9820055555')], null, '+919000000000')->ownsPhone(77, '9812345678'));
        $this->assertFalse($this->loader([$this->address(true, '9820055555')])->ownsPhone(77, null));
        $this->assertFalse($this->loader([], null, '+919820055555', false)->ownsPhone(77, '9820055555'));
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
