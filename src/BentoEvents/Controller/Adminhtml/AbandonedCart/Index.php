<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Controller\Adminhtml\AbandonedCart;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'ArtLounge_BentoEvents::abandoned_cart';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('ArtLounge_BentoEvents::abandoned_cart');
        $page->getConfig()->getTitle()->prepend(__('Abandoned Cart Schedule'));
        return $page;
    }
}
