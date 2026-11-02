<?php
/**
 * sales_order_save_commit_after — runs only after the transaction commits, so rolled-back
 * saves never emit events. All decisions live in PurchaseDetector (never throws).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer;

use ArtLounge\BentoEvents\Model\Purchase\PurchaseDetector;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class OrderSaveCommitAfter implements ObserverInterface
{
    public function __construct(private readonly PurchaseDetector $detector)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order || !$order->getEntityId()) {
            return;
        }
        $this->detector->handle([
            'order_id' => (int)$order->getEntityId(),
            'increment_id' => (string)$order->getIncrementId(),
            'email' => (string)$order->getCustomerEmail(),
            'store_id' => (int)$order->getStoreId(),
            'state' => (string)$order->getState(),
            'status' => (string)$order->getStatus(),
            'created_at' => (string)($order->getCreatedAt() ?: gmdate('Y-m-d H:i:s')),
        ]);
    }
}
