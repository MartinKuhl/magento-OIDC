<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\Provider;

use MiniOrange\OAuth\Model\ResourceModel\OauthAttributeMapping as AttrMappingResource;
use MiniOrange\OAuth\Model\ResourceModel\OauthRoleMapping as RoleMappingResource;

/**
 * Facade for reading normalized attribute and role/group mappings.
 *
 * Wraps the two new resource models with in-request caching so that multiple
 * calls for the same provider within a single request perform only one DB query
 * per table. This is the preferred entry point for service classes.
 *
 * Write operations (INSERT / REPLACE) are delegated directly to the resource
 * models since they are only called from the admin Save controller.
 */
class MappingRepository
{
    /** @var AttrMappingResource */
    private readonly AttrMappingResource $attrResource;

    /** @var RoleMappingResource */
    private readonly RoleMappingResource $roleResource;

    /** @var array<int, array<string, array{attribute_name: string, sync_on_sso: int}>> */
    private array $attrCache = [];

    /** @var array<int, array<string, list<array{oidc_group: string, magento_role_id: string}>>> */
    private array $roleCache = [];

    /**
     * @param AttrMappingResource $attrResource
     * @param RoleMappingResource $roleResource
     */
    public function __construct(
        AttrMappingResource $attrResource,
        RoleMappingResource $roleResource
    ) {
        $this->attrResource = $attrResource;
        $this->roleResource = $roleResource;
    }

    /**
     * Return the configured OIDC claim name for an attribute type, or null if not set.
     *
     * @param  int    $providerId
     * @param  string $attributeType  E.g. 'email', 'firstname', 'billing_city'
     */
    public function getAttributeName(int $providerId, string $attributeType): ?string
    {
        $map = $this->getAttributeMap($providerId);
        return isset($map[$attributeType]) ? $map[$attributeType]['attribute_name'] : null;
    }

    /**
     * Return all attribute mappings for a provider (type → name).
     *
     * @param  int $providerId
     * @return array<string, string>  attribute_type => claim_name
     */
    public function getAttributeNames(int $providerId): array
    {
        $map = $this->getAttributeMap($providerId);
        $result = [];
        foreach ($map as $type => $data) {
            $result[$type] = $data['attribute_name'];
        }
        return $result;
    }

    /**
     * Return whether a given attribute should be re-synced on every SSO login.
     *
     * @param  int    $providerId
     * @param  string $attributeType
     */
    public function isSyncOnSso(int $providerId, string $attributeType): bool
    {
        $map = $this->getAttributeMap($providerId);
        return (bool) ($map[$attributeType]['sync_on_sso'] ?? false);
    }

    /**
     * Return admin role mappings for a provider.
     *
     * @param  int $providerId
     * @return list<array{oidc_group: string, magento_role_id: string}>
     */
    public function getAdminRoleMappings(int $providerId): array
    {
        return $this->getRoleMappings($providerId, RoleMappingResource::TYPE_ADMIN_ROLE);
    }

    /**
     * Return customer group mappings for a provider.
     *
     * @param  int $providerId
     * @return list<array{oidc_group: string, magento_role_id: string}>
     *         (magento_role_id holds the customer group ID in this context)
     */
    public function getCustomerGroupMappings(int $providerId): array
    {
        return $this->getRoleMappings($providerId, RoleMappingResource::TYPE_CUSTOMER_GROUP);
    }

    /**
     * Delegate attribute mapping save to the resource model.
     *
     * Invalidates the per-provider attribute cache.
     *
     * @param int    $providerId
     * @param string $attributeType
     * @param string $attributeName
     * @param int    $syncOnSso
     */
    public function saveAttributeMapping(
        int $providerId,
        string $attributeType,
        string $attributeName,
        int $syncOnSso = 0
    ): void {
        $this->attrResource->saveMapping($providerId, $attributeType, $attributeName, $syncOnSso);
        unset($this->attrCache[$providerId]);
    }

    /**
     * Delegate role/group mapping replace to the resource model.
     *
     * Invalidates the per-provider role cache for the given mapping type.
     *
     * @param int    $providerId
     * @param string $mappingType
     * @param array  $rows
     * @psalm-param array<int, array{oidc_group: string, magento_role_id: string}> $rows
     */
    public function replaceRoleMappings(int $providerId, string $mappingType, array $rows): void
    {
        $this->roleResource->replaceProviderMappings($providerId, $mappingType, $rows);
        unset($this->roleCache[$providerId][$mappingType]);
    }

    /**
     * Return the full attribute map including sync_on_sso flags.
     *
     * Used by CLI export to persist complete mapping state.
     *
     * @param  int $providerId
     * @return array<string, array{attribute_name: string, sync_on_sso: int}>
     */
    public function getFullAttributeMap(int $providerId): array
    {
        return $this->getAttributeMap($providerId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load (and cache) all attribute mappings for a provider.
     *
     * @param  int $providerId
     * @return array<string, array{attribute_name: string, sync_on_sso: int}>
     */
    private function getAttributeMap(int $providerId): array
    {
        if (!isset($this->attrCache[$providerId])) {
            $this->attrCache[$providerId] = $this->attrResource->getMappingsForProvider($providerId);
        }
        return $this->attrCache[$providerId];
    }

    /**
     * Load (and cache) role/group mappings for a provider + type.
     *
     * @param  int    $providerId
     * @param  string $mappingType
     * @return list<array{oidc_group: string, magento_role_id: string}>
     */
    private function getRoleMappings(int $providerId, string $mappingType): array
    {
        if (!isset($this->roleCache[$providerId][$mappingType])) {
            if (!isset($this->roleCache[$providerId])) {
                $this->roleCache[$providerId] = [];
            }
            $this->roleCache[$providerId][$mappingType] =
                $this->roleResource->getMappingsForProvider($providerId, $mappingType);
        }
        return $this->roleCache[$providerId][$mappingType];
    }
}
