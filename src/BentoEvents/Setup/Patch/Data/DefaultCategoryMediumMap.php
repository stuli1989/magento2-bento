<?php
/**
 * Writes the shipped category→medium map (config.xml default, derived from the live category
 * tree on 2026-10-13) into core_config_data so it is visible and editable in admin.
 * Never overwrites a map an admin already saved.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class DefaultCategoryMediumMap implements DataPatchInterface
{
    private const PATH = 'artlounge_bento/profile/category_medium_map';

    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function apply(): self
    {
        $conn = $this->setup->getConnection();
        $table = $this->setup->getTable('core_config_data');
        $exists = $conn->fetchOne(
            $conn->select()->from($table, ['config_id'])
                ->where('path = ?', self::PATH)->where('scope = ?', 'default')->where('scope_id = 0')
        );
        $value = (string)$this->scopeConfig->getValue(self::PATH);
        if (!$exists && $value !== '') {
            $conn->insert($table, ['scope' => 'default', 'scope_id' => 0, 'path' => self::PATH, 'value' => $value]);
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
