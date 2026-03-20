<?php
/**
 * Processing Method Source Model
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoCore
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ProcessingMethod implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'cron', 'label' => __('Cron (Recommended)')],
            ['value' => 'queue', 'label' => __('Queue (Requires Delayed Queue Support)')]
        ];
    }
}
