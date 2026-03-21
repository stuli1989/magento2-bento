<?php
/**
 * Order Service Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Service\OrderService;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderServiceTest extends TestCase
{
    private OrderService $orderService;
    private MockObject $orderRepository;
    private MockObject $productRepository;
    private MockObject $categoryRepository;
    private MockObject $storeManager;
    private MockObject $groupRepository;
    private MockObject $config;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('default');
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/media/');
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getCode')->willReturn('General');
        $this->groupRepository->method('getById')->willReturn($group);

        $this->orderService = new OrderService(
            $this->orderRepository,
            $this->productRepository,
            $this->categoryRepository,
            $this->storeManager,
            $this->groupRepository,
            $this->config,
            $this->logger,
            $this->createMock(SearchCriteriaBuilder::class)
        );
    }

    private function setUpDefaultProductMock(): void
    {
        $defaultProduct = $this->createMock(ProductInterface::class);
        $defaultProduct->method('getProductUrl')->willReturn('https://example.com/product');
        $defaultProduct->method('getCategoryIds')->willReturn([]);
        $this->productRepository->method('getById')->willReturn($defaultProduct);
    }

    public function testGetOrderDataReturnsFormattedData(): void
    {
        $this->setUpDefaultProductMock();
        $orderId = 123;
        $storeId = 1;

        $order = $this->createOrderMock($orderId, $storeId, [1]);

        $this->orderRepository
            ->expects($this->once())
            ->method('get')
            ->with($orderId)
            ->willReturn($order);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->getOrderData($orderId);

        $this->assertArrayHasKey('event_type', $result);
        $this->assertEquals('$purchase', $result['event_type']);
        $this->assertArrayHasKey('order', $result);
        $this->assertEquals($orderId, $result['order']['id']);
        $this->assertArrayHasKey('financials', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('customer', $result);
    }

    public function testGetOrderDataCalculatesTotalCorrectly(): void
    {
        $this->setUpDefaultProductMock();
        $orderId = 123;
        $storeId = 1;
        $grandTotal = 250.00;
        $taxAmount = 40.00;

        $order = $this->createOrderMock($orderId, $storeId, [1]);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getTaxAmount')->willReturn($taxAmount);

        $this->orderRepository->method('get')->willReturn($order);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->getOrderData($orderId);

        $this->assertEquals(25000, $result['financials']['total_value']);
    }

    public function testGetOrderDataExcludesTaxWhenConfigured(): void
    {
        $this->setUpDefaultProductMock();
        $orderId = 123;
        $storeId = 1;
        $grandTotal = 250.00;
        $taxAmount = 40.00;

        $order = $this->createOrderMock($orderId, $storeId, [1]);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getTaxAmount')->willReturn($taxAmount);

        $this->orderRepository->method('get')->willReturn($order);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(false);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->getOrderData($orderId);

        $this->assertEquals(21000, $result['financials']['total_value']);
    }

    public function testGetOrderDataHandlesItemsWithImagesAndCategories(): void
    {
        $order = $this->createOrderMock(123, 1, [1]);
        $this->orderRepository->method('get')->willReturn($order);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getProductUrl')->willReturn('https://example.com/product');
        $product->method('getImage')->willReturn('/i/m/image.jpg');
        $product->method('getCategoryIds')->willReturn([5]);

        $category = $this->createMock(CategoryInterface::class);
        $category->method('getName')->willReturn('Category');

        $this->productRepository->method('getById')->willReturn($product);
        $this->categoryRepository->method('get')->willReturn($category);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(true);
        $this->config->method('includeCategories')->willReturn(true);

        $result = $this->orderService->getOrderData(123);

        $this->assertSame('https://example.com/media/catalog/product/i/m/image.jpg', $result['items'][0]['product_image_url']);
        $this->assertSame(['Category'], $result['items'][0]['categories']);
    }

    public function testGetOrderDataIncludesBrandWhenConfigured(): void
    {
        $order = $this->createOrderMock(123, 1, [1]);
        $this->orderRepository->method('get')->willReturn($order);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getProductUrl')->willReturn('https://example.com/product');
        $product->method('getCategoryIds')->willReturn([]);
        $product->method('getData')->with('brand')->willReturn('Pebeo');
        $product->method('getAttributeText')->with('brand')->willReturn('Pebeo');
        $this->productRepository->method('getById')->willReturn($product);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);
        $this->config->method('includeBrand')->willReturn(true);
        $this->config->method('getBrandAttribute')->willReturn('brand');

        $result = $this->orderService->getOrderData(123);

        $this->assertSame('Pebeo', $result['items'][0]['brand']);
    }

    public function testGetOrderDataReturnsNullUrlsWhenProductLookupFails(): void
    {
        $order = $this->createOrderMock(123, 1, [1]);
        $this->orderRepository->method('get')->willReturn($order);

        $this->productRepository
            ->method('getById')
            ->willThrowException(new \RuntimeException('fail'));

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(true);
        $this->config->method('includeCategories')->willReturn(true);

        $result = $this->orderService->getOrderData(123);

        $this->assertNull($result['items'][0]['product_url']);
        $this->assertArrayNotHasKey('product_image_url', $result['items'][0]);
        $this->assertArrayNotHasKey('categories', $result['items'][0]);
    }

    public function testGetOrderDataThrowsExceptionOnFailure(): void
    {
        $this->expectException(\Exception::class);

        $this->orderRepository
            ->method('get')
            ->willThrowException(new \Exception('Order not found'));

        $this->orderService->getOrderData(999);
    }

    public function testGetOrderDataLogsErrorOnFailure(): void
    {
        $this->orderRepository
            ->method('get')
            ->willThrowException(new \Exception('Order not found'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to get order data for Bento', $this->anything());

        try {
            $this->orderService->getOrderData(999);
        } catch (\Exception $e) {
            // expected
        }
    }

    public function testFormatOrderDataWithVirtualOrder(): void
    {
        $this->setUpDefaultProductMock();
        $order = $this->createCustomOrderMock([
            'is_virtual' => true,
            'shipping_address' => null,
        ]);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->formatOrderData($order);

        $this->assertTrue($result['flags']['is_virtual']);
        $this->assertNull($result['addresses']['shipping']);
    }

    public function testFormatOrderDataWithGuestOrder(): void
    {
        $this->setUpDefaultProductMock();
        $order = $this->createCustomOrderMock([
            'customer_id' => null,
        ]);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->formatOrderData($order);

        $this->assertTrue($result['flags']['is_guest']);
        $this->assertNull($result['customer']['customer_id']);
    }

    public function testFormatOrderDataWithDiscount(): void
    {
        $this->setUpDefaultProductMock();
        // Default createOrderMock has discount_amount = -20.00
        $order = $this->createOrderMock(1, 1, [1]);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->formatOrderData($order);

        $this->assertTrue($result['flags']['discounted']);
        $this->assertSame(2000, $result['financials']['discount_amount']);
    }

    public function testFormatOrderDataNoDiscountFlag(): void
    {
        $this->setUpDefaultProductMock();
        $order = $this->createCustomOrderMock([
            'discount_amount' => 0.00,
        ]);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->formatOrderData($order);

        $this->assertFalse($result['flags']['discounted']);
    }

    public function testFormatOrderDataSkipsChildItems(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(OrderPaymentInterface::class);
        $billingAddress = $this->createMock(OrderAddressInterface::class);

        $payment->method('getMethod')->willReturn('checkmo');
        $payment->method('getMethodInstance')->willReturn($payment);
        $payment->method('getTitle')->willReturn('Check');
        $billingAddress->method('getFirstname')->willReturn('John');
        $billingAddress->method('getLastname')->willReturn('Doe');
        $billingAddress->method('getStreet')->willReturn(['123 Main']);
        $billingAddress->method('getCity')->willReturn('Mumbai');
        $billingAddress->method('getRegion')->willReturn('MH');
        $billingAddress->method('getPostcode')->willReturn('400001');
        $billingAddress->method('getCountryId')->willReturn('IN');
        $billingAddress->method('getTelephone')->willReturn('123');

        $parentItem = $this->createMock(OrderItemInterface::class);
        $parentItem->method('getParentItemId')->willReturn(null);
        $parentItem->method('getItemId')->willReturn(10);
        $parentItem->method('getProductId')->willReturn(1);
        $parentItem->method('getSku')->willReturn('PARENT-SKU');
        $parentItem->method('getName')->willReturn('Parent');
        $parentItem->method('getQtyOrdered')->willReturn(1);
        $parentItem->method('getPrice')->willReturn(100.0);
        $parentItem->method('getRowTotal')->willReturn(100.0);

        $childItem = $this->createMock(OrderItemInterface::class);
        $childItem->method('getParentItemId')->willReturn(10);

        $order->method('getEntityId')->willReturn(1);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000001');
        $order->method('getCreatedAt')->willReturn('2026-01-24');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getState')->willReturn('new');
        $order->method('getGrandTotal')->willReturn(100.0);
        $order->method('getSubtotal')->willReturn(100.0);
        $order->method('getShippingAmount')->willReturn(0.0);
        $order->method('getDiscountAmount')->willReturn(0.0);
        $order->method('getTaxAmount')->willReturn(0.0);
        $order->method('getOrderCurrencyCode')->willReturn('INR');
        $order->method('getIsVirtual')->willReturn(false);
        $order->method('getCustomerId')->willReturn(1);
        $order->method('getCustomerEmail')->willReturn('test@example.com');
        $order->method('getCustomerFirstname')->willReturn('Test');
        $order->method('getCustomerLastname')->willReturn('User');
        $order->method('getCustomerGroupId')->willReturn(1);
        $order->method('getItems')->willReturn([$parentItem, $childItem]);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn($billingAddress);
        $order->method('getShippingMethod')->willReturn('flatrate');
        $order->method('getShippingDescription')->willReturn('Flat');

        $this->setUpDefaultProductMock();
        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->formatOrderData($order);

        // Should have only 1 item (parent), child is filtered
        $this->assertCount(1, $result['items']);
        $this->assertSame('PARENT-SKU', $result['items'][0]['sku']);
    }

    public function testFormatOrderDataWithDifferentMultiplier(): void
    {
        $this->setUpDefaultProductMock();
        // Default createOrderMock has grandTotal=250.00, taxAmount=40.00
        $order = $this->createOrderMock(1, 1, [1]);

        $this->orderRepository->method('get')->willReturn($order);
        $this->config->method('getCurrencyMultiplier')->willReturn(10);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->getOrderData(1);

        // 250.00 * 10 = 2500
        $this->assertSame(2500, $result['financials']['total_value']);
    }

    public function testFormatOrderDataIncludesCustomerGroupName(): void
    {
        $this->setUpDefaultProductMock();
        $order = $this->createCustomOrderMock([
            'customer_group_id' => 0,
        ]);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->formatOrderData($order);

        $this->assertSame('NOT LOGGED IN', $result['customer']['customer_group']);
    }

    public function testFormatOrderDataUnknownCustomerGroup(): void
    {
        $this->setUpDefaultProductMock();
        $order = $this->createCustomOrderMock([
            'customer_group_id' => 99,
        ]);
        $this->groupRepository->method('getById')->willThrowException(new \RuntimeException('missing group'));

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->formatOrderData($order);

        $this->assertSame('Unknown', $result['customer']['customer_group']);
    }

    public function testFormatOrderDataDisplayIncrementId(): void
    {
        $this->setUpDefaultProductMock();
        // Default createOrderMock has incrementId='000000123'
        $order = $this->createOrderMock(1, 1, [1]);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeTaxInTotals')->willReturn(true);
        $this->config->method('includeProductImages')->willReturn(false);
        $this->config->method('includeCategories')->willReturn(false);

        $result = $this->orderService->formatOrderData($order);

        $this->assertSame('000000123', $result['order']['increment_id']);
        $this->assertSame('#000000123', $result['order']['increment_id_display']);
    }

    /**
     * Create a mock order with customizable fields (avoids PHPUnit reconfigure issue)
     */
    private function createCustomOrderMock(array $overrides = []): MockObject
    {
        $defaults = [
            'entity_id' => 1,
            'store_id' => 1,
            'increment_id' => '000000001',
            'created_at' => '2026-01-24 10:00:00',
            'status' => 'processing',
            'state' => 'new',
            'grand_total' => 250.00,
            'subtotal' => 220.00,
            'shipping_amount' => 50.00,
            'discount_amount' => -20.00,
            'tax_amount' => 40.00,
            'order_currency_code' => 'INR',
            'is_virtual' => false,
            'customer_id' => 456,
            'customer_email' => 'customer@example.com',
            'customer_firstname' => 'John',
            'customer_lastname' => 'Doe',
            'customer_group_id' => 1,
            'shipping_method' => 'flatrate_flatrate',
            'shipping_description' => 'Flat Rate',
            'shipping_address' => 'default',
        ];

        $values = array_merge($defaults, $overrides);

        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(OrderPaymentInterface::class);
        $billingAddress = $this->createMock(OrderAddressInterface::class);
        $orderItem = $this->createMock(OrderItemInterface::class);

        $payment->method('getMethod')->willReturn('checkmo');
        $payment->method('getMethodInstance')->willReturn($payment);
        $payment->method('getTitle')->willReturn('Check');

        $billingAddress->method('getFirstname')->willReturn('John');
        $billingAddress->method('getLastname')->willReturn('Doe');
        $billingAddress->method('getStreet')->willReturn(['123 Main St']);
        $billingAddress->method('getCity')->willReturn('Mumbai');
        $billingAddress->method('getRegion')->willReturn('Maharashtra');
        $billingAddress->method('getPostcode')->willReturn('400001');
        $billingAddress->method('getCountryId')->willReturn('IN');
        $billingAddress->method('getTelephone')->willReturn('1234567890');

        $orderItem->method('getParentItemId')->willReturn(null);
        $orderItem->method('getItemId')->willReturn(10);
        $orderItem->method('getProductId')->willReturn(1);
        $orderItem->method('getSku')->willReturn('SKU');
        $orderItem->method('getName')->willReturn('Product');
        $orderItem->method('getQtyOrdered')->willReturn(1);
        $orderItem->method('getPrice')->willReturn(100.0);
        $orderItem->method('getRowTotal')->willReturn(100.0);

        $order->method('getEntityId')->willReturn($values['entity_id']);
        $order->method('getStoreId')->willReturn($values['store_id']);
        $order->method('getIncrementId')->willReturn($values['increment_id']);
        $order->method('getCreatedAt')->willReturn($values['created_at']);
        $order->method('getStatus')->willReturn($values['status']);
        $order->method('getState')->willReturn($values['state']);
        $order->method('getGrandTotal')->willReturn($values['grand_total']);
        $order->method('getSubtotal')->willReturn($values['subtotal']);
        $order->method('getShippingAmount')->willReturn($values['shipping_amount']);
        $order->method('getDiscountAmount')->willReturn($values['discount_amount']);
        $order->method('getTaxAmount')->willReturn($values['tax_amount']);
        $order->method('getOrderCurrencyCode')->willReturn($values['order_currency_code']);
        $order->method('getIsVirtual')->willReturn($values['is_virtual']);
        $order->method('getCustomerId')->willReturn($values['customer_id']);
        $order->method('getCustomerEmail')->willReturn($values['customer_email']);
        $order->method('getCustomerFirstname')->willReturn($values['customer_firstname']);
        $order->method('getCustomerLastname')->willReturn($values['customer_lastname']);
        $order->method('getCustomerGroupId')->willReturn($values['customer_group_id']);
        $order->method('getItems')->willReturn([$orderItem]);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn(
            $values['shipping_address'] === null ? null : $billingAddress
        );
        $order->method('getShippingMethod')->willReturn($values['shipping_method']);
        $order->method('getShippingDescription')->willReturn($values['shipping_description']);

        return $order;
    }

    /**
     * Create a mock order with basic data
     */
    private function createOrderMock(int $orderId, int $storeId, array $itemIds): MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(OrderPaymentInterface::class);
        $billingAddress = $this->createMock(OrderAddressInterface::class);
        $orderItem = $this->createMock(OrderItemInterface::class);

        $payment->method('getMethod')->willReturn('checkmo');
        $payment->method('getMethodInstance')->willReturn($payment);
        $payment->method('getTitle')->willReturn('Check');

        $billingAddress->method('getFirstname')->willReturn('John');
        $billingAddress->method('getLastname')->willReturn('Doe');
        $billingAddress->method('getStreet')->willReturn(['123 Main St']);
        $billingAddress->method('getCity')->willReturn('Mumbai');
        $billingAddress->method('getRegion')->willReturn('Maharashtra');
        $billingAddress->method('getPostcode')->willReturn('400001');
        $billingAddress->method('getCountryId')->willReturn('IN');
        $billingAddress->method('getTelephone')->willReturn('1234567890');

        $orderItem->method('getParentItemId')->willReturn(null);
        $orderItem->method('getItemId')->willReturn(10);
        $orderItem->method('getProductId')->willReturn($itemIds[0]);
        $orderItem->method('getSku')->willReturn('SKU');
        $orderItem->method('getName')->willReturn('Product');
        $orderItem->method('getQtyOrdered')->willReturn(1);
        $orderItem->method('getPrice')->willReturn(100.0);
        $orderItem->method('getRowTotal')->willReturn(100.0);

        $order->method('getEntityId')->willReturn($orderId);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getCreatedAt')->willReturn('2026-01-24 10:00:00');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getState')->willReturn('new');
        $order->method('getGrandTotal')->willReturn(250.00);
        $order->method('getSubtotal')->willReturn(220.00);
        $order->method('getShippingAmount')->willReturn(50.00);
        $order->method('getDiscountAmount')->willReturn(-20.00);
        $order->method('getTaxAmount')->willReturn(40.00);
        $order->method('getOrderCurrencyCode')->willReturn('INR');
        $order->method('getIsVirtual')->willReturn(false);
        $order->method('getCustomerId')->willReturn(456);
        $order->method('getCustomerEmail')->willReturn('customer@example.com');
        $order->method('getCustomerFirstname')->willReturn('John');
        $order->method('getCustomerLastname')->willReturn('Doe');
        $order->method('getCustomerGroupId')->willReturn(1);
        $order->method('getItems')->willReturn([$orderItem]);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn($billingAddress);
        $order->method('getShippingMethod')->willReturn('flatrate_flatrate');
        $order->method('getShippingDescription')->willReturn('Flat Rate');

        return $order;
    }
}
