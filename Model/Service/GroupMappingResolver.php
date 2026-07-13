<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\ResourceModel\OauthRoleMapping;

/**
 * Resolves a Magento role/group ID from OIDC group claims.
 *
 * Single source of truth for the mapping fallback chain that was previously
 * duplicated in AdminUserCreator, CustomerUserCreator and AdminProfileSyncService:
 *   1. normalized m2oidc_oauth_role_mappings table (Phase 4)
 *   2. legacy JSON config column (pre-migration providers)
 *   3. case-insensitive group match — first configured mapping wins
 *   4. configured numeric default ID
 *   5. deny (null) — callers decide how to handle denial
 *
 * Stateless; safe to share between consumers.
 */
class GroupMappingResolver
{
    /** Mapping type for admin role resolution */
    public const TYPE_ADMIN_ROLE = OauthRoleMapping::TYPE_ADMIN_ROLE;

    /** Mapping type for customer group resolution */
    public const TYPE_CUSTOMER_GROUP = OauthRoleMapping::TYPE_CUSTOMER_GROUP;

    /** @var array<string, array{0: string, 1: string}> Legacy config key + JSON value key per mapping type */
    private const LEGACY_CONFIG = [
        self::TYPE_ADMIN_ROLE     => [OAuthConstants::ADMIN_ROLE_MAPPING, 'role'],
        self::TYPE_CUSTOMER_GROUP => [OAuthConstants::CUSTOMER_GROUP_MAPPING, 'customerGroup'],
    ];

    /**
     * @param MappingRepository $mappingRepository
     * @param OAuthUtility      $oauthUtility
     */
    public function __construct(
        private readonly MappingRepository $mappingRepository,
        private readonly OAuthUtility $oauthUtility
    ) {
    }

    /**
     * Run the full fallback chain: mappings match → numeric default → deny (null).
     *
     * @param  string       $mappingType TYPE_ADMIN_ROLE or TYPE_CUSTOMER_GROUP
     * @param  int          $providerId  OIDC provider ID (0 = legacy JSON only)
     * @param  mixed[]      $userGroups  Group claim values from the OIDC response
     * @param  string|null  $defaultId   Configured default role/group ID (used when numeric)
     * @return int|null Magento role/group ID, or null when denied (no match, no usable default)
     */
    public function resolve(string $mappingType, int $providerId, array $userGroups, ?string $defaultId = null): ?int
    {
        $matched = $this->matchGroups($this->getMappings($mappingType, $providerId), $userGroups);
        if ($matched !== null) {
            return $matched;
        }

        if (!in_array($defaultId, [null, '', '0'], true) && is_numeric($defaultId)) {
            $this->oauthUtility->customlog(
                "GroupMappingResolver: no {$mappingType} mapping matched, using configured default ID {$defaultId}"
            );
            return (int) $defaultId;
        }

        return null;
    }

    /**
     * Return the configured group mappings for a provider.
     *
     * Reads from the normalized m2oidc_oauth_role_mappings table first (Phase 4).
     * Falls back to the legacy JSON column when the table has no rows for this
     * provider (e.g. before the migration patch runs or on providers saved
     * through an older admin UI).
     *
     * @param  string $mappingType TYPE_ADMIN_ROLE or TYPE_CUSTOMER_GROUP
     * @param  int    $providerId  OIDC provider ID (0 = legacy JSON only)
     * @return array<int, array{group: string, id: string}>
     */
    public function getMappings(string $mappingType, int $providerId): array
    {
        if (!isset(self::LEGACY_CONFIG[$mappingType])) {
            throw new \InvalidArgumentException("Unknown group mapping type '{$mappingType}'");
        }
        [$legacyConfigKey, $legacyValueKey] = self::LEGACY_CONFIG[$mappingType];

        // --- Phase 4 path: read from normalized table ---
        $mappings = [];
        if ($providerId > 0) {
            $rows = $mappingType === self::TYPE_CUSTOMER_GROUP
                ? $this->mappingRepository->getCustomerGroupMappings($providerId)
                : $this->mappingRepository->getAdminRoleMappings($providerId);
            foreach ($rows as $row) {
                $mappings[] = [
                    'group' => (string) $row['oidc_group'],
                    'id'    => (string) $row['magento_role_id'],
                ];
            }
        }

        // --- Fallback path: legacy JSON column ---
        if ($mappings === []) {
            $mappingsJson = $this->oauthUtility->getStoreConfig($legacyConfigKey);
            if (!$this->oauthUtility->isBlank($mappingsJson)) {
                $decoded = json_decode((string) $mappingsJson, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $mappings[] = [
                            'group' => (string) ($row['group'] ?? ''),
                            'id'    => (string) ($row[$legacyValueKey] ?? ''),
                        ];
                    }
                }
            }
        }

        return $mappings;
    }

    /**
     * Match OIDC user groups against configured mappings (case-insensitive).
     *
     * Iterates mappings in configured order; the first mapping whose group
     * matches any user group wins. Rows with an empty group or empty target ID
     * are skipped. Besides the canonical 'id' key, the legacy 'role' and
     * 'customerGroup' value keys are accepted so pre-fetched legacy mapping
     * arrays (e.g. AdminProfileSyncService::syncRole()) can be passed directly.
     *
     * @param mixed[] $mappings Mapping rows (group + id/role/customerGroup)
     * @param mixed[] $userGroups Group claim values from the OIDC response
     * @return int|null Matched Magento role/group ID, or null when nothing matches
     */
    public function matchGroups(array $mappings, array $userGroups): ?int
    {
        if ($userGroups === [] || $mappings === []) {
            return null;
        }

        foreach ($mappings as $mapping) {
            $mappedGroup = (string) ($mapping['group'] ?? '');
            $mappedId    = (string) ($mapping['id'] ?? $mapping['role'] ?? $mapping['customerGroup'] ?? '');
            if ($mappedGroup === '' || $mappedId === '') {
                continue;
            }
            foreach ($userGroups as $userGroup) {
                if (strcasecmp((string) $userGroup, $mappedGroup) === 0) {
                    $this->oauthUtility->customlog(
                        "GroupMappingResolver: matched group '{$userGroup}' -> ID {$mappedId}"
                    );
                    return (int) $mappedId;
                }
            }
        }

        return null;
    }
}
