<?php

declare(strict_types=1);

namespace Magento\SalesRule\Api;

interface RuleRepositoryInterface
{
    public function getById($ruleId);
    public function save(\Magento\SalesRule\Api\Data\RuleInterface $rule);
    public function deleteById($ruleId);
}
