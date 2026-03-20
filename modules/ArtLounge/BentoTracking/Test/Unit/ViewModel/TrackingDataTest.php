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
        $product->method('getData')->with('brand')->willReturn('Brand');
        $product->method('getAttributeText')->with('brand')->willReturn('Brand');
        $product->method('getSpecialPrice')->willReturn(80.0);
        $product->method('getPrice')->willReturn(100.0);

        $viewModel = $this->createViewModel($product);

        $json = $viewModel->getProductDataJson();
        $data = json_decode($json, true);

        $this->assertSame('Brand', $data['brand']);
        $this->assertSame(8000, $data['special_price']);
        $this->assertSame(10000, $data['original_price']);
        $this->assertSame(['Category'], $data['categories']);
    }

    public function testGetLastOrderDataJsonReturnsOrderData(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $item = $this->createMock(OrderItemInterface::class);

        $order->method('getId')->willReturn(10);
        $order->method('getEntityId')->willReturn(10);
        $order->method('getIncrementId')->willReturn('00001');
        $order->method('getGrandTotal')->willReturn(200.0);
        $order->method('getTaxAmount')->willReturn(20.0);
        $order->method('getSubtotal')->willReturn(180.0);
        $order->method('getShippingAmount')->willReturn(10.0);
        $order->method('getOrderCurrencyCode')->willReturn('INR');
        $order->method('getCustomerEmail')->willReturn('test@example.com');
        $order->method('getCustomerFirstname')->willReturn('Test');
        $order->method('getCustomerLastname')->willReturn('User');
        $order->method('getAllVisibleItems')->willReturn([$item]);

        $item->method('getProductId')->willReturn(1);
        $item->method('getSku')->willReturn('SKU');
        $item->method('getName')->willReturn('Item');
        $item->method('getQtyOrdered')->willReturn(1);
        $item->method('getPrice')->willReturn(100.0);

        $viewModel = $this->createViewModel(null, $order);

        $json = $viewModel->getLastOrderDataJson();
        $data = json_decode($json, true);

        $this->assertSame('00001', $data['increment_id']);
        $this->assertSame(20000, $data['total']);
        $this->assertSame(1, $data['item_count']);
    }

    public function testGetLastOrderDataJsonIncludesBrandWhenEnabled(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $item = $this->createMock(OrderItemInterface::class);

        $order->method('getId')->willReturn(10);
        $order->method('getEntityId')->willReturn(10);
        $order->method('getIncrementId')->willReturn('00001');
        $order->method('getGrandTotal')->willReturn(200.0);
        $order->method('getTaxAmount')->willReturn(20.0);
        $order->method('getSubtotal')->willReturn(180.0);
        $order->method('getShippingAmount')->willReturn(10.0);
        $order->method('getOrderCurrencyCode')->willReturn('INR');
        $order->method('getCustomerEmail')->willReturn('test@example.com');
        $order->method('getCustomerFirstname')->willReturn('Test');
        $order->method('getCustomerLastname')->willReturn('User');
        $order->method('getAllVisibleItems')->willReturn([$item]);

        $item->method('getProductId')->willReturn(1);
        $item->method('getSku')->willReturn('SKU');
        $item->method('getName')->willReturn('Item');
        $item->method('getQtyOrdered')->willReturn(1);
        $item->method('getPrice')->willReturn(100.0);

        $viewModel = $this->createViewModel(null, $order);

        $json = $viewModel->getLastOrderDataJson();
        $data = json_decode($json, true);

        $this->assertSame('Brand', $data['items'][0]['brand']);
    }

    public function testGetLastOrderDataJsonReturnsEmptyWhenNoOrder(): void
    {
        $viewModel = $this->createViewModel(null, null);

        $this->assertSame('{}', $viewModel->getLastOrderDataJson());
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
        $product->method('getData')->with('brand')->willReturn(null);
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

    public function testIsAddToCartEnabledDelegatesToConfig(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertTrue($viewModel->isAddToCartEnabled());
    }

    public function testIsCheckoutEnabledDelegatesToConfig(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertTrue($viewModel->isCheckoutEnabled());
    }

    public function testGetAddToCartSelectorDelegatesToConfig(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertSame('#selector', $viewModel->getAddToCartSelector());
    }

    public function testGetCurrencyCodeReturnsStoreCode(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertSame('INR', $viewModel->getCurrencyCode());
    }

    public function testGetCurrencyMultiplierDelegatesToConfig(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertSame(100, $viewModel->getCurrencyMultiplier());
    }

    public function testIsPurchaseTrackingEnabledDelegatesToConfig(): void
    {
        $viewModel = $this->createViewModel(null);
        $this->assertTrue($viewModel->isPurchaseTrackingEnabled());
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

    public function testGetLastOrderDataJsonIncrementIdDisplay(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn(10);
        $order->method('getEntityId')->willReturn(10);
        $order->method('getIncrementId')->willReturn('100000099');
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getTaxAmount')->willReturn(10.0);
        $order->method('getSubtotal')->willReturn(90.0);
        $order->method('getShippingAmount')->willReturn(0.0);
        $order->method('getOrderCurrencyCode')->willReturn('INR');
        $order->method('getCustomerEmail')->willReturn('a@b.com');
        $order->method('getCustomerFirstname')->willReturn('A');
        $order->method('getCustomerLastname')->willReturn('B');
        $order->method('getAllVisibleItems')->willReturn([]);

        $viewModel = $this->createViewModel(null, $order);
        $data = json_decode($viewModel->getLastOrderDataJson(), true);

        $this->assertSame('100000099', $data['increment_id']);
        $this->assertSame('#100000099', $data['increment_id_display']);
        $this->assertSame(0, $data['item_count']);
        $this->assertSame([], $data['items']);
    }

    public function testGetLastOrderDataJsonCustomerData(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn(10);
        $order->method('getEntityId')->willReturn(10);
        $order->method('getIncrementId')->willReturn('00001');
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getTaxAmount')->willReturn(0.0);
        $order->method('getSubtotal')->willReturn(100.0);
        $order->method('getShippingAmount')->willReturn(0.0);
        $order->method('getOrderCurrencyCode')->willReturn('INR');
        $order->method('getCustomerEmail')->willReturn('john@example.com');
        $order->method('getCustomerFirstname')->willReturn('John');
        $order->method('getCustomerLastname')->willReturn('Doe');
        $order->method('getAllVisibleItems')->willReturn([]);

        $viewModel = $this->createViewModel(null, $order);
        $data = json_decode($viewModel->getLastOrderDataJson(), true);

        $this->assertSame('john@example.com', $data['email']);
        $this->assertSame('John', $data['customer_firstname']);
        $this->assertSame('Doe', $data['customer_lastname']);
    }

    private function createViewModel(?ProductInterface $product, ?OrderInterface $order = null): TrackingData
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

        $config->method('getCurrencyMultiplier')->willReturn(100);
        $config->method('getBrandAttribute')->willReturn('brand');
        $config->method('isTrackingEnabled')->willReturn(true);
        $config->method('isTrackViewsEnabled')->willReturn(true);
        $config->method('isTrackAddToCartEnabled')->willReturn(true);
        $config->method('isTrackCheckoutEnabled')->willReturn(true);
        $config->method('getAddToCartSelector')->willReturn('#selector');
        $config->method('includeTaxInTotals')->willReturn(true);
        $config->method('isTrackOrderPlacedEnabled')->willReturn(true);
        $config->method('includeBrand')->willReturn(true);

        $registry->method('registry')->with('current_product')->willReturn($product);

        $loadedProduct = $this->createMock(ProductInterface::class);
        $loadedProduct->method('getData')->with('brand')->willReturn('Brand');
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

        $checkoutSession->method('getLastRealOrder')->willReturn($order);

        return new TrackingData(
            $config,
            $registry,
            $productRepository,
            $categoryRepository,
            $stockRegistry,
            $imageHelper,
            $storeManager,
            $json,
            $checkoutSession
        );
    }
}
