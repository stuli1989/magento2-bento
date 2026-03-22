<?php
declare(strict_types=1);

namespace ArtLounge\BentoCore\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DiscountType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'by_percent', 'label' => __('Percentage')],
            ['value' => 'cart_fixed', 'label' => __('Fixed Amount (whole cart)')],
        ];
    }
}
