<?php
/**
 * Outcome of one Bento API call, classified for the outbox:
 *  - success:   2xx
 *  - retryable: transport error / timeout, 429, 5xx, or "not configured"
 *  - permanent: any other 4xx (park immediately, keep the response body)
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

final class SendResult
{
    public function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly ?string $error
    ) {
    }

    public static function notConfigured(): self
    {
        return new self(0, '', 'Bento is disabled or keys are missing');
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function isRetryable(): bool
    {
        if ($this->isSuccess()) {
            return false;
        }
        return $this->error !== null || $this->status === 429 || $this->status >= 500 || $this->status === 0;
    }

    public function isPermanent(): bool
    {
        return !$this->isSuccess() && !$this->isRetryable();
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function describe(): string
    {
        $msg = $this->error !== null ? 'transport: ' . $this->error : 'HTTP ' . $this->status;
        $body = trim(mb_substr($this->body, 0, 1000));

        return $body !== '' ? $msg . ' — ' . $body : $msg;
    }
}
