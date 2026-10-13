<?php
/**
 * newsletter_subscriber_save_commit_after → $subscribe / $unsubscribe.
 * Fires for the footer form (posts to newsletter/subscriber/new), account page and admin.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer;

use ArtLounge\BentoEvents\Model\Newsletter\NewsletterChange;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class SubscriberSaveCommitAfter implements ObserverInterface
{
    public function __construct(
        private readonly Enqueuer $enqueuer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $subscriber = $observer->getEvent()->getData('subscriber');
            if (!$subscriber || !$subscriber->getId()) {
                return;
            }
            $orig = $subscriber->getOrigData('subscriber_status');
            $action = NewsletterChange::decide(
                $orig === null ? null : (int)$orig,
                (int)$subscriber->getSubscriberStatus(),
                (int)$subscriber->getOrigData('customer_id'),
                (int)$subscriber->getCustomerId()
            );
            if ($action === null) {
                return;
            }
            $customerId = (int)$subscriber->getCustomerId();
            $this->enqueuer->enqueue(
                $action,
                'subscriber',
                (int)$subscriber->getId(),
                (string)$subscriber->getSubscriberEmail(),
                (int)$subscriber->getStoreId(),
                NewsletterChange::dedupKey((int)$subscriber->getId(), $action, time()),
                [
                    'customer_id' => $customerId ?: null,
                    'source' => $customerId ? 'customer_account' : 'footer_newsletter',
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Bento newsletter enqueue failed', ['error' => $e->getMessage()]);
        }
    }
}
