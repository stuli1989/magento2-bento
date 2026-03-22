<?php
/**
 * Setup Patch: Create Bento Event Subscriptions
 *
 * Automatically creates subscriptions for all Bento events in the Aligent
 * Async Events framework. This ensures events are routed to our BentoNotifier.
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoEvents
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Setup\Patch\Data;

use Aligent\AsyncEvents\Api\AsyncEventRepositoryInterface;
use Aligent\AsyncEvents\Model\AsyncEventFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class CreateBentoSubscriptions implements DataPatchInterface
{
    /**
     * All Bento events that need subscriptions
     */
    private const BENTO_EVENTS = [
        'bento.order.placed',
        'bento.order.shipped',
        'bento.order.cancelled',
        'bento.order.refunded',
        'bento.customer.created',
        'bento.customer.updated',
        'bento.newsletter.subscribed',
        'bento.newsletter.unsubscribed',
        'bento.cart.abandoned',
        'bento.checkout.started',
    ];

    /**
     * Notifier type for Bento (matches key in NotifierFactory)
     */
    private const NOTIFIER_TYPE = 'bento';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly AsyncEventRepositoryInterface $asyncEventRepository,
        private readonly AsyncEventFactory $asyncEventFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();
        try {
            foreach (self::BENTO_EVENTS as $eventName) {
                $this->createSubscriptionIfNotExists($eventName);
            }
        } finally {
            $this->moduleDataSetup->endSetup();
        }

        return $this;
    }

    /**
     * Create a subscription for an event if it doesn't already exist
     *
     * @param string $eventName
     * @return void
     */
    private function createSubscriptionIfNotExists(string $eventName): void
    {
        try {
            // Check if subscription already exists
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('event_name', $eventName)
                ->addFilter('metadata', self::NOTIFIER_TYPE)
                ->create();

            $existingSubscriptions = $this->asyncEventRepository->getList($searchCriteria);

            if ($existingSubscriptions->getTotalCount() > 0) {
                $this->logger->debug('Bento subscription already exists', [
                    'event_name' => $eventName
                ]);
                return;
            }

            // Create new subscription
            $asyncEvent = $this->asyncEventFactory->create();
            $asyncEvent->setEventName($eventName);
            // recipient_url is not used by BentoNotifier but is a required field
            $asyncEvent->setRecipientUrl('bento://internal');
            // verification_token is not used by BentoNotifier but is a required field
            $asyncEvent->setVerificationToken(Uuid::uuid4()->toString());
            // metadata determines which notifier is used
            $asyncEvent->setMetadata(self::NOTIFIER_TYPE);
            $asyncEvent->setStatus(true);
            $asyncEvent->setSubscribedAt($this->dateTime->gmtDate());
            $asyncEvent->setStoreId(0); // Default store (applies to all stores)

            $this->asyncEventRepository->save($asyncEvent);

            $this->logger->info('Created Bento subscription', [
                'event_name' => $eventName,
                'notifier' => self::NOTIFIER_TYPE
            ]);

        } catch (AlreadyExistsException $e) {
            $this->logger->debug('Bento subscription already exists (race condition)', [
                'event_name' => $eventName
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create Bento subscription', [
                'event_name' => $eventName,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException(
                sprintf('Failed to create Bento subscription for event "%s".', $eventName),
                0,
                $e
            );
        }
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
