<?php

declare(strict_types=1);

namespace Magento\Framework\Data\Form\Element;

class AbstractElement
{
    public function unsScope(): self { return $this; }
    public function unsCanUseWebsiteValue(): self { return $this; }
    public function unsCanUseDefaultValue(): self { return $this; }
}
