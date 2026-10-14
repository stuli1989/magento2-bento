<?php
/**
 * $review_submitted — a shopper submitted a product review on the storefront (still pending moderation,
 * so the thank-you is immediate).
 *
 * details: product{sku, name, url, image_url}, rating (1–5, mean of the rating votes rounded; null when
 * none), review_id, status (pending | approved | not_approved), nickname.
 *
 * Recipient: the row's email (registered customer), else the subscriber behind the row's bento_uuid
 * (guest who arrived from a Bento email). No identity → skipped, never sent.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Review\ReviewSnapshotLoader;
use ArtLounge\BentoEvents\Model\Review\SubscriberEmailResolver;

class ReviewSubmittedBuilder implements BuilderInterface
{
    private const STATUS = [1 => 'approved', 2 => 'pending', 3 => 'not_approved'];

    public function __construct(
        private readonly ReviewSnapshotLoader $reviews,
        private readonly CatalogLookup $catalog,
        private readonly SubscriberEmailResolver $subscribers,
        private readonly EventEnvelope $envelope
    ) {
    }

    public function build(OutboxRow $row): BuildResult
    {
        $review = $this->reviews->load($row->entityId);
        if ($review === null) {
            return BuildResult::skip('review ' . $row->entityId . ' not found');
        }

        $email = $row->email;
        if ($email === '') {
            $uuid = (string)$row->contextValue('bento_uuid', '');
            $email = $uuid !== '' ? (string)$this->subscribers->emailForUuid($uuid, $row->storeId ?: null) : '';
        }
        if ($email === '') {
            return BuildResult::skip('no identity: guest review without a known Bento subscriber');
        }

        $storeId = (int)($review['store_id'] ?: $row->storeId);
        $facts = $this->catalog->forProducts([(int)$review['product_id']], $storeId)[(int)$review['product_id']] ?? [];

        $details = [
            'product' => [
                'sku' => $facts['sku'] ?? null,
                'name' => $facts['name'] ?? null,
                'url' => $facts['url'] ?? null,
                'image_url' => (string)($facts['image'] ?? ''),
            ],
            'rating' => self::rating($review['votes'] ?? []),
            'review_id' => (int)$review['review_id'],
            'status' => self::STATUS[(int)$review['status_id']] ?? 'pending',
            'nickname' => (string)($review['nickname'] ?? ''),
        ];

        $event = $this->envelope->wrap($row, '$review_submitted', $details);
        $event['email'] = $email;

        return BuildResult::event($event);
    }

    /** @param int[] $votes 1–5 per rating entity */
    private static function rating(array $votes): ?int
    {
        if (!$votes) {
            return null;
        }
        $mean = array_sum($votes) / count($votes);

        return max(1, min(5, (int)round($mean)));
    }
}
