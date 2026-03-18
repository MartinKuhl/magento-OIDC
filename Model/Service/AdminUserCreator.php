<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\AttributeMapperInterface;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use Magento\User\Model\UserFactory;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\Framework\Math\Random;

/**
 * Service class for creating Admin Users via OAuth/OIDC
 */
class AdminUserCreator
{
    /** @var \Magento\User\Model\UserFactory */
    private readonly \Magento\User\Model\UserFactory $userFactory;

    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    private readonly \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var \Magento\Framework\Math\Random */
    private readonly \Magento\Framework\Math\Random $randomUtility;

    /** @var \Magento\User\Model\ResourceModel\User */
    private readonly \Magento\User\Model\ResourceModel\User $userResource;

    /** @var UserCollectionFactory */
    private readonly UserCollectionFactory $userCollectionFactory;

    /** @var \M2Oidc\OAuth\Model\ResourceModel\UserProvider */
    private readonly UserProviderResource $userProviderResource;

    /** @var MappingRepository */
    private readonly MappingRepository $mappingRepository;

    /** @var AttributeMapperInterface */
    private readonly AttributeMapperInterface $adminAttributeMapper;

    /**
     * Initialize admin user creator.
     *
     * @param UserFactory                            $userFactory
     * @param OAuthUtility                           $oauthUtility
     * @param Random                                 $randomUtility
     * @param \Magento\User\Model\ResourceModel\User $userResource
     * @param UserCollectionFactory                  $userCollectionFactory
     * @param UserProviderResource                   $userProviderResource
     * @param MappingRepository                      $mappingRepository
     * @param AttributeMapperInterface               $adminAttributeMapper
     */
    public function __construct(
        UserFactory $userFactory,
        OAuthUtility $oauthUtility,
        Random $randomUtility,
        \Magento\User\Model\ResourceModel\User $userResource,
        UserCollectionFactory $userCollectionFactory,
        UserProviderResource $userProviderResource,
        MappingRepository $mappingRepository,
        AttributeMapperInterface $adminAttributeMapper
    ) {
        $this->userFactory = $userFactory;
        $this->oauthUtility = $oauthUtility;
        $this->randomUtility = $randomUtility;
        $this->userResource = $userResource;
        $this->userCollectionFactory = $userCollectionFactory;
        $this->userProviderResource = $userProviderResource;
        $this->mappingRepository = $mappingRepository;
        $this->adminAttributeMapper = $adminAttributeMapper;
    }

    /**
     * Create an admin user based on OIDC attributes
     *
     * @param  string      $email
     * @param  mixed       $userName
     * @param  string|null $firstName
     * @param  string|null $lastName
     * @param  array       $userGroups
     * @param  int         $providerId OIDC provider ID (0 = unknown / not tracked)
     * @return \Magento\User\Model\User|null
     */
    public function createAdminUser(string $email, $userName, $firstName, $lastName, array $userGroups, int $providerId = 0) // phpcs:ignore Generic.Files.LineLength.TooLong
    {
        $this->oauthUtility->customlog("AdminUserCreator: Starting creation for " . $email);

        // Guard against IdPs returning preferred_username as an array
        $userName = is_array($userName) ? implode(' ', $userName) : (string) $userName;

        // Apply name fallbacks via strategy (Phase 3.2)
        $nameMapped = $this->adminAttributeMapper->map(
            ['firstname' => $firstName, 'lastname' => $lastName],
            ['_email' => $email]
        );
        $firstName = (string) ($nameMapped['firstname'] ?? $firstName);
        $lastName  = (string) ($nameMapped['lastname']  ?? $lastName);

        // Determine Role ID
        $roleId = $this->getAdminRoleFromGroups($userGroups, $providerId);

        if (!$roleId) {
            $this->oauthUtility->customlog("AdminUserCreator: No suitable role found for user. Creation aborted.");
            return null;
        }

        return $this->saveAdminUser($userName, $email, $firstName, $lastName, $roleId, $providerId);
    }

    /**
     * Save the admin user to database
     *
     * @param  string $userName
     * @param  string $email
     * @param  string $firstName
     * @param  string $lastName
     * @param  int    $roleId
     * @param  int    $providerId OIDC provider ID (0 = not tracked)
     * @return \Magento\User\Model\User|null
     */
    private function saveAdminUser(
        string $userName,
        string $email,
        string $firstName,
        string $lastName,
        int $roleId,
        int $providerId = 0
    ) {
        // Generate a 32-char password and shuffle to avoid predictable character-class ordering (SEC-12).
        $randomPassword = str_shuffle(
            $this->randomUtility->getRandomString(28)
            . $this->randomUtility->getRandomString(2, '!@#$%^&*')
            . $this->randomUtility->getRandomString(2, '0123456789')
        );

        $user = $this->userFactory->create();
        $user->setUsername($userName)
            ->setFirstname($firstName)
            ->setLastname($lastName)
            ->setEmail($email)
            ->setPassword($randomPassword)
            ->setIsActive(1);

        $connection = $this->userResource->getConnection();
        $connection->beginTransaction();
        try {
            $this->userResource->save($user);
            $this->oauthUtility->customlog("AdminUserCreator: User saved with ID: " . $user->getId());

            $user->setRoleId($roleId);
            $this->userResource->save($user);
            $this->oauthUtility->customlog(
                "AdminUserCreator: Role " . $roleId . " assigned to user ID: " . $user->getId()
            );

            // Track which OIDC provider created this admin user (inside transaction)
            if ($providerId > 0 && $user->getId()) {
                $this->userProviderResource->saveMapping('admin', (int) $user->getId(), $providerId);
                $this->oauthUtility->customlog(
                    "AdminUserCreator: Provider mapping saved (admin ID "
                    . $user->getId() . " → provider ID " . $providerId . ")"
                );
            }

            $connection->commit();
            return $user;
        } catch (\Magento\Framework\Exception\AlreadyExistsException $e) {
            $connection->rollBack();
            $this->oauthUtility->customlog("AdminUserCreator: User already exists - " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            $connection->rollBack();
            $this->oauthUtility->customlog("AdminUserCreator: Error creating user: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get admin role ID from OIDC groups using configured mappings.
     *
     * Reads from the normalized m2oidc_oauth_role_mappings table first (Phase 4).
     * Falls back to the legacy JSON column when the new table has no data for this provider
     * (e.g. before the migration patch runs or on providers saved through older admin UI).
     *
     * @param  array $userGroups Groups from OIDC response
     * @param  int   $providerId OIDC provider ID (used for new table lookup)
     * @return int|null Admin role ID or null if denied
     */
    private function getAdminRoleFromGroups(array $userGroups, int $providerId = 0): ?int
    {
        // --- Phase 4 path: read from normalized table ---
        $roleMappings = [];
        if ($providerId > 0) {
            $newRows = $this->mappingRepository->getAdminRoleMappings($providerId);
            // Normalize to legacy key names so the loop below is unchanged
            foreach ($newRows as $row) {
                $roleMappings[] = [
                    'group' => $row['oidc_group'],
                    'role'  => $row['magento_role_id'],
                ];
            }
        }

        // --- Fallback path: legacy JSON column ---
        if ($roleMappings === []) {
            $roleMappingsJson = $this->oauthUtility->getStoreConfig(OAuthConstants::ADMIN_ROLE_MAPPING);
            if (!$this->oauthUtility->isBlank($roleMappingsJson)) {
                $decoded = json_decode((string) $roleMappingsJson, true);
                $roleMappings = is_array($decoded) ? $decoded : [];
            }
        }

        // Use strict empty-array check instead of empty() to avoid falsy edge-cases.
        if ($userGroups !== [] && $roleMappings !== []) {
            foreach ($roleMappings as $mapping) {
                $mappedGroup = $mapping['group'] ?? '';
                $mappedRole = $mapping['role'] ?? '';

                if (!empty($mappedGroup) && !empty($mappedRole)) {
                    // Check if user has this group (case-insensitive comparison)
                    foreach ($userGroups as $userGroup) {
                        if (strcasecmp((string) $userGroup, (string) $mappedGroup) === 0) {
                            $this->oauthUtility->customlog(
                                "AdminUserCreator: Found matching role mapping: group '$userGroup' "
                                . "-> role ID '$mappedRole'"
                            );
                            return (int) $mappedRole;
                        }
                    }
                }
            }
        }

        // No mapping found, use default role
        $defaultRole = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_ROLE);
        if (!empty($defaultRole) && is_numeric($defaultRole)) {
            $this->oauthUtility->customlog(
                "AdminUserCreator: Using configured default role ID: " . (string)$defaultRole
            );
            return (int) $defaultRole;
        }

        $this->oauthUtility->customlog(
            "AdminUserCreator: No role mapping found and no default role configured. "
            . "Denying admin creation."
        );
        return null;
    }

    /**
     * Return the role-mapping array for a given provider.
     *
     * Checks the normalized m2oidc_oauth_role_mappings table first (Phase 4),
     * falls back to the legacy JSON column. Intended for use by
     * AdminProfileSyncService::syncRole() so that role re-evaluation on every
     * login uses the same mapping data as initial user creation.
     *
     * @param  int $providerId OIDC provider ID (0 = legacy JSON only)
     * @return array<int,array{group:string,role:string}>
     */
    public function getAdminRoleMappingsForProvider(int $providerId): array
    {
        $roleMappings = [];
        if ($providerId > 0) {
            $newRows = $this->mappingRepository->getAdminRoleMappings($providerId);
            foreach ($newRows as $row) {
                $roleMappings[] = [
                    'group' => $row['oidc_group'],
                    'role'  => $row['magento_role_id'],
                ];
            }
        }
        if ($roleMappings === []) {
            $json = $this->oauthUtility->getStoreConfig(OAuthConstants::ADMIN_ROLE_MAPPING);
            if (!$this->oauthUtility->isBlank($json)) {
                $decoded = json_decode((string) $json, true);
                $roleMappings = is_array($decoded) ? $decoded : [];
            }
        }
        return $roleMappings;
    }

    /**
     * Check if the email/username belongs to an existing admin user
     *
     * @param  string $email
     */
    public function isAdminUser(string $email): bool
    {
        $this->oauthUtility->customlog("AdminUserCreator: Checking if user is admin: " . $email);

        // Single query: match on email OR username (some IdPs pass the email as username)
        $userCollection = $this->userCollectionFactory->create()
            ->addFieldToFilter(
                ['email', 'username'],
                [['eq' => $email], ['eq' => $email]]
            );

        if ($userCollection->getSize() > 0) {
            $user = $userCollection->getFirstItem();
            $this->oauthUtility->customlog("AdminUserCreator: Admin user found - ID: " . $user->getId());
            return true;
        }

        return false;
    }
}
