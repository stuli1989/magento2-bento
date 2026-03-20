<?php
/**
 * Customer Created Observer
 *
 * Triggers bento.customer.created event on registration.
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

class CustomerCreated implements ObserverInterface
{
    private const EVENT_NAME = 'bento.customer.created';
    private const QUEUE_TOPIC = 'event.trigger';

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
        $customer = $observer->getEvent()->getCustomer();

        if (!$customer) {
            return;
        }

        $storeId = (int)$customer->getStoreId();

        if (!$this->config->isTrackCustomerCreatedEnabled($storeId)) {
            return;
        }

        try {
            $arguments = $this->serializer->serialize(['id' => (int)$customer->getId()]);

            $this->publisher->publish(
                self::QUEUE_TOPIC,
                [self::EVENT_NAME, $arguments]
            );

            $this->config->debug('Customer created event queued', [
                'customer_id' => $customer->getId(),
                'email' => $customer->getEmail()
            ], $storeId);

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue customer created event', [
                'customer_id' => $customer->getId(),
                'error' => $e->getMessage()
            ]);
            $this->outboxWriter->save(self::EVENT_NAME, $arguments ?? '{}', $storeId);
        }
    }
}
