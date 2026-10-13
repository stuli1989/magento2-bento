<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;

interface BuilderInterface
{
    /**
     * Build the Bento payload for an outbox row at send time.
     * Return BuildResult::skip() when the event no longer applies (entity gone, already converted…).
     * Throwing = transient failure (retried with backoff).
     */
    public function build(OutboxRow $row): BuildResult;
}
