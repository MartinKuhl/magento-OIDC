<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Migrate JSON role/group mappings and attribute columns into the two new
 * normalized tables (m2oidc_oauth_attribute_mappings and
 * m2oidc_oauth_role_mappings).
 *
 * Idempotent: uses INSERT ON DUPLICATE KEY UPDATE keyed on (provider_id,
 * attribute_type) / (provider_id, mapping_type, oidc_group) so re-running
 * the patch after deployment does not create duplicate rows.
 *
 * Safe to run on fresh installs — no provider rows → no-op.
 *
 * Depends on: MigrateGlobalConfigToProvider (ensures provider rows have their
 * attribute columns populated from legacy core_config_data).
 */
class MigrateToNormalizedSchema implements DataPatchInterface, PatchRevertableInterface
{
    private const string PROVIDER_TABLE = 'm2oidc_oauth_client_apps';
    private const string ATTR_TABLE     = 'm2oidc_oauth_attribute_mappings';
    private const string ROLE_TABLE     = 'm2oidc_oauth_role_mappings';

    /**
     * Maps attribute_type slug → provider table column name.
     */
    private const array ATTR_COLUMN_MAP = [
        'email'           => 'email_attribute',
        'username'        => 'username_attribute',
        'firstname'       => 'firstname_attribute',
        'lastname'        => 'lastname_attribute',
        'group'           => 'group_attribute',
        'dob'             => 'dob_attribute',
        'gender'          => 'gender_attribute',
        'billing_city'    => 'billing_city_attribute',
        'billing_state'   => 'billing_state_attribute',
        'billing_country' => 'billing_country_attribute',
        'billing_address' => 'billing_address_attribute',
        'billing_phone'   => 'billing_phone_attribute',
        'billing_zip'     => 'billing_zip_attribute',
    ];

    /**
     * Maps attribute_type → which sync_on_sso column to read from the provider row.
     * null = always 0 (identity attributes like email/username are never re-synced).
     */
    private const array SYNC_COLUMN_MAP = [
        'email'           => null,
        'username'        => null,
        'firstname'       => 'sync_customer_profile_on_sso',
        'lastname'        => 'sync_customer_profile_on_sso',
        'dob'             => 'sync_customer_profile_on_sso',
        'gender'          => 'sync_customer_profile_on_sso',
        'billing_city'    => 'sync_customer_address_on_sso',
        'billing_state'   => 'sync_customer_address_on_sso',
        'billing_country' => 'sync_customer_address_on_sso',
        'billing_address' => 'sync_customer_address_on_sso',
        'billing_phone'   => 'sync_customer_address_on_sso',
        'billing_zip'     => 'sync_customer_address_on_sso',
        'group'           => 'sync_customer_group_on_sso',
    ];

    /** @var ModuleDataSetupInterface */
    private readonly ModuleDataSetupInterface $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [MigrateGlobalConfigToProvider::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * Populate the two new normalized tables from the existing provider rows.
     */
    public function apply(): self
    {
        $setup = $this->moduleDataSetup;
        $setup->startSetup();

        $connection    = $setup->getConnection();
        $providerTable = $setup->getTable(self::PROVIDER_TABLE);
        $attrTable     = $setup->getTable(self::ATTR_TABLE);
        $roleTable     = $setup->getTable(self::ROLE_TABLE);

        $providerRows = $connection->fetchAll(
            $connection->select()->from($providerTable)
        );

        foreach ($providerRows as $row) {
            $providerId = (int) $row['id'];

            // 1. Migrate attribute mapping columns
            foreach (self::ATTR_COLUMN_MAP as $attrType => $column) {
                $attrName = trim((string) ($row[$column] ?? ''));
                if ($attrName === '') {
                    continue;
                }
                $syncColumn = self::SYNC_COLUMN_MAP[$attrType] ?? null;
                $syncOnSso  = $syncColumn !== null ? (int) ($row[$syncColumn] ?? 0) : 0;

                $connection->insertOnDuplicate(
                    $attrTable,
                    [
                        'provider_id'    => $providerId,
                        'attribute_type' => $attrType,
                        'attribute_name' => $attrName,
                        'sync_on_sso'    => $syncOnSso,
                    ],
                    ['attribute_name', 'sync_on_sso']
                );
            }

            // 2. Migrate admin role mappings (JSON → rows)
            $roleMappingsJson = (string) ($row['oauth_admin_role_mapping'] ?? '');
            if ($roleMappingsJson !== '' && $roleMappingsJson !== '[]') {
                $roleMappings = json_decode($roleMappingsJson, true);
                if (is_array($roleMappings)) {
                    $sortOrder = 0;
                    foreach ($roleMappings as $mapping) {
                        $oidcGroup = trim((string) ($mapping['group'] ?? ''));
                        $roleId    = trim((string) ($mapping['role'] ?? ''));
                        if ($oidcGroup === '' || $roleId === '') {
                            continue;
                        }
                        $connection->insertOnDuplicate(
                            $roleTable,
                            [
                                'provider_id'     => $providerId,
                                'mapping_type'    => 'admin_role',
                                'oidc_group'      => $oidcGroup,
                                'magento_role_id' => $roleId,
                                'sort_order'      => $sortOrder++,
                            ],
                            ['magento_role_id', 'sort_order']
                        );
                    }
                }
            }

            // 3. Migrate customer group mappings (JSON → rows)
            $groupMappingsJson = (string) ($row['oauth_customer_group_mapping'] ?? '');
            if ($groupMappingsJson !== '' && $groupMappingsJson !== '[]') {
                $groupMappings = json_decode($groupMappingsJson, true);
                if (is_array($groupMappings)) {
                    $sortOrder = 0;
                    foreach ($groupMappings as $mapping) {
                        $oidcGroup   = trim((string) ($mapping['group'] ?? ''));
                        $groupId     = trim((string) ($mapping['customerGroup'] ?? ''));
                        if ($oidcGroup === '' || $groupId === '') {
                            continue;
                        }
                        $connection->insertOnDuplicate(
                            $roleTable,
                            [
                                'provider_id'     => $providerId,
                                'mapping_type'    => 'customer_group',
                                'oidc_group'      => $oidcGroup,
                                'magento_role_id' => $groupId,
                                'sort_order'      => $sortOrder++,
                            ],
                            ['magento_role_id', 'sort_order']
                        );
                    }
                }
            }
        }

        $setup->endSetup();
        return $this;
    }

    /**
     * Revert: truncate both new tables.
     *
     * Schema rollback (dropping the tables) is handled by db_schema.xml changes.
     */
    public function revert(): void
    {
        $setup = $this->moduleDataSetup;
        $setup->startSetup();
        $connection = $setup->getConnection();
        $connection->delete($setup->getTable(self::ATTR_TABLE));
        $connection->delete($setup->getTable(self::ROLE_TABLE));
        $setup->endSetup();
    }
}
