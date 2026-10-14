<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Review;

use ArtLounge\BentoEvents\Model\Review\VisitorUuid;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;

class VisitorUuidTest extends TestCase
{
    /**
     * @dataProvider values
     */
    public function testNormalize(?string $in, ?string $out): void
    {
        $this->assertSame($out, VisitorUuid::normalize($in));
    }

    public static function values(): array
    {
        return [
            'uuid v4' => ['9b2f0c1e-4a5b-4c3d-8e9f-0a1b2c3d4e5f', '9b2f0c1e-4a5b-4c3d-8e9f-0a1b2c3d4e5f'],
            'hex token' => ['a1b2c3d4e5f6a7b8', 'a1b2c3d4e5f6a7b8'],
            'trimmed' => ['  abcdef12  ', 'abcdef12'],
            'too short' => ['abc', null],
            'too long' => [str_repeat('a', 65), null],
            'script' => ['<script>alert(1)</script>', null],
            'spaces inside' => ['abc def 123', null],
            'empty' => ['', null],
            'null' => [null, null],
            'liquid left unrendered' => ['{{ visitor.uuid }}', null],
        ];
    }

    public function testFromUrlReadsTheBentoUuidQueryParam(): void
    {
        $this->assertSame('abcdef12', VisitorUuid::fromUrl('https://www.artlounge.in/arches-block.html?utm_source=bento&bento_uuid=abcdef12#reviews'));
        $this->assertNull(VisitorUuid::fromUrl('https://www.artlounge.in/arches-block.html?utm_source=bento'));
        $this->assertNull(VisitorUuid::fromUrl(''));
        $this->assertNull(VisitorUuid::fromUrl(null));
    }

    private function source(?string $cookie, ?string $param, string $referer): VisitorUuid
    {
        $cookies = $this->createMock(CookieManagerInterface::class);
        $cookies->method('getCookie')->with(VisitorUuid::COOKIE)->willReturn($cookie);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('bento_uuid')->willReturn($param);
        $header = $this->createMock(Header::class);
        $header->method('getHttpReferer')->willReturn($referer);

        return new VisitorUuid($cookies, $request, $header);
    }

    public function testCurrentPrefersCookieThenRequestParamThenReferer(): void
    {
        $this->assertSame('cookie123', $this->source('cookie123', 'param1234', 'https://x/p?bento_uuid=referer1')->current());
        $this->assertSame('param1234', $this->source(null, 'param1234', 'https://x/p?bento_uuid=referer1')->current());
        $this->assertSame('referer1', $this->source('bad', null, 'https://x/p?bento_uuid=referer1')->current());
        $this->assertNull($this->source(null, null, 'https://x/p')->current());
    }
}
