<?php
/**
 * Writes the shipped next-picks defaults (config.xml: add-on category map resolved from the live
 * category tree on 2026-10-23, empty pins, 90-day window, minimum stock 3) into core_config_data so
 * they are visible and editable in admin. Never overwrites a value an admin already saved.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class DefaultNextPicksConfig implements DataPatchInterface
{
    private const PATHS = [
        'artlounge_bento/profile/pick_category_map',
        'artlounge_bento/profile/pick_pinned',
        'artlounge_bento/profile/pick_sales_window_days',
        'artlounge_bento/profile/pick_min_stock',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function apply(): self
    {
        $conn = $this->setup->getConnection();
        $table = $this->setup->getTable('core_config_data');
        foreach (self::PATHS as $path) {
            $exists = $conn->fetchOne(
                $conn->select()->from($table, ['config_id'])
                    ->where('path = ?', $path)->where('scope = ?', 'default')->where('scope_id = 0')
            );
            $value = (string)$this->scopeConfig->getValue($path);
            if (!$exists && $value !== '') {
                $conn->insert($table, ['scope' => 'default', 'scope_id' => 0, 'path' => $path, 'value' => $value]);
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
