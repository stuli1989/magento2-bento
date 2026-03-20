<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AbandonedCartSchedule extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('artlounge_bento_abandoned_cart_schedule', 'schedule_id');
    }
}
