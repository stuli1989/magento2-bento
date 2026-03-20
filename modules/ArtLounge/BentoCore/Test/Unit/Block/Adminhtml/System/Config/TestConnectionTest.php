<?php
/**
 * Test Connection Block Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Block\Adminhtml\System\Config;

use ArtLounge\BentoCore\Block\Adminhtml\System\Config\TestConnection;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;

class TestConnectionTest extends TestCase
{
    public function testRenderStripsScope(): void
    {
        $block = new TestConnection();
        $element = $this->createMock(AbstractElement::class);

        $element->expects($this->once())->method('unsScope')->willReturnSelf();
        $element->expects($this->once())->method('unsCanUseWebsiteValue')->willReturnSelf();
        $element->expects($this->once())->method('unsCanUseDefaultValue')->willReturnSelf();

        $block->render($element);
    }

    public function testGetAjaxUrlReturnsRoute(): void
    {
        $block = new TestConnection();

        $this->assertSame('artlounge_bento/system_config/testconnection', $block->getAjaxUrl());
    }

    public function testGetButtonHtmlReturnsHtml(): void
    {
        $block = new TestConnection();

        $this->assertStringContainsString('button', $block->getButtonHtml());
    }
}
