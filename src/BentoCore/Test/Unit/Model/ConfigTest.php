<?php

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Model;

use ArtLounge\BentoCore\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $values = [];
    private Config $config;
    private EncryptorInterface $encryptor;

    protected function setUp(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            fn (string $path) => $this->values[$path] ?? null
        );
        $scope->method('isSetFlag')->willReturnCallback(
            fn (string $path) => !empty($this->values[$path])
        );
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->config = new Config($scope, $this->encryptor, $this->createMock(LoggerInterface::class));
    }

    public function testSecretKeyIsDecryptedWhenStoredEncrypted(): void
    {
        $this->values['artlounge_bento/general/secret_key'] = '0:3:abcdef';
        $this->encryptor->method('decrypt')->with('0:3:abcdef')->willReturn('sk_live');
        $this->assertSame('sk_live', $this->config->getSecretKey());
    }

    public function testSecretKeyPlainValuePassesThrough(): void
    {
        $this->values['artlounge_bento/general/secret_key'] = 'plain_secret';
        $this->encryptor->expects($this->never())->method('decrypt');
        $this->assertSame('plain_secret', $this->config->getSecretKey());
    }

    public function testIsConfiguredNeedsAllThreeKeys(): void
    {
        $this->values['artlounge_bento/general/enabled'] = '1';
        $this->values['artlounge_bento/general/site_uuid'] = 'uuid';
        $this->values['artlounge_bento/general/publishable_key'] = 'pk';
        $this->assertFalse($this->config->isConfigured());
        $this->values['artlounge_bento/general/secret_key'] = 'sk';
        $this->assertTrue($this->config->isConfigured());
        $this->values['artlounge_bento/general/enabled'] = '0';
        $this->assertFalse($this->config->isConfigured());
    }

    public function testDefaults(): void
    {
        $this->assertSame(60, $this->config->getCartDelayMinutes());
        $this->assertSame(30, $this->config->getContactCaptureDelayMinutes());
        $this->assertSame(10, $this->config->getTimeout());
        $this->assertSame('artlounge', $this->config->getSource());
        $this->assertSame('BENTO', $this->config->getCouponPrefix());
        $this->assertSame(7, $this->config->getCouponLifetimeDays());
        $this->assertSame([], $this->config->getTestModeEmailPatterns());
        $this->assertSame([], $this->config->getCategoryMediumMap());
    }

    public function testTestModePatternsParsed(): void
    {
        $this->values['artlounge_bento/general/test_mode_emails'] = "*+bentotest*@*,\nqa@artlounge.in";
        $this->assertSame(['*+bentotest*@*', 'qa@artlounge.in'], $this->config->getTestModeEmailPatterns());
        $this->assertTrue($this->config->getTestMode()->isActive());
    }

    public function testCategoryMediumMapParsing(): void
    {
        $this->values['artlounge_bento/profile/category_medium_map'] =
            "94=watercolour\n 92 = Acrylic \n# comment line\nbad line\n0=oil\nabc=ink\n97=gouache";
        $this->assertSame(
            [94 => 'watercolour', 92 => 'acrylic', 97 => 'gouache'],
            $this->config->getCategoryMediumMap()
        );
    }

    public function testPickCategoryMapParsing(): void
    {
        $this->values['artlounge_bento/profile/pick_category_map'] =
            "# Oil\noil=158, 159\n Any = 169,abc,0,169\nbad line\n1=ink\noil=160";
        $this->assertSame(
            ['oil' => [158, 159, 160], 'any' => [169]],
            $this->config->getPickCategoryMap()
        );
    }

    public function testPinnedPicksKeepSkuCaseAndCapAtThree(): void
    {
        $this->values['artlounge_bento/profile/pick_pinned'] = "# none yet\noil=WN-Liquin, gam-1,A,B\nink=";
        $this->assertSame(['oil' => ['WN-Liquin', 'gam-1', 'A'], 'ink' => []], $this->config->getPinnedPicks());
    }

    public function testPickNumbersDefault(): void
    {
        $this->assertSame(90, $this->config->getPickSalesWindowDays());
        $this->assertSame(3.0, $this->config->getPickMinStock());
        $this->values['artlounge_bento/profile/pick_sales_window_days'] = '30';
        $this->values['artlounge_bento/profile/pick_min_stock'] = '0';
        $this->assertSame(30, $this->config->getPickSalesWindowDays());
        $this->assertSame(0.0, $this->config->getPickMinStock());
    }

    public function testCouponEnabledRequiresMasterSwitch(): void
    {
        $this->values['artlounge_bento/abandoned_cart/coupon_enabled'] = '1';
        $this->assertFalse($this->config->isCouponEnabled());
        $this->values['artlounge_bento/general/enabled'] = '1';
        $this->assertTrue($this->config->isCouponEnabled());
    }

    public function testCouponPrefixStripsUnsafeChars(): void
    {
        $this->values['artlounge_bento/abandoned_cart/coupon_prefix'] = 'AL-back!';
        $this->assertSame('ALback', $this->config->getCouponPrefix());
    }

    public function testNumericSettings(): void
    {
        $this->values['artlounge_bento/abandoned_cart/delay_minutes'] = '2';
        $this->values['artlounge_bento/abandoned_cart/min_value'] = '499.5';
        $this->values['artlounge_bento/abandoned_cart/coupon_min_subtotal'] = '2500';
        $this->values['artlounge_bento/abandoned_cart/contact_capture_delay_minutes'] = '2';
        $this->assertSame(2, $this->config->getCartDelayMinutes());
        $this->assertSame(499.5, $this->config->getCartMinValue());
        $this->assertSame(2500.0, $this->config->getCouponThreshold());
        $this->assertSame(2, $this->config->getContactCaptureDelayMinutes());
    }

    public function testReviewEventsRequireMasterSwitchAndTheirOwnFlag(): void
    {
        $this->values['artlounge_bento/reviews/enabled'] = '1';
        $this->assertFalse($this->config->isReviewEventsEnabled());
        $this->values['artlounge_bento/general/enabled'] = '1';
        $this->assertTrue($this->config->isReviewEventsEnabled());
        $this->values['artlounge_bento/reviews/enabled'] = '0';
        $this->assertFalse($this->config->isReviewEventsEnabled());
    }
}
