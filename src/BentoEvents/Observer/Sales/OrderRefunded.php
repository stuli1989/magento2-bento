<?php
/**
 * Order Refunded Observer
 *
 * Triggers bento.order.refunded event when creditmemo is created.
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

class OrderRefunded implements ObserverInterface
{
    private const EVENT_NAME = 'bento.order.refunded';
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
        $creditmemo = $observer->getEvent()->getCreditmemo();

        if (!$creditmemo) {
            return;
        }

        $storeId = (int)$creditmemo->getStoreId();

        if (!$this->config->isTrackOrderRefundedEnabled($storeId)) {
            return;
        }

        try {
            $arguments = $this->serializer->serialize(['id' => (int)$creditmemo->getEntityId()]);

            $this->publisher->publish(
                self::QUEUE_TOPIC,
                [self::EVENT_NAME, $arguments]
            );

            $this->config->debug('Order refunded event queued', [
                'creditmemo_id' => $creditmemo->getEntityId(),
                'order_id' => $creditmemo->getOrderId()
            ], $storeId);

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue order refunded event', [
                'creditmemo_id' => $creditmemo->getEntityId(),
                'error' => $e->getMessage()
            ]);
            $this->outboxWriter->save(self::EVENT_NAME, $arguments ?? '{}', $storeId);
        }
    }
}
