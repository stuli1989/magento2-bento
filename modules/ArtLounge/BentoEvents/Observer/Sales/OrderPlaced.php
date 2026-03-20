<?php
/**
 * Order Placed Observer
 *
 * Triggers bento.order.placed event when order is placed.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer\Sales;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;
use ArtLounge\BentoEvents\Model\Outbox\Writer as OutboxWriter;

class OrderPlaced implements ObserverInterface
{
    private const EVENT_NAME = 'bento.order.placed';
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

        $storeId = (int)$order->getStoreId();

        if (!$this->config->isTrackOrderPlacedEnabled($storeId)) {
            return;
        }

        try {
            // Use increment_id instead of entity_id because sales_order_place_after
            // fires before the order row is persisted — entity_id can be null
            $incrementId = $order->getIncrementId();
            if (!$incrementId) {
                $this->logger->warning('Order placed event skipped: no increment_id available');
                return;
            }

            $arguments = $this->serializer->serialize(['increment_id' => $incrementId]);

            $this->publisher->publish(
                self::QUEUE_TOPIC,
                [self::EVENT_NAME, $arguments]
            );

            $this->config->debug('Order placed event queued', [
                'increment_id' => $incrementId
            ], $storeId);

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue order placed event', [
                'increment_id' => $order->getIncrementId(),
                'error' => $e->getMessage()
            ]);
            $this->outboxWriter->save(self::EVENT_NAME, $arguments ?? '{}', $storeId);
        }
    }
}
