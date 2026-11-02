<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Abandonment;

interface AbandonmentQueriesInterface
{
    public function quoteHasPaidOrder(int $quoteId): bool;

    /**
     * Identified (email set), active, non-empty quotes last updated between 7 days ago and
     * $idleBeforeUtc, grand total ≥ $minValue, with no sales_order at all.
     * @return array<int, array{quote_id:int, email:string, store_id:int, updated_at:string}>
     */
    public function idleCarts(string $idleBeforeUtc, float $minValue, int $limit = 5000): array;

    /**
     * Sent $cart_abandoned / $checkout_abandoned rows created since $sinceUtc, newest first.
     * @return array<int, array{email:string, store_id:int, dedup_key:string}>
     */
    public function sentAbandonRows(string $sinceUtc): array;

    /**
     * @param int[] $quoteIds
     * @return array<int, string[]> quote id => customer_email of each order on it that was paid at some point
     */
    public function paidOrderEmails(array $quoteIds): array;
}
