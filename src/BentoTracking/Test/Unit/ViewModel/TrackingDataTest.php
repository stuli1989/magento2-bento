<?php
/**
 * Tracking Data ViewModel Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoTracking\Test\Unit\ViewModel;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoTracking\ViewModel\TrackingData;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\TestCase;

class TrackingDataTest extends TestCase
{
    public function testGetProductDataJsonReturnsEmptyWhenNoProduct(): void
    {
        $viewModel = $this->createViewModel(null);

        $this->assertSame('{}', $viewModel->getProductDataJson());
    }

    public function testGetProductDataJsonIncludesBrandAndSpecialPrice(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(10);
        $product->method('getSku')->willReturn('SKU');
        $product->method('getName')->willReturn('Product');
        $product->method('getFinalPrice')->willReturn(80.0);
        $product->method('getProductUrl')->willReturn('https://example.com/product');
        $product->method('getCategoryIds')->willReturn([1]);
        $product->method('getData')->willReturnCallback(fn ($k) => $k === 'brand' ? 'Brand' : null);
        $product->method('getAttributeText')->with('brand')->willReturn('Brand');
        $product->method('getSpecialPrice')->willReturn(80.0);
        $product->method('getPrice')->willReturn(100.0);

        $viewModel = $this->createViewModel($product);

        $json = $viewModel->getProductDataJson();
        $data = json_decode($json, true);

        $this->assertSame('Brand', $data['brand']);
        $this->assertSame(8000, $data['special_price']);
        $this->assertSame(10000, $data['original_price']);
        $this->assertSame('Category', $data['categories']);
    }

    public function testGetProductDataJsonBasicFields(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $product->method('getSku')->willReturn('TEST-SKU');
        $product->method('getName')->willReturn('Test Product');
        $product->method('getFinalPrice')->willReturn(49.99);
        $product->method('getProductUrl')->willReturn('https://example.com/test');
        $product->method('getCategoryIds')->willReturn([]);
        $product->method('getData')->willReturn(null);
        $product->method('getSpecialPrice')->willReturn(null);
        $product->method('getPrice')->willReturn(49.99);

        $viewModel = $this->createViewModel($product);
        $data = json_decode($viewModel->getProductDataJson(), true);

        $this->assertSame(5, $data['product_id']);
        $this->assertSame('TEST-SKU', $data['sku']);
        $this->assertSame('Test Product', $data['name']);
        $this->assertSame(4999, $data['price']);
        $this->assertSame('https://example.com/test', $data['url']);
        $this->assertTrue($data['in_stock']);
        $this->assertSame('INR', $data['currency_code']);
    }

    public function testGetProductDataJsonNoBrandWhenAttributeEmpty(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $product->method('getSku')->willReturn('SKU');
        $product->method('getName')->willReturn('Product');
        $product->method('getFinalPrice')->willReturn(10.0);
        $product->method('getProductUrl')->willReturn('https://example.com');
        $product->method('getCategoryIds')->willReturn([]);
        $product->method('getData')->willReturnCallback(fn ($k) => $k === 'brand' ? null : null);
        $product->method('getSpecialPrice')->willReturn(null);
        $product->method('getPrice')->willReturn(10.0);

        $viewModel = $this->createViewModel($product);
        $data = json_decode($viewModel->getProductDataJson(), true);

        $this->assertArrayNotHasKey('brand', $data);
    }

    public function testGetProductDataJsonNoSpecialPriceWhenNotLessThanRegular(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $product->method('getSku')->willReturn('SKU');
        $product->method('getName')->willReturn('Product');
        $product->method('getFinalPrice')->willReturn(100.0);
        $product->method('getProductUrl')->willReturn('https://example.com');
        $product->method('getCategoryIds')->willReturn([]);
        $product->method('getData')->willReturn(null);
        $product->method('getSpecialPrice')->willReturn(100.0);
        $product->method('getPrice')->willReturn(100.0);

        $viewModel = $this->createViewModel($product);
        $data = json_decode($viewModel->getProductDataJson(), true);

        $this->assertArrayNotHasKey('special_price', $data);
        $this->assertArrayNotHasKey('original_price', $data);
    }

    public function testIsEnabledDelegatesToConfig(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertTrue($viewModel->isEnabled());
    }

    public function testIsProductViewEnabledDelegatesToConfig(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertTrue($viewModel->isProductViewEnabled());
    }

    public function testReviewAttributionFollowsTheReviewEventsFlag(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertTrue($viewModel->isReviewAttributionEnabled());
    }

    public function testGetCurrencyCodeReturnsStoreCode(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertSame('INR', $viewModel->getCurrencyCode());
    }

    public function testGetCurrentProductFromRegistry(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $viewModel = $this->createViewModel($product);
        $this->assertSame($product, $viewModel->getCurrentProduct());
    }

    public function testGetCurrentProductNullWhenNoProduct(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertNull($viewModel->getCurrentProduct());
    }

    private function createViewModel(?ProductInterface $product): TrackingData
    {
        $config = $this->createMock(ConfigInterface::class);
        $registry = $this->createMock(Registry::class);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $imageHelper = $this->createMock(ImageHelper::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $json = $this->createMock(Json::class);
        $checkoutSession = $this->createMock(CheckoutSession::class);

        $config->method('getBrandAttribute')->willReturn('brand');
        $config->method('isTrackingEnabled')->willReturn(true);
        $config->method('isTrackViewsEnabled')->willReturn(true);
        $config->method('isReviewEventsEnabled')->willReturn(true);
        $config->method('includeBrand')->willReturn(true);

        $registry->method('registry')->with('current_product')->willReturn($product);

        $loadedProduct = $this->createMock(ProductInterface::class);
        $loadedProduct->method('getData')->willReturnCallback(fn ($k) => $k === 'brand' ? 'Brand' : null);
        $loadedProduct->method('getAttributeText')->with('brand')->willReturn('Brand');
        $productRepository->method('getById')->willReturn($loadedProduct);

        $image = $this->createMock(ImageHelper::class);
        $image->method('init')->willReturnSelf();
        $image->method('getUrl')->willReturn('https://example.com/image.jpg');
        $imageHelper->method('init')->willReturn($image);

        $category = $this->createMock(CategoryInterface::class);
        $category->method('getName')->willReturn('Category');
        $categoryRepository->method('get')->willReturn($category);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCurrentCurrencyCode')->willReturn('INR');
        $storeManager->method('getStore')->willReturn($store);

        $json->method('serialize')->willReturnCallback(function (array $data): string {
            return json_encode($data);
        });

        return new TrackingData(
            $config,
            $registry,
            $categoryRepository,
            $stockRegistry,
            $storeManager,
            $json
        );
    }
}
