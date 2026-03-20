<?php
/**
 * Config Model Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Model;

use ArtLounge\BentoCore\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigTest extends TestCase
{
    private Config $config;
    private MockObject $scopeConfig;
    private MockObject $encryptor;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config = new Config(
            $this->scopeConfig,
            $this->encryptor,
            $this->logger
        );
    }

    public function testIncludeCustomerAddressReadsConfigFlag(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->with('artlounge_bento/customers/include_address')
            ->willReturn(true);

        $this->assertTrue($this->config->includeCustomerAddress());
    }

    public function testGetSecretKeyDecryptsValue(): void
    {
        $encrypted = 'encrypted_value';
        $decrypted = 'secret_key_123';

        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/general/secret_key')
            ->willReturn($encrypted);

        $this->encryptor
            ->method('decrypt')
            ->with($encrypted)
            ->willReturn($decrypted);

        $this->assertEquals($decrypted, $this->config->getSecretKey());
    }

    public function testGetSecretKeyReturnsNullOnDecryptFailure(): void
    {
        $encrypted = 'encrypted_value';

        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/general/secret_key')
            ->willReturn($encrypted);

        $this->encryptor
            ->method('decrypt')
            ->with($encrypted)
            ->willThrowException(new \Exception('Decrypt failed'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Failed to decrypt Bento secret key',
                $this->arrayHasKey('exception')
            );

        $this->assertNull($this->config->getSecretKey());
    }

    public function testIsOrderEventsEnabledChecksBothFlags(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->willReturnCallback(function ($path) {
                return match ($path) {
                    'artlounge_bento/general/enabled' => true,
                    'artlounge_bento/orders/enabled' => true,
                    default => false
                };
            });

        $this->assertTrue($this->config->isOrderEventsEnabled());
    }

    public function testIsOrderEventsEnabledReturnsFalseWhenMainDisabled(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->willReturnCallback(function ($path) {
                return match ($path) {
                    'artlounge_bento/general/enabled' => false,
                    'artlounge_bento/orders/enabled' => true,
                    default => false
                };
            });

        $this->assertFalse($this->config->isOrderEventsEnabled());
    }

    public function testGetDefaultTagsReturnsArray(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/customers/default_tags')
            ->willReturn('lead, mql, website');

        $expected = ['lead', 'mql', 'website'];
        $this->assertEquals($expected, $this->config->getDefaultTags());
    }

    public function testGetDefaultTagsReturnsEmptyArrayWhenNull(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/customers/default_tags')
            ->willReturn(null);

        $this->assertEquals([], $this->config->getDefaultTags());
    }

    public function testGetExcludedCustomerGroupsReturnsArray(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/abandoned_cart/exclude_groups')
            ->willReturn('2,4,5');

        $expected = [2, 4, 5];
        $this->assertEquals($expected, $this->config->getExcludedCustomerGroups());
    }

    public function testGetSourceDefaultsToArtlounge(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/general/source')
            ->willReturn(null);

        $this->assertSame('artlounge', $this->config->getSource());
    }

    public function testDebugLogsWhenEnabled(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->with('artlounge_bento/general/debug')
            ->willReturn(true);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('[Bento] Test debug', ['foo' => 'bar']);

        $this->config->debug('Test debug', ['foo' => 'bar']);
    }

    public function testGetValueUsesStoreScopeWhenStoreIdProvided(): void
    {
        $this->scopeConfig
            ->expects($this->once())
            ->method('getValue')
            ->with('artlounge_bento/general/site_uuid', ScopeInterface::SCOPE_STORE, 2)
            ->willReturn('uuid-123');

        $this->assertSame('uuid-123', $this->config->getSiteUuid(2));
    }

    public function testDebugDoesNotLogWhenDisabled(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->with('artlounge_bento/general/debug')
            ->willReturn(false);

        $this->logger
            ->expects($this->never())
            ->method('debug');

        $this->config->debug('Should not log', ['key' => 'value']);
    }

    public function testGetSecretKeyReturnsNullWhenEmpty(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/general/secret_key')
            ->willReturn(null);

        $this->assertNull($this->config->getSecretKey());
    }

    public function testGetCurrencyMultiplierDefaultsTo100(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/orders/currency_multiplier')
            ->willReturn(null);

        $this->assertSame(100, $this->config->getCurrencyMultiplier());
    }

    public function testGetAbandonedCartDelayDefaultsTo60(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/abandoned_cart/delay_minutes')
            ->willReturn(null);

        $this->assertSame(60, $this->config->getAbandonedCartDelay());
    }

    public function testGetTimeoutDefaultsTo30(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/advanced/timeout')
            ->willReturn(null);

        $this->assertSame(30, $this->config->getTimeout());
    }

    public function testGetMaxRetriesDefaultsTo5(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/advanced/max_retries')
            ->willReturn(null);

        $this->assertSame(5, $this->config->getMaxRetries());
    }

    public function testGetLogRetentionDefaultsTo30(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/advanced/log_retention')
            ->willReturn(null);

        $this->assertSame(30, $this->config->getLogRetention());
    }

    public function testGetAddToCartSelectorDefaultsToButton(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/tracking/add_to_cart_selector')
            ->willReturn(null);

        $this->assertSame('#product-addtocart-button', $this->config->getAddToCartSelector());
    }

    public function testGetBrandAttributeDefaultsToManufacturer(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/tracking/brand_attribute')
            ->willReturn(null);

        $this->assertSame('manufacturer', $this->config->getBrandAttribute());
    }

    public function testGetSubscribeTagsReturnsArray(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/newsletter/subscribe_tags')
            ->willReturn('newsletter, subscriber');

        $this->assertSame(['newsletter', 'subscriber'], $this->config->getSubscribeTags());
    }

    public function testGetSubscribeTagsReturnsEmptyArrayWhenNull(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/newsletter/subscribe_tags')
            ->willReturn(null);

        $this->assertSame([], $this->config->getSubscribeTags());
    }

    public function testGetExcludedCustomerGroupsReturnsEmptyArrayWhenNull(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/abandoned_cart/exclude_groups')
            ->willReturn(null);

        $this->assertSame([], $this->config->getExcludedCustomerGroups());
    }

    public function testGetAbandonedCartProcessingMethodDefaultsToCron(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/abandoned_cart/processing_method')
            ->willReturn(null);
        $this->assertSame('cron', $this->config->getAbandonedCartProcessingMethod());
    }

    public function testIsTrackingEnabledChecksBothFlags(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->willReturnCallback(function ($path) {
                return match ($path) {
                    'artlounge_bento/general/enabled' => true,
                    'artlounge_bento/tracking/enabled' => true,
                    default => false
                };
            });

        $this->assertTrue($this->config->isTrackingEnabled());
    }

    public function testIsTrackingEnabledReturnsFalseWhenMainDisabled(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->willReturnCallback(function ($path) {
                return match ($path) {
                    'artlounge_bento/general/enabled' => false,
                    'artlounge_bento/tracking/enabled' => true,
                    default => false
                };
            });

        $this->assertFalse($this->config->isTrackingEnabled());
    }

    public function testIsNewsletterEventsEnabledChecksBothFlags(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->willReturnCallback(function ($path) {
                return match ($path) {
                    'artlounge_bento/general/enabled' => true,
                    'artlounge_bento/newsletter/enabled' => true,
                    default => false
                };
            });

        $this->assertTrue($this->config->isNewsletterEventsEnabled());
    }

    public function testIsAbandonedCartEnabledChecksBothFlags(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->willReturnCallback(function ($path) {
                return match ($path) {
                    'artlounge_bento/general/enabled' => true,
                    'artlounge_bento/abandoned_cart/enabled' => true,
                    default => false
                };
            });

        $this->assertTrue($this->config->isAbandonedCartEnabled());
    }

    public function testChainedTrackingChecks(): void
    {
        // isTrackViewsEnabled requires isTrackingEnabled which requires isEnabled
        $this->scopeConfig
            ->method('isSetFlag')
            ->willReturnCallback(function ($path) {
                return match ($path) {
                    'artlounge_bento/general/enabled' => true,
                    'artlounge_bento/tracking/enabled' => true,
                    'artlounge_bento/tracking/track_views' => true,
                    default => false
                };
            });

        $this->assertTrue($this->config->isTrackViewsEnabled());
    }

    public function testChainedTrackingReturnsFalseWhenMiddleFlagOff(): void
    {
        // tracking/enabled is off, so track_views should also be false
        $this->scopeConfig
            ->method('isSetFlag')
            ->willReturnCallback(function ($path) {
                return match ($path) {
                    'artlounge_bento/general/enabled' => true,
                    'artlounge_bento/tracking/enabled' => false,
                    'artlounge_bento/tracking/track_views' => true,
                    default => false
                };
            });

        $this->assertFalse($this->config->isTrackViewsEnabled());
    }

    public function testGetAbandonedCartMinValueDefaultsToZero(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/abandoned_cart/min_value')
            ->willReturn(null);

        $this->assertSame(0.0, $this->config->getAbandonedCartMinValue());
    }

    public function testGetAbandonedCartDuplicateWindowDefaultsTo24(): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->with('artlounge_bento/abandoned_cart/duplicate_window')
            ->willReturn(null);

        $this->assertSame(24, $this->config->getAbandonedCartDuplicateWindow());
    }

    public function testIncludeBrandReadsConfigFlag(): void
    {
        $this->scopeConfig
            ->method('isSetFlag')
            ->with('artlounge_bento/tracking/include_brand')
            ->willReturn(true);

        $this->assertTrue($this->config->includeBrand());
    }
}
