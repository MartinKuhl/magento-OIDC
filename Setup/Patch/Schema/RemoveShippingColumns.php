<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Removes obsolete shipping address attribute columns from m2oidc_oauth_client_apps.
 */
class RemoveShippingColumns implements SchemaPatchInterface
{
    private const string TABLE = 'm2oidc_oauth_client_apps';

    private const array DROP_COLUMNS = [
        'shipping_address_attribute',
        'shipping_zip_attribute',
        'shipping_city_attribute',
        'shipping_state_attribute',
        'shipping_country_attribute',
        'oauth_am_sameasbilling',
    ];

    /**
     * Constructor.
     *
     * @param SchemaSetupInterface $schemaSetup
     */
    public function __construct(
        private readonly SchemaSetupInterface $schemaSetup
    ) {
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [AddProfileSyncColumns::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * Drop legacy shipping attribute columns.
     */
    public function apply(): self
    {
        $this->schemaSetup->startSetup();
        $connection = $this->schemaSetup->getConnection();
        $tableName  = $this->schemaSetup->getTable(self::TABLE);

        foreach (self::DROP_COLUMNS as $column) {
            if ($connection->tableColumnExists($tableName, $column)) {
                $connection->dropColumn($tableName, $column);
            }
        }

        $this->schemaSetup->endSetup();
        return $this;
    }
}
