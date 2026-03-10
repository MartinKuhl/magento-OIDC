<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class RemoveShippingColumns implements SchemaPatchInterface
{
    private const TABLE = 'miniorange_oauth_client_apps';

    private const DROP_COLUMNS = [
        'shipping_address_attribute',
        'shipping_zip_attribute',
        'shipping_city_attribute',
        'shipping_state_attribute',
        'shipping_country_attribute',
        'oauth_am_sameasbilling',
    ];

    public function __construct(
        private readonly SchemaSetupInterface $schemaSetup
    ) {
    }

    public static function getDependencies(): array
    {
        return [AddProfileSyncColumns::class];
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

        foreach (self::DROP_COLUMNS as $column) {
            if ($connection->tableColumnExists($tableName, $column)) {
                $connection->dropColumn($tableName, $column);
            }
        }

        $this->schemaSetup->endSetup();
        return $this;
    }
}
