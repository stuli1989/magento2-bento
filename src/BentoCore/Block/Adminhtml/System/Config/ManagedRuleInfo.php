<?php
declare(strict_types=1);

namespace ArtLounge\BentoCore\Block\Adminhtml\System\Config;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;

class ManagedRuleInfo extends Field
{
    public function __construct(
        Context $context,
        private readonly ConfigInterface $config,
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $ruleResource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $ruleId = $this->config->getCouponManagedRuleId();

        if ($ruleId === null) {
            return '<span style="color:#999;">'
                . __('No rule created yet. A rule will be auto-created when the first coupon is generated.')
                . '</span>';
        }

        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, $ruleId);

        if (!$rule->getId()) {
            return '<span style="color:#e22626;">'
                . __('Rule #%1 was deleted. A new rule will be created on next coupon generation.', $ruleId)
                . '</span>';
        }

        $editUrl = $this->getUrl('sales_rule/promo_quote/edit', ['id' => $ruleId]);
        $status = $rule->getIsActive() ? __('Active') : __('Inactive');

        return sprintf(
            '<strong>%s</strong> (Rule #%d) — %s &nbsp; <a href="%s" target="_blank">%s</a>',
            $this->escapeHtml($rule->getName()),
            $ruleId,
            $status,
            $this->escapeUrl($editUrl),
            __('View in Cart Price Rules →')
        );
    }
}
