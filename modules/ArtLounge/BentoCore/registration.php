<?php
/**
 * ArtLounge Bento Core Module
 *
 * Provides shared configuration, API client, and utilities for Bento integration.
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoCore
 * @author    Art Lounge Development Team
 * @copyright 2026 Art Lounge
 * @license   MIT
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'ArtLounge_BentoCore',
    __DIR__
);
