<?php
/**
 * Profile fields for /batch/subscribers, computed from full order history.
 * Sent with $purchase, $OrderCanceled, $OrderRefunded, $account_created and by bento:backfill.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Profile;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Payload\Phone;

class ProfileFieldsProvider
{
    public function __construct(
        private readonly ProfileLoader $loader,
        private readonly StatsCalculator $calculator,
        private readonly ConfigInterface $config
    ) {
    }

    /** One subscriber record ready for /batch/subscribers. */
    public function subscriberRecord(string $email, array $nameAndPhone = []): array
    {
        return $this->subscriberRecords([strtolower(trim($email))], [strtolower(trim($email)) => $nameAndPhone])[strtolower(trim($email))];
    }

    /**
     * @param string[] $emails lower-cased
     * @param array<string,array> $overrides email => {first_name?, last_name?, phone?} (preferred over history)
     * @return array<string,array> email => {email, first_name?, last_name?, phone?, order_count, ...}
     */
    public function subscriberRecords(array $emails, array $overrides = []): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn ($e) => strtolower(trim($e)), $emails))));
        $orders = $this->loader->orders($emails);
        $items = $this->loader->items($emails);
        $accounts = $this->loader->accounts($emails);
        $contacts = $this->loader->contactDetails($emails);
        $tree = $this->loader->categoryTree();
        $map = $this->config->getCategoryMediumMap();

        $out = [];
        foreach ($emails as $email) {
            $stats = $this->calculator->calculate(
                $orders[$email] ?? [],
                $items[$email] ?? [],
                $accounts[$email] ?? ['is_registered' => false, 'created_at' => null],
                $tree,
                $map
            );
            $contact = array_merge($contacts[$email] ?? [], array_filter($overrides[$email] ?? []));
            $record = ['email' => $email]
                + array_filter([
                    'first_name' => $contact['first_name'] ?? null,
                    'last_name' => $contact['last_name'] ?? null,
                    'phone' => Phone::normalize($contact['phone'] ?? null),
                ])
                + $stats;
            // Never send nulls (they would blank existing Bento values).
            $out[$email] = array_filter($record, static fn ($v) => $v !== null);
        }

        return $out;
    }
}
