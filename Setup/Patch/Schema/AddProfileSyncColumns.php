<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Replaces the legacy "update_frontend_groups_on_sso" column with
 * granular per-provider Profile Sync flags.
 */
class AddProfileSyncColumns implements SchemaPatchInterface
{
    private const TABLE = 'miniorange_oauth_client_apps';

    /** New boolean columns (tinyint, default 0) */
    private const NEW_COLUMNS = [
        'sync_customer_profile_on_sso' => 'Sync customer name/DOB/gender on every SSO login',
        'sync_customer_address_on_sso' => 'Sync customer billing/shipping address on every SSO login',
        'sync_customer_group_on_sso'   => 'Re-evaluate customer group mapping on every SSO login',
        'sync_admin_profile_on_sso'    => 'Sync admin name and username on every SSO login',
        'sync_admin_role_on_sso'       => 'Re-evaluate admin role mapping on every SSO login',
    ];

    public function __construct(
        private readonly SchemaSetupInterface $schemaSetup
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->schemaSetup->startSetup();
        $connection = $this->schemaSetup->getConnection();
        $tableName  = $this->schemaSetup->getTable(self::TABLE);

        // 1. Add new Profile Sync columns (idempotent)
        foreach (self::NEW_COLUMNS as $column => $comment) {
            if (!$connection->tableColumnExists($tableName, $column)) {
                $connection->addColumn($tableName, $column, [
                    'type'     => Table::TYPE_SMALLINT,
                    'nullable' => false,
                    'default'  => 0,
                    'comment'  => $comment,
                ]);
            }
        }

        // 2. Migrate legacy flag: copy update_frontend_groups_on_sso → sync_customer_group_on_sso
        if ($connection->tableColumnExists($tableName, 'update_frontend_groups_on_sso')) {
            $connection->update(
                $tableName,
                ['sync_customer_group_on_sso' => new \Zend_Db_Expr('update_frontend_groups_on_sso')]
            );
            $connection->dropColumn($tableName, 'update_frontend_groups_on_sso');
        }

        $this->schemaSetup->endSetup();

        return $this;
    }
}
