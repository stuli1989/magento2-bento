<?php
/**
 * Customer Updated Observer
 *
 * Triggers bento.customer.updated event on profile changes.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer\Customer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;
use ArtLounge\BentoEvents\Model\Outbox\Writer as OutboxWriter;

class CustomerUpdated implements ObserverInterface
{
    private const EVENT_NAME = 'bento.customer.updated';
    private const QUEUE_TOPIC = 'event.trigger';

    private array $processedIds = [];

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly OutboxWriter $outboxWriter
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getCustomerDataObject();

        if (!$customer) {
            return;
        }

        $customerId = (int)$customer->getId();

        // Prevent duplicate events in same request
        if (isset($this->processedIds[$customerId])) {
            return;
        }

        // Skip new customers (handled by CustomerCreated)
        $origCustomer = $observer->getEvent()->getOrigCustomerDataObject();
        if (!$origCustomer || !$origCustomer->getId()) {
            return;
        }

        $storeId = (int)$customer->getStoreId();

        if (!$this->config->isTrackCustomerUpdatedEnabled($storeId)) {
            return;
        }

        try {
            $this->processedIds[$customerId] = true;

            $arguments = $this->serializer->serialize(['id' => $customerId]);

            $this->publisher->publish(
                self::QUEUE_TOPIC,
                [self::EVENT_NAME, $arguments]
            );

            $this->config->debug('Customer updated event queued', [
                'customer_id' => $customerId,
                'email' => $customer->getEmail()
            ], $storeId);

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue customer updated event', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            $this->outboxWriter->save(self::EVENT_NAME, $arguments ?? '{}', $storeId);
        }
    }
}
