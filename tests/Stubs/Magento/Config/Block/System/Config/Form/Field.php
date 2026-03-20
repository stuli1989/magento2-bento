<?php

declare(strict_types=1);

namespace Magento\Config\Block\System\Config\Form;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Layout;

class Field
{
    protected Layout $layout;

    public function __construct()
    {
        $this->layout = new Layout();
    }

    public function render(AbstractElement $element): string
    {
        return 'rendered';
    }

    protected function _toHtml(): string
    {
        return '<div>Rendered</div>';
    }

    public function getUrl($routePath, $routeParams = null): string
    {
        return $routePath;
    }

    public function getLayout(): Layout
    {
        return $this->layout;
    }
}
