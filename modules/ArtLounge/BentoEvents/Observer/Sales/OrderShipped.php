<?php
/**
 * Order Shipped Observer
 *
 * Triggers bento.order.shipped event when shipment is created.
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

class OrderShipped implements ObserverInterface
{
    private const EVENT_NAME = 'bento.order.shipped';
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
        $shipment = $observer->getEvent()->getShipment();

        if (!$shipment) {
            return;
        }

        $storeId = (int)$shipment->getStoreId();

        if (!$this->config->isTrackOrderShippedEnabled($storeId)) {
            return;
        }

        try {
            $arguments = $this->serializer->serialize(['id' => (int)$shipment->getEntityId()]);

            $this->publisher->publish(
                self::QUEUE_TOPIC,
                [self::EVENT_NAME, $arguments]
            );

            $this->config->debug('Order shipped event queued', [
                'shipment_id' => $shipment->getEntityId(),
                'order_id' => $shipment->getOrderId()
            ], $storeId);

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue order shipped event', [
                'shipment_id' => $shipment->getEntityId(),
                'error' => $e->getMessage()
            ]);
            $this->outboxWriter->save(self::EVENT_NAME, $arguments ?? '{}', $storeId);
        }
    }
}
