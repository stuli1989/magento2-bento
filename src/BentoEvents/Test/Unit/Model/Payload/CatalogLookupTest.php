<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Payload\CatalogLookup;
use ArtLounge\BentoEvents\Model\Payload\ThumbnailUrlBuilder;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CatalogLookupTest extends TestCase
{
    private function product(int $id, array $data): object
    {
        return new class ($id, $data) {
            public function __construct(private int $id, private array $data)
            {
            }
            public function getId(): int { return $this->id; }
            public function getSku() { return $this->data['sku'] ?? null; }
            public function getName() { return $this->data['name'] ?? null; }
            public function getProductUrl() { return $this->data['url'] ?? null; }
            public function getCategoryIds(): array { return []; }
            public function getData($key) { return $this->data[$key] ?? null; }
            public function getAttributeText($key) { return null; }
        };
    }

    private function lookup(array $collections, array $superLinks, ?array &$thumbFiles): CatalogLookup
    {
        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...array_map(
            static fn (array $items) => (new Collection())->setItems($items),
            $collections
        ));

        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('select')->willReturn(new Select());
        $conn->method('fetchPairs')->willReturn($superLinks);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);

        $thumbs = $this->createMock(ThumbnailUrlBuilder::class);
        $thumbs->method('urls')->willReturnCallback(function (array $files, int $storeId) use (&$thumbFiles) {
            $thumbFiles = $files;
            return array_map(static fn ($f) => $f === null ? null : 'https://cdn/media/catalog/product/cache/h' . $f, $files);
        });

        $config = $this->createMock(ConfigInterface::class);
        $config->method('getBrandAttribute')->willReturn('brand');

        return new CatalogLookup($factory, $this->createMock(CategoryCollectionFactory::class), $thumbs, $resource,
            $config, $this->createMock(LoggerInterface::class));
    }

    public function testThumbnailWithParentFallbackAndSkuName(): void
    {
        $thumbFiles = null;
        $lookup = $this->lookup(
            [
                [
                    $this->product(11, ['sku' => 'A', 'name' => 'Alpha', 'url' => 'https://x/a',
                        'thumbnail' => '/a/t.jpg', 'image' => '/a/i.jpg']),
                    $this->product(12, ['sku' => 'B-RED', 'name' => 'Beta Red', 'thumbnail' => 'no_selection']),
                    $this->product(13, ['sku' => 'C', 'name' => 'Gamma']),
                ],
                [$this->product(20, ['small_image' => '/p/s.jpg'])], // parents
            ],
            [12 => '20'],
            $thumbFiles
        );

        $out = $lookup->forProducts([11, 12, 13], 1);

        $this->assertSame([11 => '/a/t.jpg', 12 => '/p/s.jpg', 13 => null], $thumbFiles);
        $this->assertSame('https://cdn/media/catalog/product/cache/h/a/t.jpg', $out[11]['image']);
        $this->assertSame('https://cdn/media/catalog/product/cache/h/p/s.jpg', $out[12]['image']);
        $this->assertNull($out[13]['image']);
        $this->assertSame('A', $out[11]['sku']);
        $this->assertSame('Beta Red', $out[12]['name']);
        $this->assertSame('https://x/a', $out[11]['url']);
    }

    public function testNoParentQueryWhenEveryProductHasItsOwnImage(): void
    {
        $thumbFiles = null;
        $lookup = $this->lookup([[$this->product(11, ['small_image' => '/s.jpg'])]], [], $thumbFiles);

        $out = $lookup->forProducts([11], 1);

        $this->assertSame([11 => '/s.jpg'], $thumbFiles);
        $this->assertSame('https://cdn/media/catalog/product/cache/h/s.jpg', $out[11]['image']);
    }
}
