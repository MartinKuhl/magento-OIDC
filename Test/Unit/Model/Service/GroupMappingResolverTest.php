<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\Service\GroupMappingResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GroupMappingResolver.
 *
 * Verifies the unified fallback chain previously duplicated across
 * AdminUserCreator::getAdminRoleFromGroups(), CustomerUserCreator::getCustomerGroupFromOidcGroups()
 * and AdminProfileSyncService::syncRole():
 *   normalized table → legacy JSON column → case-insensitive group match → configured default → deny (null)
 *
 * @covers \M2Oidc\OAuth\Model\Service\GroupMappingResolver
 */
class GroupMappingResolverTest extends TestCase
{
    /** @var MappingRepository&MockObject */
    private MappingRepository $mappingRepository;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var GroupMappingResolver */
    private GroupMappingResolver $resolver;

    protected function setUp(): void
    {
        $this->mappingRepository = $this->createMock(MappingRepository::class);
        $this->oauthUtility      = $this->createMock(OAuthUtility::class);

        $this->oauthUtility->method('customlog');
        $this->oauthUtility->method('isBlank')->willReturnCallback(
            fn($v) => $v === null || $v === '' || $v === '0'
        );

        $this->resolver = new GroupMappingResolver($this->mappingRepository, $this->oauthUtility);
    }

    // -------------------------------------------------------------------------
    // resolve() — normalized table match (Phase 4)
    // -------------------------------------------------------------------------

    public function testResolveMatchesNormalizedTableRow(): void
    {
        $this->mappingRepository->method('getAdminRoleMappings')->with(5)->willReturn([
            ['oidc_group' => 'Devs', 'magento_role_id' => '3'],
        ]);

        $result = $this->resolver->resolve(
            GroupMappingResolver::TYPE_ADMIN_ROLE,
            5,
            ['Devs'],
            null
        );

        $this->assertSame(3, $result);
    }

    public function testResolveIgnoresLegacyJsonWhenNormalizedTableHasRows(): void
    {
        $this->mappingRepository->method('getAdminRoleMappings')->with(5)->willReturn([
            ['oidc_group' => 'Devs', 'magento_role_id' => '3'],
        ]);
        // Legacy JSON would map 'Devs' to role 99 — normalized table takes priority
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Devs', 'role' => '99']])],
        ]);

        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 5, ['Devs'], null);

        $this->assertSame(3, $result);
    }

    // -------------------------------------------------------------------------
    // resolve() — legacy JSON column fallback (providerId=0 or empty normalized table)
    // -------------------------------------------------------------------------

    public function testResolveFallsBackToLegacyJsonWhenNormalizedTableEmpty(): void
    {
        $this->mappingRepository->method('getAdminRoleMappings')->with(5)->willReturn([]);
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Devs', 'role' => '7']])],
        ]);

        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 5, ['Devs'], null);

        $this->assertSame(7, $result);
    }

    public function testResolveUsesLegacyJsonWhenProviderIdIsZero(): void
    {
        $this->mappingRepository->expects($this->never())->method('getAdminRoleMappings');
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Devs', 'role' => '7']])],
        ]);

        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 0, ['Devs'], null);

        $this->assertSame(7, $result);
    }

    public function testResolveCustomerGroupUsesLegacyCustomerGroupJsonKey(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::CUSTOMER_GROUP_MAPPING, json_encode([['group' => 'Vip', 'customerGroup' => '4']])],
        ]);

        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_CUSTOMER_GROUP, 0, ['Vip'], null);

        $this->assertSame(4, $result);
    }

    // -------------------------------------------------------------------------
    // resolve() — case-insensitive group match
    // -------------------------------------------------------------------------

    public function testResolveMatchesCaseInsensitively(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'devs', 'role' => '5']])],
        ]);

        // OIDC sends uppercase, config has lowercase
        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 0, ['DEVS'], null);

        $this->assertSame(5, $result);
    }

    public function testResolveFirstMatchingMappingWins(): void
    {
        $mappings = [
            ['group' => 'Editors', 'role' => '2'],
            ['group' => 'Devs',    'role' => '3'],
        ];
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode($mappings)],
        ]);

        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 0, ['Devs', 'Editors'], null);

        $this->assertSame(2, $result, 'First configured mapping row that matches any user group should win');
    }

    // -------------------------------------------------------------------------
    // resolve() — configured default fallback
    // -------------------------------------------------------------------------

    public function testResolveFallsBackToConfiguredDefaultWhenNoGroupMatches(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Admins', 'role' => '1']])],
        ]);

        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 0, ['Engineers'], '4');

        $this->assertSame(4, $result);
    }

    public function testResolveIgnoresNonNumericDefault(): void
    {
        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 0, [], 'Administrator');

        $this->assertNull($result, 'Non-numeric default must not be used');
    }

    public function testResolveIgnoresBlankDefault(): void
    {
        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 0, [], '');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // resolve() — deny (null) when nothing matches and no default
    // -------------------------------------------------------------------------

    public function testResolveReturnsNullWhenNoMatchAndNoDefault(): void
    {
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::ADMIN_ROLE_MAPPING, json_encode([['group' => 'Admins', 'role' => '1']])],
        ]);

        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 0, ['RandomGroup'], null);

        $this->assertNull($result);
    }

    public function testResolveReturnsNullWhenGroupsEmptyAndNoDefault(): void
    {
        $result = $this->resolver->resolve(GroupMappingResolver::TYPE_ADMIN_ROLE, 0, [], null);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // getMappings() — normalized table normalization + legacy fallback
    // -------------------------------------------------------------------------

    public function testGetMappingsNormalizesTableRowsToGroupIdShape(): void
    {
        $this->mappingRepository->method('getAdminRoleMappings')->with(9)->willReturn([
            ['oidc_group' => 'Devs', 'magento_role_id' => '3'],
        ]);

        $mappings = $this->resolver->getMappings(GroupMappingResolver::TYPE_ADMIN_ROLE, 9);

        $this->assertSame([['group' => 'Devs', 'id' => '3']], $mappings);
    }

    public function testGetMappingsCustomerGroupUsesCustomerGroupRepositoryMethod(): void
    {
        $this->mappingRepository->method('getCustomerGroupMappings')->with(9)->willReturn([
            ['oidc_group' => 'Vip', 'magento_role_id' => '4'],
        ]);

        $mappings = $this->resolver->getMappings(GroupMappingResolver::TYPE_CUSTOMER_GROUP, 9);

        $this->assertSame([['group' => 'Vip', 'id' => '4']], $mappings);
    }

    public function testGetMappingsThrowsForUnknownMappingType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->getMappings('bogus_type', 0);
    }

    // -------------------------------------------------------------------------
    // matchGroups() — direct unit coverage (used by AdminProfileSyncService::syncRole())
    // -------------------------------------------------------------------------

    public function testMatchGroupsReturnsNullForEmptyInputs(): void
    {
        $this->assertNull($this->resolver->matchGroups([], ['Devs']));
        $this->assertNull($this->resolver->matchGroups([['group' => 'Devs', 'id' => '3']], []));
    }

    public function testMatchGroupsSkipsRowsWithEmptyGroupOrId(): void
    {
        $mappings = [
            ['group' => '',        'id' => '7'],
            ['group' => 'Devs',    'id' => ''],
        ];

        $this->assertNull($this->resolver->matchGroups($mappings, ['Devs']));
    }

    public function testMatchGroupsAcceptsLegacyRoleKey(): void
    {
        // AdminProfileSyncService::syncRole() passes pre-fetched legacy-shaped rows ('role' key)
        $mappings = [['group' => 'Devs', 'role' => '3']];

        $this->assertSame(3, $this->resolver->matchGroups($mappings, ['Devs']));
    }

    public function testMatchGroupsAcceptsLegacyCustomerGroupKey(): void
    {
        $mappings = [['group' => 'Vip', 'customerGroup' => '4']];

        $this->assertSame(4, $this->resolver->matchGroups($mappings, ['Vip']));
    }

    public function testMatchGroupsIsCaseInsensitive(): void
    {
        $mappings = [['group' => 'devs', 'id' => '5']];

        $this->assertSame(5, $this->resolver->matchGroups($mappings, ['DEVS']));
    }
}
