<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Model\Payload;

use ArtLounge\BentoEvents\Model\Payload\CatalogLookup;
use ArtLounge\BentoEvents\Model\Payload\QuoteSnapshotLoader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Quote\Api\CartRepositoryInterface;
use PHPUnit\Framework\TestCase;

class QuoteSnapshotLoaderTest extends TestCase
{
    private function item(int $itemId, int $productId, string $type, array $children = []): object
    {
        return new class ($itemId, $productId, $type, $children) {
            public function __construct(private int $itemId, private int $productId, private string $type, private array $children)
            {
            }
            public function getItemId(): int { return $this->itemId; }
            public function getProductId(): int { return $this->productId; }
            public function getProductType(): string { return $this->type; }
            public function getChildren(): array { return $this->children; }
            public function getSku(): string { return 'SKU' . $this->productId; }
            public function getName(): string { return 'Name ' . $this->productId; }
            public function getQty(): float { return 1.0; }
            public function getPrice(): float { return 100.0; }
            public function getRowTotal(): float { return 100.0; }
        };
    }

    public function testConfigurableLineUsesTheChosenVariantsImageAndTheParentsUrl(): void
    {
        $variant = $this->item(2, 501, 'simple');
        $quote = new class ([$this->item(1, 500, 'configurable', [$variant]), $this->item(3, 600, 'simple')]) {
            public function __construct(private array $items)
            {
            }
            public function getId(): int { return 9001; }
            public function getStoreId(): int { return 1; }
            public function getAllVisibleItems(): array { return $this->items; }
            public function isVirtual(): bool { return false; }
            public function getShippingAddress() { return null; }
            public function getBillingAddress() { return null; }
            public function getIsActive(): bool { return true; }
            public function getCreatedAt() { return null; }
            public function getUpdatedAt() { return null; }
            public function getQuoteCurrencyCode(): string { return 'INR'; }
            public function getGrandTotal(): float { return 200.0; }
            public function getSubtotal(): float { return 200.0; }
            public function getCustomerEmail() { return 'a@b.c'; }
            public function getCustomerFirstname() { return 'A'; }
            public function getCustomerLastname() { return 'B'; }
            public function getCustomerId() { return 77; }
        };
        $repo = $this->createMock(CartRepositoryInterface::class);
        $repo->method('get')->willReturn($quote);

        $catalog = $this->createMock(CatalogLookup::class);
        $catalog->expects($this->once())->method('forProducts')->with([500, 600, 501], 1)->willReturn([
            500 => ['url' => 'https://x/parent', 'image' => 'https://cdn/parent.jpg', 'categories' => []],
            501 => ['url' => null, 'image' => 'https://cdn/red.jpg', 'categories' => []],
            600 => ['url' => 'https://x/plain', 'image' => null, 'categories' => []],
        ]);

        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('select')->willReturn(new Select());
        $conn->method('fetchOne')->willReturn(false);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);

        $s = (new QuoteSnapshotLoader($repo, $catalog, $resource))->load(9001);

        $this->assertSame('https://cdn/red.jpg', $s['items'][0]['image']);
        $this->assertSame('https://x/parent', $s['items'][0]['url']);
        $this->assertNull($s['items'][1]['image']);
        $this->assertSame('https://x/plain', $s['items'][1]['url']);
        $this->assertSame(77, $s['customer_id']);
    }
}
