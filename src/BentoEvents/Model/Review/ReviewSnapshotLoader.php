<?php
/**
 * Review → plain snapshot for ReviewSubmittedBuilder, read at send time (by then the controller has
 * also saved the rating votes, which happens after the review row itself is saved).
 *
 * source: ReviewSource::COLUMN when Artlounge_Wismo has added it ('whatsapp' for WhatsApp reviews), else null.
 *
 * @return array{review_id:int, product_id:int, store_id:int, status_id:int, nickname:?string,
 *               customer_id:?int, created_at:?string, votes:int[], source:?string}|null
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Review;

use Magento\Framework\App\ResourceConnection;

class ReviewSnapshotLoader
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function load(int $reviewId): ?array
    {
        $conn = $this->resource->getConnection();
        $detailColumns = ['nickname', 'customer_id', 'store_id'];
        $detailTable = $this->resource->getTableName(ReviewSource::TABLE);
        $hasSource = (bool)$conn->tableColumnExists($detailTable, ReviewSource::COLUMN);
        if ($hasSource) {
            $detailColumns['source'] = ReviewSource::COLUMN;
        }
        $row = $conn->fetchRow(
            $conn->select()
                ->from(['r' => $this->resource->getTableName('review')], ['review_id', 'entity_pk_value', 'status_id', 'created_at'])
                ->join(
                    ['d' => $detailTable],
                    'd.review_id = r.review_id',
                    $detailColumns
                )
                ->where('r.review_id = ?', $reviewId)
                ->limit(1)
        );
        if (!$row) {
            return null;
        }
        $votes = $conn->fetchCol(
            $conn->select()
                ->from($this->resource->getTableName('rating_option_vote'), ['value'])
                ->where('review_id = ?', $reviewId)
        );

        return [
            'review_id' => (int)$row['review_id'],
            'product_id' => (int)$row['entity_pk_value'],
            'store_id' => (int)$row['store_id'],
            'status_id' => (int)$row['status_id'],
            'nickname' => $row['nickname'] !== null ? (string)$row['nickname'] : null,
            'customer_id' => !empty($row['customer_id']) ? (int)$row['customer_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'votes' => array_map('intval', $votes),
            'source' => isset($row['source']) && $row['source'] !== '' ? (string)$row['source'] : null,
        ];
    }
}
