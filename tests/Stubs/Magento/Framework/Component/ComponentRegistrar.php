<?php

declare(strict_types=1);

namespace Magento\Framework\Component;

/**
 * Stub for Magento's ComponentRegistrar used in registration.php files.
 */
class ComponentRegistrar
{
    public const MODULE = 'module';

    /**
     * @var array<string, array<string, string>>
     */
    private static array $paths = [];

    /**
     * Register a component (module, theme, language, library).
     */
    public static function register(string $type, string $componentName, string $path): void
    {
        self::$paths[$type][$componentName] = $path;
    }

    /**
     * Get registered paths for a component type.
     *
     * @return array<string, string>
     */
    public static function getPaths(string $type): array
    {
        return self::$paths[$type] ?? [];
    }
}
