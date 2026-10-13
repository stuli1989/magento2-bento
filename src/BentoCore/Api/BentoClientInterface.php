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

    /** @return array{success:bool, message:string, response_time_ms?:int} */
    public function testConnection(?int $storeId = null): array;
}
