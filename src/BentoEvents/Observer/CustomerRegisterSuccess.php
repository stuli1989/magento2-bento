<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer;

use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * customer_register_success → $account_created.
 */
class CustomerRegisterSuccess implements ObserverInterface
{
    public function __construct(
        private readonly Enqueuer $enqueuer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getData('customer');
            if (!$customer || !$customer->getId()) {
                return;
            }
            $this->enqueuer->enqueue(
                'account_created',
                'customer',
                (int)$customer->getId(),
                (string)$customer->getEmail(),
                (int)$customer->getStoreId(),
                'account_created:' . (int)$customer->getId()
            );
        } catch (\Throwable $e) {
            $this->logger->error('Bento account_created enqueue failed', ['error' => $e->getMessage()]);
        }
    }
}
