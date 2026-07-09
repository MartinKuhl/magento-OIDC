<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for m2oidc_oauth_role_mappings.
 *
 * Provides low-level DB operations for the normalized role/group mapping table.
 * Higher-level callers should use MappingRepository for cached access.
 */
class OauthRoleMapping extends AbstractDb
{
    /**
     * @var string
     */
    private const TABLE_NAME = 'm2oidc_oauth_role_mappings';

    /** Mapping type constant for admin role mappings.
     * @var string */
    public const TYPE_ADMIN_ROLE = 'admin_role';

    /** Mapping type constant for customer group mappings.
     * @var string */
    public const TYPE_CUSTOMER_GROUP = 'customer_group';

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'id');
    }

    /**
     * Return all mappings for a provider and mapping type.
     *
     * @param  int    $providerId
     * @param  string $mappingType  TYPE_ADMIN_ROLE or TYPE_CUSTOMER_GROUP
     * @return array<int, array{oidc_group: string, magento_role_id: string}>
     *         Ordered by sort_order ASC.
     */
    public function getMappingsForProvider(int $providerId, string $mappingType): array
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return [];
        }
        $select = $connection->select()
            ->from($this->getMainTable(), ['oidc_group', 'magento_role_id'])
            ->where('provider_id = ?', $providerId)
            ->where('mapping_type = ?', $mappingType)
            ->order('sort_order ASC');

        return $connection->fetchAll($select);
    }

    /**
     * Delete all existing mappings for a provider+type then insert the new rows.
     *
     * Runs in a single transaction to avoid partial writes.
     *
     * @param int    $providerId
     * @param string $mappingType
     * @param array  $rows
     * @psalm-param array<int, array<string,string>> $rows
     */
    public function replaceProviderMappings(int $providerId, string $mappingType, array $rows): void
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->beginTransaction();
        try {
            $connection->delete(
                $this->getMainTable(),
                ['provider_id = ?' => $providerId, 'mapping_type = ?' => $mappingType]
            );

            $sortOrder = 0;
            foreach ($rows as $row) {
                $oidcGroup   = trim((string) ($row['oidc_group'] ?? $row['group'] ?? ''));
                $roleId      = trim((string) ($row['magento_role_id'] ?? $row['role'] ?? $row['customerGroup'] ?? ''));
                if ($oidcGroup === '' || $roleId === '') {
                    continue;
                }
                $connection->insert($this->getMainTable(), [
                    'provider_id'    => $providerId,
                    'mapping_type'   => $mappingType,
                    'oidc_group'     => $oidcGroup,
                    'magento_role_id' => $roleId,
                    'sort_order'     => $sortOrder++,
                ]);
            }

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
