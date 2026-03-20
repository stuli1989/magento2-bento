<?php
/**
 * ArtLounge Bento Events Module
 *
 * Handles server-side event tracking for Bento integration.
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoEvents
 * @author    Art Lounge Development Team
 * @copyright 2026 Art Lounge
 * @license   MIT
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'ArtLounge_BentoEvents',
    __DIR__
);
