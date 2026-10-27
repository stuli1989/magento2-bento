<?php
/**
 * Contract with Artlounge_Wismo (WhatsApp reviews, 00g §3): a review created from a WhatsApp review Flow
 * carries `review_detail.artlounge_source = 'whatsapp'`. Artlounge_Wismo owns that column (its
 * db_schema.xml adds it, varchar(32), nullable; storefront reviews leave it NULL). WISMO posts its own
 * $review_submitted (details.channel = "whatsapp") for those, so this plugin must not send a second one.
 *
 * The plugin only reads the column, and only when it exists, so it works with or without Artlounge_Wismo.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Review;

final class ReviewSource
{
    public const TABLE = 'review_detail';
    public const COLUMN = 'artlounge_source';
    public const WHATSAPP = 'whatsapp';
}
