<?php
/**
 * The Bento visitor uuid of the current shopper, for attributing guest reviews.
 *
 * Bento appends `bento_uuid=<subscriber uuid>` to every link in its emails. The browser layer
 * (bento-uuid.js) copies it into the first-party cookie `al_bento_uuid` on landing — client-side,
 * because full-page-cached responses can't set cookies. Order: that cookie → a `bento_uuid` request
 * param → the `bento_uuid` on the Referer (review form posted from a page opened from an email).
 *
 * The value is only an opaque lookup key: the email is resolved at send time via Bento's API.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Review;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\Stdlib\CookieManagerInterface;

class VisitorUuid
{
    public const COOKIE = 'al_bento_uuid';
    public const PARAM = 'bento_uuid';

    public function __construct(
        private readonly CookieManagerInterface $cookies,
        private readonly RequestInterface $request,
        private readonly Header $header
    ) {
    }

    public function current(): ?string
    {
        return self::normalize($this->scalar($this->cookies->getCookie(self::COOKIE)))
            ?? self::normalize($this->scalar($this->request->getParam(self::PARAM)))
            ?? self::fromUrl($this->scalar($this->header->getHttpReferer()));
    }

    /** UUIDs / hex tokens only (8–64 of [A-Za-z0-9_-]); anything else is ignored. */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string)$value);

        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value) ? $value : null;
    }

    public static function fromUrl(?string $url): ?string
    {
        $query = parse_url((string)$url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $params);

        return self::normalize(is_string($params[self::PARAM] ?? null) ? $params[self::PARAM] : null);
    }

    private function scalar(mixed $v): ?string
    {
        return is_scalar($v) ? (string)$v : null;
    }
}
