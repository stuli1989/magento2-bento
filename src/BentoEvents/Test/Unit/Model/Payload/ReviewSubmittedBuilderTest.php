<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\CatalogLookup;
use ArtLounge\BentoEvents\Model\Payload\EventEnvelope;
use ArtLounge\BentoEvents\Model\Payload\ReviewSubmittedBuilder;
use ArtLounge\BentoEvents\Model\Review\ReviewSnapshotLoader;
use ArtLounge\BentoEvents\Model\Review\SubscriberEmailResolver;
use PHPUnit\Framework\TestCase;

class ReviewSubmittedBuilderTest extends TestCase
{
    private ReviewSnapshotLoader $reviews;
    private CatalogLookup $catalog;
    private SubscriberEmailResolver $resolver;

    protected function setUp(): void
    {
        $this->reviews = $this->createMock(ReviewSnapshotLoader::class);
        $this->catalog = $this->createMock(CatalogLookup::class);
        $this->catalog->method('forProducts')->willReturn([500 => [
            'sku' => 'S-ARCHES-300', 'name' => 'Arches Cold Press Watercolour Block, 300 gsm',
            'url' => 'https://www.artlounge.in/arches-block.html',
            'image' => 'https://d1f0kjhjeqrfvd.cloudfront.net/media/catalog/product/cache/h/S/-/S-ARCHES.jpg',
            'brand' => 'Arches', 'categories' => ['Surfaces'],
        ]]);
        $this->resolver = $this->createMock(SubscriberEmailResolver::class);
    }

    private function builder(): ReviewSubmittedBuilder
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getSource')->willReturn('artlounge');

        return new ReviewSubmittedBuilder($this->reviews, $this->catalog, $this->resolver, new EventEnvelope($config));
    }

    private function review(array $o = []): array
    {
        return array_replace([
            'review_id' => 18342, 'product_id' => 500, 'store_id' => 1, 'status_id' => 2, 'nickname' => 'Meera',
            'customer_id' => 77, 'created_at' => '2026-10-14 05:00:00', 'votes' => [5],
        ], $o);
    }

    private function row(string $email, array $context = []): OutboxRow
    {
        return OutboxRow::fromArray([
            'id' => 9, 'event_type' => 'review_submitted', 'entity_type' => 'review', 'entity_id' => 18342,
            'email' => $email, 'store_id' => 1, 'dedup_key' => 'review_submitted:18342',
            'created_at' => '2026-10-14 05:00:02', 'context' => $context ? json_encode($context) : null,
        ]);
    }

    public function testRegisteredCustomerReviewPayload(): void
    {
        $this->reviews->method('load')->with(18342)->willReturn($this->review());
        $this->resolver->expects($this->never())->method('emailForUuid');

        $r = $this->builder()->build($this->row('meera+bentotest@example.com'));

        $this->assertFalse($r->isSkip());
        $this->assertNull($r->subscriber);
        $this->assertSame([
            'type' => '$review_submitted',
            'email' => 'meera+bentotest@example.com',
            'details' => [
                'product' => [
                    'sku' => 'S-ARCHES-300',
                    'name' => 'Arches Cold Press Watercolour Block, 300 gsm',
                    'url' => 'https://www.artlounge.in/arches-block.html',
                    'image_url' => 'https://d1f0kjhjeqrfvd.cloudfront.net/media/catalog/product/cache/h/S/-/S-ARCHES.jpg',
                ],
                'rating' => 5,
                'review_id' => 18342,
                'status' => 'pending',
                'nickname' => 'Meera',
                'unique' => ['key' => 'review_submitted:18342'],
                'source' => 'artlounge',
                'timestamp' => '2026-10-14T10:30:02+05:30',
            ],
        ], $r->event);
    }

    public function testGuestReviewIsAttributedThroughTheBentoUuid(): void
    {
        $this->reviews->method('load')->willReturn($this->review(['customer_id' => null, 'status_id' => 1, 'votes' => [4, 5]]));
        $this->resolver->method('emailForUuid')->with('abcdef12', 1)->willReturn('guest+bentotest@example.com');

        $e = $this->builder()->build($this->row('', ['bento_uuid' => 'abcdef12']))->event;

        $this->assertSame('guest+bentotest@example.com', $e['email']);
        $this->assertSame(5, $e['details']['rating'], 'mean of 4 and 5, rounded');
        $this->assertSame('approved', $e['details']['status']);
    }

    public function testUnknownUuidIsSkippedNotSent(): void
    {
        $this->reviews->method('load')->willReturn($this->review(['customer_id' => null]));
        $this->resolver->method('emailForUuid')->willReturn(null);

        $r = $this->builder()->build($this->row('', ['bento_uuid' => 'abcdef12']));

        $this->assertTrue($r->isSkip());
        $this->assertStringContainsString('identity', (string)$r->skipReason);
    }

    public function testNoEmailAndNoUuidIsSkipped(): void
    {
        $this->reviews->method('load')->willReturn($this->review());
        $this->assertTrue($this->builder()->build($this->row(''))->isSkip());
    }

    public function testNoVotesMeansNullRatingAndMissingProductFactsAreBlankSafe(): void
    {
        $this->reviews->method('load')->willReturn($this->review(['votes' => [], 'product_id' => 999, 'nickname' => null]));

        $d = $this->builder()->build($this->row('meera+bentotest@example.com'))->event['details'];

        $this->assertArrayHasKey('rating', $d);
        $this->assertNull($d['rating']);
        $this->assertSame(['sku' => null, 'name' => null, 'url' => null, 'image_url' => ''], $d['product']);
        $this->assertSame('', $d['nickname']);
    }

    public function testRatingIsClampedToOneToFiveAndStatusMapped(): void
    {
        $this->reviews->method('load')->willReturnOnConsecutiveCalls(
            $this->review(['votes' => [9], 'status_id' => 3]),
            $this->review(['votes' => [0, 1]]),
            $this->review(['votes' => [2, 3]])
        );

        $a = $this->builder()->build($this->row('x+bentotest@example.com'))->event['details'];
        $b = $this->builder()->build($this->row('x+bentotest@example.com'))->event['details'];
        $c = $this->builder()->build($this->row('x+bentotest@example.com'))->event['details'];

        $this->assertSame(5, $a['rating']);
        $this->assertSame('not_approved', $a['status']);
        $this->assertSame(1, $b['rating']);
        $this->assertSame(3, $c['rating'], '2.5 rounds half up');
    }

    public function testDeletedReviewIsSkipped(): void
    {
        $this->reviews->method('load')->willReturn(null);
        $this->assertTrue($this->builder()->build($this->row('x+bentotest@example.com'))->isSkip());
    }
}
