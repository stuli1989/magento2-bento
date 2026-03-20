<?php
/**
 * Test Connection Controller Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Controller\Adminhtml\System\Config;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Controller\Adminhtml\System\Config\TestConnection;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class TestConnectionTest extends TestCase
{
    public function testExecuteReturnsClientData(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('store', 0)->willReturn(0);

        $context = new Context($request);

        $jsonFactory = new JsonFactory();
        $client = $this->createMock(BentoClientInterface::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $client->method('testConnection')->with(1)->willReturn(['success' => true]);

        $controller = new TestConnection($context, $jsonFactory, $client, $storeManager);

        $result = $controller->execute();

        $this->assertSame(['success' => true], $result->getData());
    }
}
