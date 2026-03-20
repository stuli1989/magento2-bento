<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\ResourceModel\AbandonedCartSchedule;

use ArtLounge\BentoEvents\Model\AbandonedCartSchedule as Model;
use ArtLounge\BentoEvents\Model\ResourceModel\AbandonedCartSchedule as ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'schedule_id';

    protected function _construct(): void
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
