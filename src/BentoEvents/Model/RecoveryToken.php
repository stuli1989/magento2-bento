<?php
/**
 * Recovery Token
 *
 * Generates and validates signed, expiring cart recovery tokens.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;

class RecoveryToken
{
    private const TOKEN_VERSION = 'v1';
    private const DEFAULT_TTL_SECONDS = 604800; // 7 days

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Json $json,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Generate a signed token for cart recovery links.
     *
     * @param int $quoteId
     * @param string $email
     * @param int $storeId
     * @return string|null
     */
    public function generate(int $quoteId, string $email, int $storeId): ?string
    {
        $normalizedEmail = strtolower(trim($email));
        if ($quoteId < 1 || $normalizedEmail === '') {
            return null;
        }

        $payload = [
            'v' => self::TOKEN_VERSION,
            'q' => $quoteId,
            'e' => $normalizedEmail,
            's' => $storeId,
            'x' => $this->dateTime->gmtTimestamp() + self::DEFAULT_TTL_SECONDS
        ];

        $payloadJson = $this->json->serialize($payload);
        $encodedPayload = $this->base64UrlEncode($payloadJson);
        $signature = hash_hmac('sha256', $encodedPayload, $this->getSigningSecret($storeId));

        return $encodedPayload . '.' . $signature;
    }

    /**
     * Parse and validate a recovery token.
     *
     * @param string $token
     * @return array{quote_id:int,email:string,store_id:int}
     */
    public function parse(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('Invalid token format');
        }

        [$encodedPayload, $providedSignature] = $parts;

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            throw new \InvalidArgumentException('Invalid token payload');
        }

        $payload = $this->json->unserialize($payloadJson);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid token data');
        }

        $quoteId = isset($payload['q']) ? (int)$payload['q'] : 0;
        $email = isset($payload['e']) ? trim((string)$payload['e']) : '';
        $storeId = isset($payload['s']) ? (int)$payload['s'] : 0;
        $expiresAt = isset($payload['x']) ? (int)$payload['x'] : 0;
        $version = isset($payload['v']) ? (string)$payload['v'] : '';

        if ($version !== self::TOKEN_VERSION || $quoteId < 1 || $email === '' || $expiresAt < 1) {
            throw new \InvalidArgumentException('Invalid token data');
        }

        if ($expiresAt < $this->dateTime->gmtTimestamp()) {
            throw new \InvalidArgumentException('Token has expired');
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->getSigningSecret($storeId));
        if (!hash_equals($expectedSignature, $providedSignature)) {
            throw new \InvalidArgumentException('Invalid token signature');
        }

        return [
            'quote_id' => $quoteId,
            'email' => strtolower($email),
            'store_id' => $storeId
        ];
    }

    private function getSigningSecret(int $storeId): string
    {
        $secret = $this->config->getSecretKey($storeId);
        if (!$secret) {
            throw new \RuntimeException('Bento secret key is not configured');
        }

        return $secret;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
