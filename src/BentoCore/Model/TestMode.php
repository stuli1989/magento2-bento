<?php
/**
 * Test-mode email allow-list.
 *
 * When at least one glob pattern is configured, only rows whose email matches a
 * pattern may be sent to Bento; everything else is marked `skipped`. Used locally
 * (local Magento talks to the PRODUCTION Bento site). Empty on prod = off.
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

final class TestMode
{
    /** @var string[] */
    private array $patterns;

    /** @var string[] */
    private array $regexes;

    /**
     * @param string[] $patterns Shell-style globs, `*` = any run of characters.
     */
    public function __construct(array $patterns)
    {
        $this->patterns = array_values(array_filter(array_map('trim', $patterns), 'strlen'));
        $this->regexes = array_map(
            static fn (string $glob): string => '/^' . str_replace('\*', '.*', preg_quote(strtolower($glob), '/')) . '$/',
            $this->patterns
        );
    }

    public static function fromString(?string $raw): self
    {
        return new self(preg_split('/[,\r\n]+/', (string)$raw) ?: []);
    }

    public function isActive(): bool
    {
        return $this->patterns !== [];
    }

    public function allows(string $email): bool
    {
        if (!$this->isActive()) {
            return true;
        }
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        foreach ($this->regexes as $regex) {
            if (preg_match($regex, $email) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    public function getPatterns(): array
    {
        return $this->patterns;
    }
}
