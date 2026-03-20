<?php
/**
 * Bento Client Interface
 *
 * Provides methods for communicating with the Bento API.
 * Uses the official Bento batch events API with Basic Auth.
 *
 * @see https://bentonow.com/docs/developer_guides/introduction
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoCore
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Api;

interface BentoClientInterface
{
    /**
     * Send event to Bento API
     *
     * Uses POST https://app.bentonow.com/api/v1/batch/events with Basic Auth.
     * The data is automatically formatted into Bento's expected structure.
     *
     * @param string $eventType Event type (e.g., $purchase, $abandoned)
     * @param array $data Event data payload
     * @param int|null $storeId Store ID for configuration
     * @return array Response data with 'success' and 'message' keys
     */
    public function sendEvent(string $eventType, array $data, ?int $storeId = null): array;

    /**
     * Test connection to Bento API
     *
     * @param int|null $storeId Store ID for configuration
     * @return array Response data with 'success', 'message', and 'response_time_ms' keys
     */
    public function testConnection(?int $storeId = null): array;
}
