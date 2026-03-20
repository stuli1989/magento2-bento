<?php
/**
 * Test Connection Controller
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoCore
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Controller\Adminhtml\System\Config;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

class TestConnection extends Action
{
    /**
     * Authorization level
     */
    public const ADMIN_RESOURCE = 'ArtLounge_BentoCore::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly BentoClientInterface $bentoClient,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    /**
     * Execute test connection
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            // Get current store ID from request or default
            $storeId = (int)$this->getRequest()->getParam('store', 0);

            if ($storeId === 0) {
                $storeId = (int)$this->storeManager->getDefaultStoreView()->getId();
            }

            $testResult = $this->bentoClient->testConnection($storeId);

            return $result->setData($testResult);

        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }
}
