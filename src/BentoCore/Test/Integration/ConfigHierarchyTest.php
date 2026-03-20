<?php
/**
 * Integration Test: Config Hierarchy
 *
 * Tests Config's hierarchical enable/disable behavior using a real Config
 * object with a mock ScopeConfig. Verifies that disabling a parent flag
 * correctly disables all child features.
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Integration;

use ArtLounge\BentoCore\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigHierarchyTest extends TestCase
{
    private Config $config;
    private array $configValues = [];

    protected function setUp(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);

        // Dynamic config lookup: getValue returns from our $configValues array
        $scopeConfig->method('getValue')
            ->willReturnCallback(fn(?string $path) => $this->configValues[$path] ?? null);

        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(fn(?string $path) => !empty($this->configValues[$path]));

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnArgument(0);

        $logger = $this->createMock(LoggerInterface::class);

        $this->config = new Config($scopeConfig, $encryptor, $logger);
    }

    /**
     * Test: master switch off → everything disabled.
     */
    public function testMasterDisabledCascadesToAll(): void
    {
        $this->configValues = [
            'artlounge_bento/general/enabled' => '0',
            'artlounge_bento/orders/enabled' => '1',
            'artlounge_bento/orders/track_placed' => '1',
            'artlounge_bento/customers/enabled' => '1',
            'artlounge_bento/newsletter/enabled' => '1',
            'artlounge_bento/abandoned_cart/enabled' => '1',
            'artlounge_bento/tracking/enabled' => '1',
        ];

        $this->assertFalse($this->config->isEnabled());
        $this->assertFalse($this->config->isOrderEventsEnabled());
        $this->assertFalse($this->config->isTrackOrderPlacedEnabled());
        $this->assertFalse($this->config->isCustomerEventsEnabled());
        $this->assertFalse($this->config->isNewsletterEventsEnabled());
        $this->assertFalse($this->config->isAbandonedCartEnabled());
        $this->assertFalse($this->config->isTrackingEnabled());
    }

    /**
     * Test: master on, orders section off → order children disabled.
     */
    public function testOrderSectionDisabledCascadesToChildren(): void
    {
        $this->configValues = [
            'artlounge_bento/general/enabled' => '1',
            'artlounge_bento/orders/enabled' => '0',
            'artlounge_bento/orders/track_placed' => '1',
            'artlounge_bento/orders/track_shipped' => '1',
            'artlounge_bento/orders/track_cancelled' => '1',
            'artlounge_bento/orders/track_refunded' => '1',
        ];

        $this->assertTrue($this->config->isEnabled());
        $this->assertFalse($this->config->isOrderEventsEnabled());
        $this->assertFalse($this->config->isTrackOrderPlacedEnabled());
        $this->assertFalse($this->config->isTrackOrderShippedEnabled());
        $this->assertFalse($this->config->isTrackOrderCancelledEnabled());
        $this->assertFalse($this->config->isTrackOrderRefundedEnabled());
    }

    /**
     * Test: master on, tracking section off → tracking children disabled.
     */
    public function testTrackingSectionDisabledCascadesToChildren(): void
    {
        $this->configValues = [
            'artlounge_bento/general/enabled' => '1',
            'artlounge_bento/tracking/enabled' => '0',
            'artlounge_bento/tracking/track_views' => '1',
            'artlounge_bento/tracking/track_add_to_cart' => '1',
            'artlounge_bento/tracking/track_checkout' => '1',
        ];

        $this->assertTrue($this->config->isEnabled());
        $this->assertFalse($this->config->isTrackingEnabled());
        $this->assertFalse($this->config->isTrackViewsEnabled());
        $this->assertFalse($this->config->isTrackAddToCartEnabled());
        $this->assertFalse($this->config->isTrackCheckoutEnabled());
    }

    /**
     * Test: all enabled → granular flags work correctly.
     */
    public function testFullyEnabledHierarchy(): void
    {
        $this->configValues = [
            'artlounge_bento/general/enabled' => '1',
            'artlounge_bento/orders/enabled' => '1',
            'artlounge_bento/orders/track_placed' => '1',
            'artlounge_bento/orders/track_shipped' => '0',
            'artlounge_bento/customers/enabled' => '1',
            'artlounge_bento/customers/track_created' => '1',
            'artlounge_bento/customers/track_updated' => '0',
            'artlounge_bento/tracking/enabled' => '1',
            'artlounge_bento/tracking/track_views' => '1',
            'artlounge_bento/tracking/track_add_to_cart' => '0',
        ];

        $this->assertTrue($this->config->isTrackOrderPlacedEnabled());
        $this->assertFalse($this->config->isTrackOrderShippedEnabled());
        $this->assertTrue($this->config->isTrackCustomerCreatedEnabled());
        $this->assertFalse($this->config->isTrackCustomerUpdatedEnabled());
        $this->assertTrue($this->config->isTrackViewsEnabled());
        $this->assertFalse($this->config->isTrackAddToCartEnabled());
    }

    /**
     * Test: config defaults for numeric/string values.
     */
    public function testDefaultValues(): void
    {
        $this->configValues = []; // everything null

        $this->assertSame(100, $this->config->getCurrencyMultiplier());
        $this->assertSame(60, $this->config->getAbandonedCartDelay());
        $this->assertSame(0.0, $this->config->getAbandonedCartMinValue());
        $this->assertSame(5, $this->config->getMaxRetries());
        $this->assertSame(30, $this->config->getTimeout());
        $this->assertSame(24, $this->config->getAbandonedCartDuplicateWindow());
        $this->assertSame('cron', $this->config->getAbandonedCartProcessingMethod());
        $this->assertSame('artlounge', $this->config->getSource());
        $this->assertSame('#product-addtocart-button', $this->config->getAddToCartSelector());
    }

    /**
     * Test: debug method only logs when debug is enabled.
     */
    public function testDebugLogsOnlyWhenEnabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        // Rebuild with this logger
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(function (?string $path) {
                return $path === 'artlounge_bento/general/debug';
            });

        $config = new Config(
            $scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $logger
        );

        $logger->expects($this->once())->method('debug');
        $config->debug('test message', ['key' => 'val']);
    }

    /**
     * Test: tags parsing from comma-separated config values.
     */
    public function testTagsParsing(): void
    {
        $this->configValues = [
            'artlounge_bento/customers/default_tags' => 'lead, mql, bento ',
            'artlounge_bento/newsletter/subscribe_tags' => 'newsletter',
        ];

        $this->assertSame(['lead', 'mql', 'bento'], $this->config->getDefaultTags());
        $this->assertSame(['newsletter'], $this->config->getSubscribeTags());
    }

    /**
     * Test: excluded customer groups parsing.
     */
    public function testExcludedGroupsParsing(): void
    {
        $this->configValues = [
            'artlounge_bento/abandoned_cart/exclude_groups' => '0,3,5',
        ];

        $this->assertSame([0, 3, 5], $this->config->getExcludedCustomerGroups());
    }
}
