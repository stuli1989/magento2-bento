<?php
/**
 * Newsletter Service
 *
 * Provides newsletter subscriber data for Bento events.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\Subscriber;
use Psr\Log\LoggerInterface;

class NewsletterService
{
    public function __construct(
        private readonly SubscriberFactory $subscriberFactory,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get subscriber data for async event
     *
     * @param int $id Subscriber ID
     * @return array
     */
    public function getSubscriberData(int $id): array
    {
        try {
            $subscriber = $this->subscriberFactory->create()->load($id);
            return $this->formatSubscriberData($subscriber);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get subscriber data for Bento', [
                'subscriber_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Format subscriber data for Bento
     *
     * @param Subscriber $subscriber
     * @return array
     */
    public function formatSubscriberData(Subscriber $subscriber): array
    {
        $storeId = (int)$subscriber->getStoreId();
        $isSubscribed = $subscriber->getSubscriberStatus() == Subscriber::STATUS_SUBSCRIBED;

        $eventType = $isSubscribed ? '$subscribe' : '$unsubscribe';
        $tags = $isSubscribed ? $this->config->getSubscribeTags($storeId) : [];

        return [
            'event_type' => $eventType,

            'subscriber' => [
                'subscriber_id' => (int)$subscriber->getId(),
                'email' => $subscriber->getSubscriberEmail(),
                'status' => $this->getStatusLabel($subscriber->getSubscriberStatus()),
                'customer_id' => $subscriber->getCustomerId() ? (int)$subscriber->getCustomerId() : null,
                'subscribed_at' => $subscriber->getChangeStatusAt(),
                'source' => $subscriber->getCustomerId()
                    ? 'customer_account'
                    : 'footer_newsletter'
            ],

            'tags' => $tags,

            'store' => [
                'store_id' => $storeId
            ]
        ];
    }

    /**
     * Get status label
     */
    private function getStatusLabel(int|string $status): string
    {
        $status = (int) $status;
        return match ($status) {
            Subscriber::STATUS_SUBSCRIBED => 'subscribed',
            Subscriber::STATUS_NOT_ACTIVE => 'pending',
            Subscriber::STATUS_UNSUBSCRIBED => 'unsubscribed',
            Subscriber::STATUS_UNCONFIRMED => 'unconfirmed',
            default => 'unknown'
        };
    }
}
