<?php
/**
 * Profile fields for /batch/subscribers, computed from full order history.
 * Sent with $purchase, $OrderCanceled, $OrderRefunded, $account_created, by bento:backfill and by the
 * nightly product-list refresh. Includes next_pick_*, new_in_*, usual_* (NextPicksProvider) unless they fail to compute,
 * and the live ladder (ladder_line, free_ship_from_display; Pricing\Ladder) so a pricing change reaches every
 * lifecycle email by the next night without editing a template.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Profile;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Payload\Phone;
use ArtLounge\BentoEvents\Model\Picks\NextPicksProvider;
use ArtLounge\BentoEvents\Model\Pricing\Ladder;

class ProfileFieldsProvider
{
    public function __construct(
        private readonly ProfileLoader $loader,
        private readonly StatsCalculator $calculator,
        private readonly ConfigInterface $config,
        private readonly NextPicksProvider $picks,
        private readonly Ladder $ladder
    ) {
    }

    /** One subscriber record ready for /batch/subscribers. */
    public function subscriberRecord(string $email, array $nameAndPhone = []): array
    {
        $email = strtolower(trim($email));

        return $this->subscriberRecords([$email], [$email => $nameAndPhone])[$email] ?? ['email' => $email];
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
        // List fields keep '' on purpose: an empty slot must clear an older entry.
        foreach ($this->picks->fieldsFor(array_map(static fn ($r) => $r['primary_medium'] ?? null, $out)) as $email => $fields) {
            $out[$email] += $fields;
        }
        // '' kept too: an empty ladder (all tiers off) must clear yesterday's sentence.
        $ladder = $this->ladder->profileFields(0);
        foreach ($out as $email => $record) {
            $out[$email] = $record + $ladder;
        }

        return $out;
    }
}
