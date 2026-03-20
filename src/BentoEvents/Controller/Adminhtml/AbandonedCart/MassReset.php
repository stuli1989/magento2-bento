<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Controller\Adminhtml\AbandonedCart;

use ArtLounge\BentoEvents\Model\ResourceModel\AbandonedCartSchedule\Collection;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use ArtLounge\BentoEvents\Model\ResourceModel\AbandonedCartSchedule\CollectionFactory;

class MassReset extends Action
{
    public const ADMIN_RESOURCE = 'ArtLounge_BentoEvents::abandoned_cart';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Collection $collection */
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $count = 0;

        foreach ($collection as $item) {
            $item->setData('status', 'pending');
            $item->setData('attempts', 0);
            $item->save();
            $count++;
        }

        $this->messageManager->addSuccessMessage(
            __('A total of %1 record(s) have been reset to pending.', $count)
        );

        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
