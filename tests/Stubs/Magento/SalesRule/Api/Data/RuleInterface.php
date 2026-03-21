<?php

declare(strict_types=1);

namespace Magento\SalesRule\Api\Data;

interface RuleInterface
{
    public function getRuleId();
    public function setRuleId($ruleId);
}
