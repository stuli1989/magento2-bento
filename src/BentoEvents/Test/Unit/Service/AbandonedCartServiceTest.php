<?php
/**
 * Abandoned Cart Service Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\RecoveryToken;
use ArtLounge\BentoEvents\Service\AbandonedCartService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AbandonedCartServiceTest extends TestCase
{
    private AbandonedCartService $service;
    private MockObject $cartRepository;
    private MockObject $productRepository;
    private MockObject $imageHelper;
    private MockObject $config;
    private MockObject $recoveryToken;
    private MockObject $urlBuilder;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->imageHelper = $this->createMock(ImageHelper::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->recoveryToken = $this->createMock(RecoveryToken::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new AbandonedCartService(
            $this->cartRepository,
            $this->productRepository,
            $this->imageHelper,
            $this->config,
            $this->recoveryToken,
            $this->urlBuilder,
            $this->logger
        );
    }

    public function testGetAbandonedCartDataFormatsData(): void
    {
        $quote = $this->createQuoteMock();

        $this->cartRepository->method('get')->willReturn($quote);
        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(false);

        $result = $this->service->getAbandonedCartData(10);

        $this->assertSame('$abandoned', $result['event_type']);
        $this->assertSame('test@example.com', $result['customer']['email']);
        $this->assertCount(1, $result['items']);
    }

    public function testFormatAbandonedCartDataIncludesRecoveryUrl(): void
    {
        $quote = $this->createQuoteMock();

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(true);
        $this->recoveryToken->method('generate')->willReturn('signed-token');
        $this->urlBuilder->method('getUrl')->willReturn('https://example.com/recover');

        $result = $this->service->formatAbandonedCartData($quote);

        $this->assertArrayHasKey('recovery', $result);
        $this->assertSame('https://example.com/recover', $result['recovery']['cart_url']);
        $this->assertSame('https://example.com/recover', $result['recovery_url']);
    }

    public function testFormatAbandonedCartDataHandlesProductLookupFailure(): void
    {
        $quote = $this->createQuoteMock();

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(false);

        $this->productRepository
            ->method('getById')
            ->willThrowException(new \RuntimeException('fail'));

        $result = $this->service->formatAbandonedCartData($quote);

        $this->assertNull($result['items'][0]['product_url']);
        $this->assertNull($result['items'][0]['product_image_url']);
    }

    public function testGetAbandonedCartDataThrowsOnFailure(): void
    {
        $this->cartRepository
            ->method('get')
            ->willThrowException(new \RuntimeException('fail'));

        $this->expectException(\RuntimeException::class);

        $this->service->getAbandonedCartData(99);
    }

    public function testGetAbandonedCartDataLogsErrorOnFailure(): void
    {
        $this->cartRepository
            ->method('get')
            ->willThrowException(new \RuntimeException('fail'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to get abandoned cart data for Bento', $this->anything());

        try {
            $this->service->getAbandonedCartData(99);
        } catch (\RuntimeException $e) {
            // expected
        }
    }

    public function testFormatAbandonedCartDataWithoutRecoveryUrl(): void
    {
        $quote = $this->createQuoteMock();

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(false);

        $result = $this->service->formatAbandonedCartData($quote);

        $this->assertArrayNotHasKey('recovery', $result);
    }

    public function testFormatAbandonedCartDataFinancialsCalculation(): void
    {
        $quote = $this->createQuoteMock();

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(false);

        $result = $this->service->formatAbandonedCartData($quote);

        // grandTotal=200, subtotal=200, subtotalWithDiscount=180 => discount=20
        $this->assertSame(20000, $result['financials']['total_value']);
        $this->assertSame(20000, $result['financials']['subtotal']);
        $this->assertSame(2000, $result['financials']['discount_amount']);
        $this->assertSame('INR', $result['financials']['currency_code']);
    }

    public function testFormatAbandonedCartDataCartMetadata(): void
    {
        $quote = $this->createQuoteMock();

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(false);

        $result = $this->service->formatAbandonedCartData($quote);

        $this->assertSame(10, $result['cart']['quote_id']);
        $this->assertSame('2026-01-24 09:00:00', $result['cart']['created_at']);
        $this->assertSame('2026-01-24 10:00:00', $result['cart']['updated_at']);
        $this->assertIsInt($result['cart']['abandoned_duration_minutes']);
    }

    public function testFormatAbandonedCartDataCustomerInfo(): void
    {
        $quote = $this->createQuoteMock();

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(false);

        $result = $this->service->formatAbandonedCartData($quote);

        $this->assertSame('test@example.com', $result['customer']['email']);
        $this->assertSame('Test', $result['customer']['firstname']);
        $this->assertSame('User', $result['customer']['lastname']);
        $this->assertTrue($result['customer']['is_guest']);
    }

    public function testFormatAbandonedCartDataItemDetails(): void
    {
        $quote = $this->createQuoteMock();

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(false);

        $result = $this->service->formatAbandonedCartData($quote);

        $item = $result['items'][0];
        $this->assertSame(1, $item['item_id']);
        $this->assertSame(10, $item['product_id']);
        $this->assertSame('SKU', $item['sku']);
        $this->assertSame('Product', $item['name']);
        $this->assertSame(1, $item['qty']);
        $this->assertSame(10000, $item['price']);
        $this->assertSame(10000, $item['row_total']);
        $this->assertSame('https://example.com/product', $item['product_url']);
        $this->assertSame('https://example.com/image.jpg', $item['product_image_url']);
    }

    public function testFormatAbandonedCartDataIncludesBrandWhenConfigured(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getProductUrl')->willReturn('https://example.com/product');
        $product->method('getData')->with('brand')->willReturn('Pebeo');
        $product->method('getAttributeText')->with('brand')->willReturn('Pebeo');
        $quote = $this->createQuoteMock($product);

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(false);
        $this->config->method('includeBrand')->willReturn(true);
        $this->config->method('getBrandAttribute')->willReturn('brand');

        $result = $this->service->formatAbandonedCartData($quote);

        $this->assertSame('Pebeo', $result['items'][0]['brand']);
    }

    public function testFormatAbandonedCartDataRecoveryUrlFormat(): void
    {
        $quote = $this->createQuoteMock();

        $this->config->method('getCurrencyMultiplier')->willReturn(100);
        $this->config->method('includeRecoveryUrl')->willReturn(true);
        $this->recoveryToken->method('generate')->willReturn('signed-token');

        // Capture the URL parameters
        $this->urlBuilder->method('getUrl')
            ->with(
                'bento/cart/recover',
                $this->callback(function ($params) {
                    return isset($params['recover'])
                        && isset($params['_scope'])
                        && isset($params['autopay'])
                        && (int)$params['autopay'] === 1
                        && isset($params['_nosid'])
                        && $params['_nosid'] === true;
                })
            )
            ->willReturn('https://example.com/bento/cart/recover?recover=abc');

        $result = $this->service->formatAbandonedCartData($quote);

        $this->assertArrayHasKey('recovery', $result);
        $this->assertArrayHasKey('recovery_url', $result);
    }

    private function createQuoteMock(?ProductInterface $product = null): MockObject
    {
        $quote = $this->createMock(CartInterface::class);
        $item = $this->createMock(CartItemInterface::class);
        $product = $product ?? $this->createMock(ProductInterface::class);

        $item->method('getItemId')->willReturn(1);
        $item->method('getProductId')->willReturn(10);
        $item->method('getSku')->willReturn('SKU');
        $item->method('getName')->willReturn('Product');
        $item->method('getQty')->willReturn(1);
        $item->method('getPrice')->willReturn(100.0);
        $item->method('getRowTotal')->willReturn(100.0);

        $product->method('getProductUrl')->willReturn('https://example.com/product');

        $this->productRepository->method('getById')->willReturn($product);

        $image = $this->createMock(ImageHelper::class);
        $image->method('init')->willReturnSelf();
        $image->method('getUrl')->willReturn('https://example.com/image.jpg');
        $this->imageHelper->method('init')->willReturn($image);

        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getId')->willReturn(10);
        $quote->method('getCreatedAt')->willReturn('2026-01-24 09:00:00');
        $quote->method('getUpdatedAt')->willReturn('2026-01-24 10:00:00');
        $quote->method('getGrandTotal')->willReturn(200.0);
        $quote->method('getSubtotal')->willReturn(200.0);
        $quote->method('getSubtotalWithDiscount')->willReturn(180.0);
        $quote->method('getQuoteCurrencyCode')->willReturn('INR');
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getCustomerFirstname')->willReturn('Test');
        $quote->method('getCustomerLastname')->willReturn('User');
        $quote->method('getCustomerIsGuest')->willReturn(true);
        $quote->method('getAllVisibleItems')->willReturn([$item]);

        return $quote;
    }
}
