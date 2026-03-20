<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model;

use Magento\Framework\Model\AbstractModel;

class AbandonedCartSchedule extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\AbandonedCartSchedule::class);
    }
}
