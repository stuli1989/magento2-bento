<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

class BuilderPool
{
    /**
     * @param array<string, BuilderInterface> $builders outbox event_type => builder (configured in di.xml)
     */
    public function __construct(private readonly array $builders = [])
    {
    }

    public function get(string $eventType): ?BuilderInterface
    {
        return $this->builders[$eventType] ?? null;
    }
}
