<?php

declare(strict_types=1);

namespace Magento\Catalog\Api\Data;

interface ProductInterface
{
    public function getId();
    public function getSku();
    public function getName();
    public function getFinalPrice();
    public function getProductUrl();
    public function getCategoryIds();
    public function getData($key);
    public function getAttributeText($key);
    public function getSpecialPrice();
    public function getPrice();
    public function getImage();
}
