<?php
declare(strict_types=1);

namespace ArtLounge\BentoCore\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;

class CartPriceRule implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $ruleCollectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Please Select --')]
        ];

        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('coupon_type', \Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC);

        foreach ($collection as $rule) {
            $options[] = [
                'value' => $rule->getRuleId(),
                'label' => $rule->getName()
            ];
        }

        return $options;
    }
}
