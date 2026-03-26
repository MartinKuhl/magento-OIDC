<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for m2oidc_oauth_attribute_mappings.
 *
 * Provides low-level DB operations for the normalized attribute mapping table.
 * Higher-level callers should use MappingRepository for cached access.
 */
class OauthAttributeMapping extends AbstractDb
{
    private const string TABLE_NAME = 'm2oidc_oauth_attribute_mappings';

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'id');
    }

    /**
     * Return all attribute mappings for a provider as an associative array.
     *
     * @param  int $providerId
     * @return array<string, array{attribute_name: string, sync_on_sso: int}>
     *         Keys are attribute_type values (e.g. 'email', 'firstname').
     */
    public function getMappingsForProvider(int $providerId): array
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return [];
        }
        $select = $connection->select()
            ->from($this->getMainTable(), ['attribute_type', 'attribute_name', 'sync_on_sso'])
            ->where('provider_id = ?', $providerId);

        $rows = $connection->fetchAll($select);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['attribute_type']] = [
                'attribute_name' => (string) $row['attribute_name'],
                'sync_on_sso'    => (int) $row['sync_on_sso'],
            ];
        }
        return $result;
    }

    /**
     * Insert or update a single attribute mapping row.
     *
     * Uses INSERT ON DUPLICATE KEY UPDATE keyed on (provider_id, attribute_type).
     *
     * @param int    $providerId
     * @param string $attributeType  E.g. 'email', 'firstname', 'billing_city'
     * @param string $attributeName  The OIDC claim key configured by the admin
     * @param int    $syncOnSso      1 = re-sync this attribute on every SSO login
     */
    public function saveMapping(
        int $providerId,
        string $attributeType,
        string $attributeName,
        int $syncOnSso = 0
    ): void {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->insertOnDuplicate(
            $this->getMainTable(),
            [
                'provider_id'    => $providerId,
                'attribute_type' => $attributeType,
                'attribute_name' => $attributeName,
                'sync_on_sso'    => $syncOnSso,
            ],
            ['attribute_name', 'sync_on_sso']
        );
    }
}
