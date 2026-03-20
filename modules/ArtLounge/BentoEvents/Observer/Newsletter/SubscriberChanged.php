<?php
/**
 * Subscriber Changed Observer
 *
 * Triggers newsletter subscription/unsubscription events.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer\Newsletter;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Newsletter\Model\Subscriber;
use Psr\Log\LoggerInterface;
use ArtLounge\BentoEvents\Model\Outbox\Writer as OutboxWriter;

class SubscriberChanged implements ObserverInterface
{
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
        /** @var Subscriber $subscriber */
        $subscriber = $observer->getEvent()->getSubscriber();

        if (!$subscriber) {
            return;
        }

        $storeId = (int)$subscriber->getStoreId();

        // Skip system-initiated status changes during customer account linking.
        // When a guest newsletter subscriber creates a Magento account, CustomerPlugin
        // re-saves the subscriber to link customer_id. This can change the status as a
        // side effect, which is NOT a user-initiated unsubscribe and should not be sent
        // to Bento. The $Subscriber event (from customer_save_after) handles this case.
        $origCustomerId = (int)$subscriber->getOrigData('customer_id');
        $newCustomerId = (int)$subscriber->getCustomerId();
        if ($origCustomerId === 0 && $newCustomerId > 0) {
            $this->config->debug('Newsletter event skipped (guest→customer linking)', [
                'subscriber_id' => $subscriber->getId(),
                'email' => $subscriber->getSubscriberEmail(),
                'new_customer_id' => $newCustomerId
            ], $storeId);
            return;
        }

        // Determine event type based on status
        $status = $subscriber->getSubscriberStatus();
        $eventName = null;

        if ($status == Subscriber::STATUS_SUBSCRIBED) {
            if (!$this->config->isTrackSubscribeEnabled($storeId)) {
                return;
            }
            $eventName = 'bento.newsletter.subscribed';
        } elseif ($status == Subscriber::STATUS_UNSUBSCRIBED) {
            if (!$this->config->isTrackUnsubscribeEnabled($storeId)) {
                return;
            }
            $eventName = 'bento.newsletter.unsubscribed';
        } else {
            // Don't track pending/unconfirmed status
            return;
        }

        try {
            $arguments = $this->serializer->serialize(['id' => (int)$subscriber->getId()]);

            $this->publisher->publish(
                self::QUEUE_TOPIC,
                [$eventName, $arguments]
            );

            $this->config->debug('Newsletter event queued', [
                'subscriber_id' => $subscriber->getId(),
                'email' => $subscriber->getSubscriberEmail(),
                'event' => $eventName
            ], $storeId);

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue newsletter event', [
                'subscriber_id' => $subscriber->getId(),
                'error' => $e->getMessage()
            ]);
            $this->outboxWriter->save($eventName, $arguments ?? '{}', $storeId);
        }
    }
}
