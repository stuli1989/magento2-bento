<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer;

use ArtLounge\BentoEvents\Model\Coupon\RuleManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class AdminConfigSaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly RuleManager $ruleManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $defaultStoreId = (int)$this->storeManager->getDefaultStoreView()->getId();
            $this->ruleManager->syncRuleFromConfig($defaultStoreId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to sync Bento managed rule on config save', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
