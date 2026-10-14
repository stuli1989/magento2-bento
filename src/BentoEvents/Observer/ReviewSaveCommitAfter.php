<?php
/**
 * review_save_commit_after (storefront only) → $review_submitted.
 *
 * Only storefront submissions count: admin edits/approvals happen in adminhtml and are ignored, and
 * dedup_key review_submitted:<id> makes any re-save a no-op. The payload (incl. the rating votes, which
 * the controller saves after the review row) is built later in cron.
 *
 * Identity: logged-in → account email. Guest → the Bento visitor uuid (resolved to an email at send
 * time). Neither → nothing is queued.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Review\VisitorUuid;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ReviewSaveCommitAfter implements ObserverInterface
{
    public function __construct(
        private readonly Enqueuer $enqueuer,
        private readonly ConfigInterface $config,
        private readonly CustomerRepositoryInterface $customers,
        private readonly VisitorUuid $visitorUuid,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            $review = $event ? ($event->getData('object') ?? $event->getData('data_object')) : null;
            if (!$review || !$review->getId()) {
                return;
            }
            if ($this->appState->getAreaCode() !== 'frontend') {
                return;
            }
            $storeId = (int)$review->getStoreId();
            if (!$this->config->isReviewEventsEnabled($storeId ?: null)) {
                return;
            }

            $email = '';
            $context = [];
            if ($review->getCustomerId()) {
                $email = (string)$this->customers->getById((int)$review->getCustomerId())->getEmail();
            } else {
                $uuid = $this->visitorUuid->current();
                if ($uuid === null) {
                    $this->config->debug('Guest review without Bento identity — not sent', ['review_id' => (int)$review->getId()]);
                    return;
                }
                $context['bento_uuid'] = $uuid;
            }

            $this->enqueuer->enqueue(
                'review_submitted',
                'review',
                (int)$review->getId(),
                $email,
                $storeId,
                'review_submitted:' . (int)$review->getId(),
                $context
            );
        } catch (\Throwable $e) {
            $this->logger->error('Bento review_submitted enqueue failed', ['error' => $e->getMessage()]);
        }
    }
}
