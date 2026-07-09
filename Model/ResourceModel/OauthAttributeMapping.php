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
    /**
     * @var string
     */
    private const TABLE_NAME = 'm2oidc_oauth_attribute_mappings';

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
     * @return array<string, array{
     *     attribute_name: string,
     *     sync_on_sso: int,
     *     transform_function: string|null,
     *     transform_params: string|null
     * }>
     *         Keys are attribute_type values (e.g. 'email', 'firstname').
     */
    public function getMappingsForProvider(int $providerId): array
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return [];
        }
        $select = $connection->select()
            ->from(
                $this->getMainTable(),
                ['attribute_type', 'attribute_name', 'sync_on_sso', 'transform_function', 'transform_params']
            )
            ->where('provider_id = ?', $providerId);

        $rows = $connection->fetchAll($select);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['attribute_type']] = [
                'attribute_name'     => (string) $row['attribute_name'],
                'sync_on_sso'        => (int) $row['sync_on_sso'],
                'transform_function' => isset($row['transform_function']) ? (string) $row['transform_function'] : null,
                'transform_params'   => isset($row['transform_params']) ? (string) $row['transform_params'] : null,
            ];
        }
        return $result;
    }

    /**
     * Insert or update a single attribute mapping row.
     *
     * Uses INSERT ON DUPLICATE KEY UPDATE keyed on (provider_id, attribute_type).
     *
     * @param int         $providerId
     * @param string      $attributeType      E.g. 'email', 'firstname', 'billing_city'
     * @param string      $attributeName      The OIDC claim key configured by the admin
     * @param int         $syncOnSso          1 = re-sync this attribute on every SSO login
     * @param string|null $transformFunction  Transform function name (null = passthrough)
     * @param string|null $transformParams    JSON-encoded transform parameters
     */
    public function saveMapping(
        int $providerId,
        string $attributeType,
        string $attributeName,
        int $syncOnSso = 0,
        ?string $transformFunction = null,
        ?string $transformParams = null
    ): void {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->insertOnDuplicate(
            $this->getMainTable(),
            [
                'provider_id'        => $providerId,
                'attribute_type'     => $attributeType,
                'attribute_name'     => $attributeName,
                'sync_on_sso'        => $syncOnSso,
                'transform_function' => $transformFunction,
                'transform_params'   => $transformParams,
            ],
            ['attribute_name', 'sync_on_sso', 'transform_function', 'transform_params']
        );
    }

    /**
     * Replace all attribute mapping rows for a provider (delete-then-insert).
     *
     * Used by the admin save controller to apply the full set of rows submitted by
     * the dynamic attribute mapping rows UI, including rows that were removed.
     *
     * @param int          $providerId
     * @param array<mixed> $rows  Mapping rows: attribute_type, attribute_name, sync_on_sso, transform_*.
     */
    public function replaceProviderMappings(int $providerId, array $rows): void
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->beginTransaction();
        try {
            $connection->delete($this->getMainTable(), ['provider_id = ?' => $providerId]);
            foreach ($rows as $row) {
                $type = trim((string) ($row['attribute_type'] ?? ''));
                $name = trim((string) ($row['attribute_name'] ?? ''));
                if ($type === '' || $name === '') {
                    continue;
                }
                $transformFn = isset($row['transform_function']) && $row['transform_function'] !== ''
                    ? (string) $row['transform_function']
                    : null;
                $transformPr = isset($row['transform_params']) && $row['transform_params'] !== ''
                    ? (string) $row['transform_params']
                    : null;
                $connection->insert($this->getMainTable(), [
                    'provider_id'        => $providerId,
                    'attribute_type'     => $type,
                    'attribute_name'     => $name,
                    'sync_on_sso'        => (int) ($row['sync_on_sso'] ?? 0),
                    'transform_function' => $transformFn,
                    'transform_params'   => $transformPr,
                ]);
            }
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
