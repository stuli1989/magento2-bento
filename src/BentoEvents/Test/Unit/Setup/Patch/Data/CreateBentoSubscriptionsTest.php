<?php
/**
 * Create Bento Subscriptions Patch Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Setup\Patch\Data;

use Aligent\AsyncEvents\Api\AsyncEventRepositoryInterface;
use Aligent\AsyncEvents\Model\AsyncEventFactory;
use ArtLounge\BentoEvents\Setup\Patch\Data\CreateBentoSubscriptions;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CreateBentoSubscriptionsTest extends TestCase
{
    public function testApplyCreatesSubscriptionsWhenMissing(): void
    {
        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $asyncEventRepository = $this->createMock(AsyncEventRepositoryInterface::class);
        $asyncEventFactory = $this->createMock(AsyncEventFactory::class);
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $dateTime = $this->createMock(DateTime::class);
        $logger = $this->createMock(LoggerInterface::class);

        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class));

        $searchResult = $this->createMock(\Aligent\AsyncEvents\Api\Data\AsyncEventSearchResultInterface::class);
        $searchResult->method('getTotalCount')->willReturn(0);
        $asyncEventRepository->method('getList')->willReturn($searchResult);

        $asyncEvent = $this->createMock(\Aligent\AsyncEvents\Api\Data\AsyncEventInterface::class);
        $asyncEventFactory->method('create')->willReturn($asyncEvent);

        $moduleDataSetup->expects($this->once())->method('startSetup');
        $moduleDataSetup->expects($this->once())->method('endSetup');

        $asyncEventRepository->expects($this->exactly(9))->method('save');

        $patch = new CreateBentoSubscriptions(
            $moduleDataSetup,
            $asyncEventRepository,
            $asyncEventFactory,
            $searchCriteriaBuilder,
            $dateTime,
            $logger
        );

        $patch->apply();
    }
}
