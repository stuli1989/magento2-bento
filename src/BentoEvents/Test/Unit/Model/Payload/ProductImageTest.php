<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoEvents\Model\Payload\ProductImage;
use ArtLounge\BentoEvents\Model\Payload\ThumbnailUrlBuilder;
use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductImageTest extends TestCase
{
    public function testPicksThumbnailThenSmallImageThenBaseImage(): void
    {
        $this->assertSame('/t.jpg', ProductImage::pickFile(['thumbnail' => '/t.jpg', 'small_image' => '/s.jpg', 'image' => '/i.jpg']));
        $this->assertSame('/s.jpg', ProductImage::pickFile(['thumbnail' => 'no_selection', 'small_image' => '/s.jpg', 'image' => '/i.jpg']));
        $this->assertSame('/i.jpg', ProductImage::pickFile(['thumbnail' => null, 'small_image' => '', 'image' => '/i.jpg']));
        $this->assertNull(ProductImage::pickFile(['thumbnail' => 'no_selection', 'small_image' => 'no_selection']));
        $this->assertNull(ProductImage::pickFile([]));
    }

    public function testFallsBackToTheConfigurableParentsImage(): void
    {
        $files = ProductImage::resolve(
            [1 => ['thumbnail' => '/own.jpg'], 2 => ['thumbnail' => 'no_selection'], 3 => []],
            [2 => 20, 3 => 30],
            [20 => ['small_image' => '/parent.jpg'], 30 => ['image' => 'no_selection']]
        );

        $this->assertSame([1 => '/own.jpg', 2 => '/parent.jpg', 3 => null], $files);
    }

    private function builder(UrlBuilder $urls, ?Emulation $emulation = null): ThumbnailUrlBuilder
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn('https://d1f0kjhjeqrfvd.cloudfront.net/media/');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new ThumbnailUrlBuilder($urls, $emulation ?? $this->createMock(Emulation::class), $stores,
            $this->createMock(LoggerInterface::class), 'wishlist_small_image');
    }

    public function testBuildsCachedThumbnailUrlsInsideFrontendEmulationOfTheStore(): void
    {
        $calls = [];
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->once())->method('startEnvironmentEmulation')
            ->willReturnCallback(function ($storeId, $area, $force) use (&$calls, $emulation) {
                $calls[] = ['start', $storeId, $area, $force];
                return $emulation;
            });
        $emulation->expects($this->once())->method('stopEnvironmentEmulation')
            ->willReturnCallback(function () use (&$calls, $emulation) {
                $calls[] = ['stop'];
                return $emulation;
            });
        $urls = $this->createMock(UrlBuilder::class);
        $urls->method('getUrl')->willReturnCallback(function (string $file, string $id) use (&$calls) {
            $calls[] = ['url', $file, $id];
            return 'https://d1f0kjhjeqrfvd.cloudfront.net/media/catalog/product/cache/abc123' . $file;
        });

        $out = $this->builder($urls, $emulation)->urls([11 => '/a/b/ab.jpg', 12 => null], 1);

        $this->assertSame([
            11 => 'https://d1f0kjhjeqrfvd.cloudfront.net/media/catalog/product/cache/abc123/a/b/ab.jpg',
            12 => null,
        ], $out);
        $this->assertSame([['start', 1, 'frontend', true], ['url', '/a/b/ab.jpg', 'wishlist_small_image'], ['stop']], $calls);
    }

    public function testFallsBackToTheOriginalMediaUrlWhenTheResizedUrlCannotBeBuilt(): void
    {
        $urls = $this->createMock(UrlBuilder::class);
        $urls->method('getUrl')->willThrowException(new \RuntimeException('no view config'));

        $out = $this->builder($urls)->urls([11 => '/a/b/ab.jpg'], 1);

        $this->assertSame([11 => 'https://d1f0kjhjeqrfvd.cloudfront.net/media/catalog/product/a/b/ab.jpg'], $out);
    }

    public function testEmulationFailureStillYieldsAbsoluteUrlsAndNeverThrows(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willThrowException(new \RuntimeException('Area code is not set'));
        $emulation->expects($this->never())->method('stopEnvironmentEmulation');
        $urls = $this->createMock(UrlBuilder::class);
        $urls->expects($this->never())->method('getUrl');

        $out = $this->builder($urls, $emulation)->urls([11 => '/a/b/ab.jpg'], 1);

        $this->assertSame([11 => 'https://d1f0kjhjeqrfvd.cloudfront.net/media/catalog/product/a/b/ab.jpg'], $out);
    }

    public function testNoFilesMeansNoEmulation(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->never())->method('startEnvironmentEmulation');

        $this->assertSame([5 => null], $this->builder($this->createMock(UrlBuilder::class), $emulation)->urls([5 => null], 1));
    }
}
