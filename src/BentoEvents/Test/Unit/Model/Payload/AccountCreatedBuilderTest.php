<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\AccountCreatedBuilder;
use ArtLounge\BentoEvents\Model\Payload\CustomerSnapshotLoader;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;
use PHPUnit\Framework\TestCase;

class AccountCreatedBuilderTest extends TestCase
{
    public function testAccountCreatedCarriesCustomerAddressPhoneAndProfile(): void
    {
        $loader = $this->createMock(CustomerSnapshotLoader::class);
        $loader->method('load')->with(77)->willReturn([
            'customer_id' => 77, 'email' => 'new+bentotest@x.com', 'firstname' => 'Asha', 'lastname' => 'Rao',
            'created_at' => '2026-10-13 10:00:00', 'group' => 'General',
            'default_billing' => ['street' => ['1 Lane'], 'city' => 'Pune', 'region' => 'Maharashtra',
                'postcode' => '411001', 'country_id' => 'IN', 'telephone' => '9876543210'],
        ]);
        $profile = $this->createMock(ProfileFieldsProvider::class);
        $profile->method('subscriberRecord')->willReturnCallback(
            fn ($email, $extra) => ['email' => $email, 'is_registered' => true, 'order_count' => 0] + $extra
        );
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');

        $builder = new AccountCreatedBuilder($loader, new EventEnvelope($config), $profile);
        $r = $builder->build(OutboxRow::fromArray([
            'id' => 1, 'event_type' => 'account_created', 'entity_type' => 'customer', 'entity_id' => 77,
            'email' => 'new+bentotest@x.com', 'store_id' => 1, 'dedup_key' => 'account_created:77',
            'created_at' => '2026-10-13 10:00:01',
        ]));

        $this->assertSame('$account_created', $r->event['type']);
        $this->assertSame('+919876543210', $r->event['fields']['phone']);
        $this->assertSame('Asha', $r->event['fields']['first_name']);
        $this->assertSame('2026-10-13T15:30:00+05:30', $r->event['details']['customer']['created_at']);
        $this->assertSame('Pune', $r->event['details']['addresses']['default_billing']['city']);
        $this->assertTrue($r->subscriber['is_registered']);
        $this->assertSame('+919876543210', $r->subscriber['phone']);
    }

    public function testDeletedCustomerIsSkipped(): void
    {
        $loader = $this->createMock(CustomerSnapshotLoader::class);
        $loader->method('load')->willReturn(null);
        $config = $this->createMock(ConfigInterface::class);
        $builder = new AccountCreatedBuilder($loader, new EventEnvelope($config), $this->createMock(ProfileFieldsProvider::class));
        $this->assertTrue($builder->build(OutboxRow::fromArray(['entity_id' => 1, 'email' => 'a@b.c']))->isSkip());
    }
}
