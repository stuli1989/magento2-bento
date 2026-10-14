<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Observer;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\Enqueuer;
use ArtLounge\BentoEvents\Model\Review\VisitorUuid;
use ArtLounge\BentoEvents\Observer\ReviewSaveCommitAfter;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReviewSaveCommitAfterTest extends TestCase
{
    private Enqueuer $enqueuer;
    private ConfigInterface $config;
    private CustomerRepositoryInterface $customers;
    private VisitorUuid $uuid;
    private string $area = 'frontend';
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->enqueuer = $this->createMock(Enqueuer::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isReviewEventsEnabled')->willReturnCallback(fn () => $this->enabled);
        $this->customers = $this->createMock(CustomerRepositoryInterface::class);
        $this->uuid = $this->createMock(VisitorUuid::class);
    }

    private function observer(): ReviewSaveCommitAfter
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturnCallback(fn () => $this->area);

        return new ReviewSaveCommitAfter($this->enqueuer, $this->config, $this->customers, $this->uuid, $state,
            $this->createMock(LoggerInterface::class));
    }

    private function event(?int $customerId, int $reviewId = 18342): Observer
    {
        $review = new class ($reviewId, $customerId) {
            public function __construct(private int $id, private ?int $customerId)
            {
            }
            public function getId(): int { return $this->id; }
            public function getCustomerId(): ?int { return $this->customerId; }
            public function getStoreId(): int { return 1; }
            public function getEntityPkValue(): int { return 500; }
        };
        $event = $this->createMock(Event::class);
        $event->method('getData')->willReturnCallback(fn ($key = null) => $key === 'object' ? $review : null);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    public function testRegisteredCustomerIsQueuedWithTheirEmail(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('Meera+BentoTest@Example.com');
        $this->customers->method('getById')->with(77)->willReturn($customer);
        $this->uuid->expects($this->never())->method('current');
        $this->enqueuer->expects($this->once())->method('enqueue')
            ->with('review_submitted', 'review', 18342, 'Meera+BentoTest@Example.com', 1, 'review_submitted:18342', [])
            ->willReturn(true);

        $this->observer()->execute($this->event(77));
    }

    public function testGuestWithBentoUuidIsQueuedForSendTimeLookup(): void
    {
        $this->uuid->method('current')->willReturn('abcdef12');
        $this->enqueuer->expects($this->once())->method('enqueue')
            ->with('review_submitted', 'review', 18342, '', 1, 'review_submitted:18342', ['bento_uuid' => 'abcdef12'])
            ->willReturn(true);

        $this->observer()->execute($this->event(null));
    }

    public function testGuestWithoutAnyIdentityIsNotQueued(): void
    {
        $this->uuid->method('current')->willReturn(null);
        $this->enqueuer->expects($this->never())->method('enqueue');

        $this->observer()->execute($this->event(null));
    }

    public function testAdminSavesNeverQueue(): void
    {
        $this->area = 'adminhtml';
        $this->enqueuer->expects($this->never())->method('enqueue');

        $this->observer()->execute($this->event(77));
    }

    public function testDisabledFlagNeverQueues(): void
    {
        $this->enabled = false;
        $this->enqueuer->expects($this->never())->method('enqueue');

        $this->observer()->execute($this->event(77));
    }

    public function testAnythingThrowingIsSwallowed(): void
    {
        $this->customers->method('getById')->willThrowException(new \RuntimeException('db down'));
        $this->enqueuer->expects($this->never())->method('enqueue');

        $this->observer()->execute($this->event(77));
        $this->addToAssertionCount(1);
    }
}
