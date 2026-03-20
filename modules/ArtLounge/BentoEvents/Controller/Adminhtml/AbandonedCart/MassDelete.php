<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Controller\Adminhtml\AbandonedCart;

use ArtLounge\BentoEvents\Model\ResourceModel\AbandonedCartSchedule\Collection;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use ArtLounge\BentoEvents\Model\ResourceModel\AbandonedCartSchedule\CollectionFactory;

class MassDelete extends Action
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
        $count = $collection->getSize();

        $collection->walk('delete');

        $this->messageManager->addSuccessMessage(
            __('A total of %1 record(s) have been deleted.', $count)
        );

        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
