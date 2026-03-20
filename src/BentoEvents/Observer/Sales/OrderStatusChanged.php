<?php
/**
 * Order Status Changed Observer
 *
 * Triggers bento.order.cancelled event when order is cancelled.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer\Sales;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use ArtLounge\BentoEvents\Model\Outbox\Writer as OutboxWriter;

class OrderStatusChanged implements ObserverInterface
{
    private const EVENT_NAME = 'bento.order.cancelled';
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
        $order = $observer->getEvent()->getOrder();

        if (!$order) {
            return;
        }

        // Only process if order state changed to canceled
        if ($order->getState() !== Order::STATE_CANCELED) {
            return;
        }

        // Check if this is actually a state change
        if (!$order->dataHasChangedFor('state')) {
            return;
        }

        $storeId = (int)$order->getStoreId();

        if (!$this->config->isTrackOrderCancelledEnabled($storeId)) {
            return;
        }

        try {
            $arguments = $this->serializer->serialize(['id' => (int)$order->getEntityId()]);

            $this->publisher->publish(
                self::QUEUE_TOPIC,
                [self::EVENT_NAME, $arguments]
            );

            $this->config->debug('Order cancelled event queued', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId()
            ], $storeId);

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue order cancelled event', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage()
            ]);
            $this->outboxWriter->save(self::EVENT_NAME, $arguments ?? '{}', $storeId);
        }
    }
}
