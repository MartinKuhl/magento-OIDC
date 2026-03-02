<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Migrate global core_config_data OAuth settings into per-provider DB columns.
 *
 * Before multi-provider support, all OIDC settings (attribute mapping, login
 * options, auto-create flags, etc.) were stored globally in core_config_data
 * under the path prefix `miniorange/oauth/`.  This patch copies those values
 * into the corresponding columns of each row in `miniorange_oauth_client_apps`
 * so that settings are now stored and managed per provider.
 *
 * Idempotent: a column is only updated when its current value is NULL or ''.
 * Existing per-provider values are never overwritten.
 * Safe to run on fresh installs (no rows → no-op).
 *
 * Depends on: MigrateToMultiProvider (ensures rows have display_name set)
 */
class MigrateGlobalConfigToProvider implements DataPatchInterface, PatchRevertableInterface
{
    private const PROVIDER_TABLE = 'miniorange_oauth_client_apps';
    private const CONFIG_TABLE   = 'core_config_data';
    private const CONFIG_PREFIX  = 'miniorange/oauth/';

    /**
     * Map from core_config_data path suffix → provider table column name.
     */
    private const COLUMN_MAP = [
        'amEmail'                    => 'email_attribute',
        'amUsername'                 => 'username_attribute',
        'amFirstName'                => 'firstname_attribute',
        'amLastName'                 => 'lastname_attribute',
        'group'                      => 'group_attribute',
        'defaultRole'                => 'default_role',
        'adminRoleMapping'           => 'oauth_admin_role_mapping',
        'autoCreateAdmin'            => 'mo_oauth_auto_create_admin',
        'autoCreateCustomer'         => 'mo_oauth_auto_create_customer',
        'showcustomerlink'           => 'show_customer_link',
        'showadminlink'              => 'show_admin_link',
        'enableLoginRedirect'        => 'autoredirect',
        'amDob'                      => 'dob_attribute',
        'amGender'                   => 'gender_attribute',
        'amPhone'                    => 'billing_phone_attribute',
        'amStreet'                   => 'billing_address_attribute',
        'amZip'                      => 'billing_zip_attribute',
        'amCity'                     => 'billing_city_attribute',
        'amState'                    => 'billing_state_attribute',
        'amCountry'                  => 'billing_country_attribute',
        'disableNonOidcAdminLogin'   => 'mo_disable_non_oidc_admin_login',
        'disableNonOidcCustomerLogin' => 'mo_disable_non_oidc_customer_login',
    ];

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
     * Copy global config values into each provider row (only when column is empty).
     */
    public function apply(): self
    {
        $setup = $this->moduleDataSetup;
        $setup->startSetup();

        $connection    = $setup->getConnection();
        $configTable   = $setup->getTable(self::CONFIG_TABLE);
        $providerTable = $setup->getTable(self::PROVIDER_TABLE);

        // 1. Build a key → value map from core_config_data (default scope only)
        $select = $connection->select()
            ->from($configTable, ['path', 'value'])
            ->where('path LIKE ?', self::CONFIG_PREFIX . '%')
            ->where('scope = ?', 'default');

        $configRows = $connection->fetchPairs($select);

        // Strip the prefix and keep only keys we know how to map
        $globalValues = [];
        foreach ($configRows as $path => $value) {
            $key = substr($path, strlen(self::CONFIG_PREFIX));
            if (isset(self::COLUMN_MAP[$key])) {
                $globalValues[$key] = $value;
            }
        }

        if (empty($globalValues)) {
            $setup->endSetup();
            return $this;
        }

        // 2. Load all provider rows
        $providerRows = $connection->fetchAll(
            $connection->select()->from($providerTable)
        );

        foreach ($providerRows as $row) {
            $updates = [];
            foreach (self::COLUMN_MAP as $configKey => $dbColumn) {
                if (!isset($globalValues[$configKey])) {
                    continue;
                }
                // Only fill when the column is currently empty / null
                $currentValue = $row[$dbColumn] ?? null;
                if ($currentValue !== null && $currentValue !== '') {
                    continue;
                }
                $updates[$dbColumn] = $globalValues[$configKey];
            }

            if (!empty($updates)) {
                $connection->update(
                    $providerTable,
                    $updates,
                    ['id = ?' => (int) $row['id']]
                );
            }
        }

        $setup->endSetup();
        return $this;
    }

    /**
     * Revert: clear the migrated columns (restore them to empty string).
     * Does NOT restore the core_config_data entries.
     */
    public function revert(): void
    {
        $setup      = $this->moduleDataSetup;
        $connection = $setup->getConnection();
        $table      = $setup->getTable(self::PROVIDER_TABLE);

        $resetValues = [];
        foreach (self::COLUMN_MAP as $dbColumn) {
            $resetValues[$dbColumn] = '';
        }

        $connection->update($table, $resetValues);
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [MigrateToMultiProvider::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
