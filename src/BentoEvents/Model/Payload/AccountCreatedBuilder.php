<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoCore\Model\IstDate;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Profile\ProfileFieldsProvider;

class AccountCreatedBuilder implements BuilderInterface
{
    public function __construct(
        private readonly CustomerSnapshotLoader $loader,
        private readonly EventEnvelope $envelope,
        private readonly ProfileFieldsProvider $profile
    ) {
    }

    public function build(OutboxRow $row): BuildResult
    {
        $c = $this->loader->load($row->entityId);
        if ($c === null) {
            return BuildResult::skip('customer ' . $row->entityId . ' not found');
        }
        $phone = Phone::normalize($c['default_billing']['telephone'] ?? null);
        $fields = array_filter([
            'first_name' => $c['firstname'] ?? null,
            'last_name' => $c['lastname'] ?? null,
            'phone' => $phone,
        ]);

        $details = [
            'customer' => [
                'customer_id' => $c['customer_id'],
                'email' => $c['email'],
                'firstname' => $c['firstname'],
                'lastname' => $c['lastname'],
                'created_at' => IstDate::formatOrNull($c['created_at'] ?? null),
                'group_name' => $c['group'] ?? null,
                'phone' => $phone,
            ],
            'addresses' => ['default_billing' => $c['default_billing']],
        ];

        return BuildResult::event(
            $this->envelope->wrap($row, '$account_created', $details, $row->dedupKey, $fields),
            $this->profile->subscriberRecord($row->email, $fields)
        );
    }
}
