<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Outbox;

final class OutboxRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $eventType,
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly string $email,
        public readonly int $storeId,
        public readonly string $dedupKey,
        public readonly array $context,
        public readonly string $status,
        public readonly int $attempts,
        public readonly string $createdAt,
        public readonly ?string $lastError = null,
        public readonly ?string $nextAttemptAt = null
    ) {
    }

    public static function fromArray(array $r): self
    {
        $context = [];
        if (!empty($r['context'])) {
            $decoded = is_array($r['context']) ? $r['context'] : json_decode((string)$r['context'], true);
            $context = is_array($decoded) ? $decoded : [];
        }

        return new self(
            (int)($r['id'] ?? 0),
            (string)($r['event_type'] ?? ''),
            (string)($r['entity_type'] ?? ''),
            (int)($r['entity_id'] ?? 0),
            (string)($r['email'] ?? ''),
            (int)($r['store_id'] ?? 0),
            (string)($r['dedup_key'] ?? ''),
            $context,
            (string)($r['status'] ?? OutboxStoreInterface::STATUS_PENDING),
            (int)($r['attempts'] ?? 0),
            (string)($r['created_at'] ?? gmdate('Y-m-d H:i:s')),
            isset($r['last_error']) ? (string)$r['last_error'] : null,
            isset($r['next_attempt_at']) ? (string)$r['next_attempt_at'] : null
        );
    }

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
}
