<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer;

use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * sales_order_creditmemo_save_commit_after → $OrderRefunded (one per credit memo).
 */
class CreditmemoSaveCommitAfter implements ObserverInterface
{
    public function __construct(
        private readonly Enqueuer $enqueuer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $cm = $observer->getEvent()->getData('creditmemo');
            if (!$cm || !$cm->getEntityId()) {
                return;
            }
            $order = $cm->getOrder();
            $email = $order ? (string)$order->getCustomerEmail() : '';
            $this->enqueuer->enqueue(
                'refund',
                'creditmemo',
                (int)$cm->getEntityId(),
                $email,
                (int)$cm->getStoreId(),
                'refund:' . $cm->getIncrementId()
            );
        } catch (\Throwable $e) {
            $this->logger->error('Bento refund enqueue failed', ['error' => $e->getMessage()]);
        }
    }
}
