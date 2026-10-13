<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

final class BuildResult
{
    private function __construct(
        public readonly ?array $event,
        public readonly ?array $subscriber,
        public readonly ?string $skipReason
    ) {
    }

    /**
     * @param array $event Bento event {type, email, fields?, details}
     * @param array|null $subscriber when set, imported via /batch/subscribers BEFORE the event
     */
    public static function event(array $event, ?array $subscriber = null): self
    {
        return new self($event, $subscriber, null);
    }

    public static function skip(string $reason): self
    {
        return new self(null, null, $reason);
    }

    public function isSkip(): bool
    {
        return $this->skipReason !== null;
    }
}
