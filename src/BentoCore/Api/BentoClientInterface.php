<?php

declare(strict_types=1);

namespace ArtLounge\BentoCore\Api;

use ArtLounge\BentoCore\Model\SendResult;

interface BentoClientInterface
{
    /** POST /batch/events — each event: {type, email, fields?, details?} */
    public function postEvents(array $events, ?int $storeId = null): SendResult;

    /** POST /batch/subscribers — each: {email, ...fields} */
    public function importSubscribers(array $subscribers, ?int $storeId = null): SendResult;

    /**
     * GET /fetch/subscribers?uuid= — read-only lookup of the subscriber behind a `bento_uuid`
     * (Bento appends it to every email link). Body: {"data":{"attributes":{"email":…}}}; 404 = unknown.
     */
    public function fetchSubscriberByUuid(string $uuid, ?int $storeId = null): SendResult;

    /** @return array{success:bool, message:string, response_time_ms?:int} */
    public function testConnection(?int $storeId = null): array;
}
