<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Zero-downtime upgrade patch for existing single-provider installations (MP-10).
 *
 * On upgrade, every row in `miniorange_oauth_client_apps` that has no
 * `display_name` set (i.e. was created before the multi-provider sprint)
 * is updated to:
 *
 *   display_name = app_name
 *   is_active    = 1
 *   login_type   = 'both'   (preserves existing single-provider behaviour)
 *   sort_order   = 0
 *
 * Idempotent: skips rows that already have `display_name` populated.
 * Safe to run on fresh installs (no rows → no-op).
 *
 * Revert: resets the four columns to NULL / 0 / default on every row,
 * effectively undoing the migration (not destructive — config data remains).
 */
class MigrateToMultiProvider implements DataPatchInterface, PatchRevertableInterface
{
    private const TABLE = 'miniorange_oauth_client_apps';

    /** @var ModuleDataSetupInterface */
    private readonly ModuleDataSetupInterface $moduleDataSetup;

    /**
     * Initialize migration patch.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $setup = $this->moduleDataSetup;
        $setup->startSetup();

        $connection = $setup->getConnection();
        $table      = $setup->getTable(self::TABLE);

        // Only migrate rows that have not yet been given a display_name.
        // This makes the patch safe to re-run (idempotent).
        $connection->update(
            $table,
            [
                'display_name' => new \Zend_Db_Expr('`app_name`'),
                'is_active'    => 1,
                'login_type'   => 'both',
                'sort_order'   => 0,
            ],
            'display_name IS NULL OR display_name = \'\''
        );

        $setup->endSetup();
        return $this;
    }

    /**
     * @inheritdoc
     *
     * Revert resets the multi-provider columns but leaves `app_name` / config untouched.
     */
    public function revert(): void
    {
        $setup      = $this->moduleDataSetup;
        $connection = $setup->getConnection();
        $table      = $setup->getTable(self::TABLE);

        $connection->update(
            $table,
            [
                'display_name' => null,
                'is_active'    => 1,        // keep active to avoid breaking live stores
                'login_type'   => 'customer',
                'sort_order'   => 0,
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
