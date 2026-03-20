<?php
/**
 * ArtLounge Bento Tracking Module
 *
 * Client-side tracking for product views, add to cart, and checkout.
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'ArtLounge_BentoTracking',
    __DIR__
);
