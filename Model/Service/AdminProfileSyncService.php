<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\User\Model\UserFactory;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Authorization\Model\Acl\Role\User as RoleUser;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Provider\MappingRepository;

/**
 * Syncs existing admin user profile and role from OIDC claims
 * on every SSO login when the corresponding per-provider flag is enabled.
 *
 * Per-attribute sync control: when a normalized attribute mapping row exists for the
 * provider and its `sync_on_sso` flag is 0, that attribute is skipped regardless of
 * the coarse global `sync_admin_profile_on_sso` switch.
 *
 * Designed to be injected into CheckAttributeMappingAction via DI.
 */
class AdminProfileSyncService
{
    /**
     * Constructor.
     *
     * @param UserFactory               $userFactory
     * @param UserResource              $userResource
     * @param RoleCollectionFactory     $roleCollectionFactory
     * @param OAuthUtility              $oauthUtility
     * @param OidcAuthenticationService $oidcAuthenticationService
     * @param MappingRepository         $mappingRepository
     */
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource,
        private readonly RoleCollectionFactory $roleCollectionFactory,
        private readonly OAuthUtility $oauthUtility,
        private readonly OidcAuthenticationService $oidcAuthenticationService,
        private readonly MappingRepository $mappingRepository
    ) {
    }

    // ──────────────────────────────────────────────
    //  Profile sync (firstname, lastname, username)
    // ──────────────────────────────────────────────

    /**
     * Update admin firstname, lastname, username and email from OIDC claims.
     *
     * When $providerId > 0 and a normalized attribute mapping row exists for a given
     * attribute type, the row's `sync_on_sso` flag is consulted.  When `sync_on_sso = 0`
     * the attribute is skipped.  When no normalized row exists (legacy mode) the attribute
     * is always synced (the coarse global flag still guards the call-site).
     *
     * @param string  $email          Admin email (lookup key)
     * @param mixed[] $flattenedAttrs Flattened OIDC attributes
     * @param mixed[] $rawAttrs       Raw (nested) OIDC attributes
     * @param string  $firstNameKey   OIDC claim key for firstname
     * @param string  $lastNameKey    OIDC claim key for lastname
     * @param string  $usernameKey    OIDC claim key for username
     * @param string  $emailKey       OIDC claim key for email (empty = skip email sync)
     * @param int     $providerId     Provider ID for per-attribute sync flag lookup (0 = legacy)
     */
    public function syncProfile(
        string $email,
        array $flattenedAttrs,
        array $rawAttrs,
        string $firstNameKey,
        string $lastNameKey,
        string $usernameKey,
        string $emailKey = '',
        int $providerId = 0
    ): void {
        $user = $this->loadAdminByEmail($email);
        if (!$user instanceof \Magento\User\Model\User) {
            return;
        }

        // Load normalized attribute map once; used to check per-attribute sync_on_sso flags.
        $attrMap = $providerId > 0 ? $this->mappingRepository->getFullAttributeMap($providerId) : [];

        $changed = false;

        if ($this->shouldSync($attrMap, 'firstname')) {
            $fn = $this->extract($firstNameKey, $flattenedAttrs, $rawAttrs);
            if ($fn !== null && $user->getFirstName() !== $fn) {
                $user->setFirstName($fn);
                $changed = true;
            }
        }

        if ($this->shouldSync($attrMap, 'lastname')) {
            $ln = $this->extract($lastNameKey, $flattenedAttrs, $rawAttrs);
            if ($ln !== null && $user->getLastName() !== $ln) {
                $user->setLastName($ln);
                $changed = true;
            }
        }

        if ($this->shouldSync($attrMap, 'username')) {
            $un = $this->extract($usernameKey, $flattenedAttrs, $rawAttrs);
            if ($un !== null && $user->getUserName() !== $un) {
                // M-08: Check-then-save has a small TOCTOU window. Acceptable since
                // admin logins are low-frequency and MySQL UNIQUE constraint provides a safety net.
                $existing = $this->userFactory->create();
                $this->userResource->load($existing, $un, 'username');
                if (!$existing->getId() || (int) $existing->getId() === (int) $user->getId()) {
                    $user->setUserName($un);
                    $changed = true;
                } else {
                    $this->oauthUtility->customlog(
                        'AdminProfileSync: username "' . $un
                        . '" already taken by admin #' . $existing->getId()
                        . ' — skipping username update'
                    );
                }
            }
        }

        if ($this->shouldSync($attrMap, 'email')) {
            $newEmail = $this->extract($emailKey, $flattenedAttrs, $rawAttrs);
            if ($newEmail !== null && $user->getEmail() !== $newEmail) {
                $user->setEmail($newEmail);
                $changed = true;
            }
        }

        if ($changed) {
            // setHasDataChanges to bypass password validation on save
            $user->setHasDataChanges(true);
            $this->userResource->save($user);
            $this->oauthUtility->customlog(
                'AdminProfileSync: profile updated for ' . $email
            );
        }
    }

    // ──────────────────────────────────────────────
    //  Role sync
    // ──────────────────────────────────────────────

    /**
     * Re-evaluate and update admin role from OIDC group claims.
     *
     * Uses the same role-mapping logic as AdminUserCreator:
     * iterate provider role mappings, match against OIDC groups,
     * assign first matching role.
     *
     * @param string  $email          Admin email
     * @param mixed[] $flattenedAttrs Flattened OIDC attributes
     * @param mixed[] $rawAttrs       Raw (nested) OIDC attributes
     * @param string  $groupAttribute OIDC claim key for groups
     * @param mixed[] $roleMappings   [['group' => 'idp-group', 'role' => 'magento-role-id'], ...]
     * @param string  $defaultRole    Fallback role name if no mapping matches
     */
    public function syncRole(
        string $email,
        array $flattenedAttrs,
        array $rawAttrs,
        string $groupAttribute,
        array $roleMappings,
        string $defaultRole = ''
    ): void {
        $user = $this->loadAdminByEmail($email);
        if (!$user instanceof \Magento\User\Model\User) {
            return;
        }

        // Extract user groups from OIDC claims
        $userGroups = $this->extractGroups($groupAttribute, $flattenedAttrs, $rawAttrs);
        if ($userGroups === [] && $roleMappings !== []) {
            $this->oauthUtility->customlog(
                'AdminProfileSync: no groups in OIDC response for role sync'
            );
            // If no groups found but default role is set, use it
            if ($defaultRole !== '') {
                $this->assignRoleByName($user, $defaultRole);
            }
            return;
        }

        // Find first matching role
        $targetRoleId = null;
        foreach ($roleMappings as $mapping) {
            $idpGroup = $mapping['group'] ?? '';
            $roleId   = $mapping['role'] ?? '';
            if ($idpGroup !== '' && $roleId !== '' && in_array($idpGroup, $userGroups, true)) {
                $targetRoleId = (int) $roleId;
                break;
            }
        }

        if ($targetRoleId === null) {
            // No mapping matched — use default role if configured
            if ($defaultRole !== '') {
                $this->assignRoleByName($user, $defaultRole);
            }
            return;
        }

        // Check if user already has this role
        $currentRoles = $user->getRoles();
        if (in_array((string) $targetRoleId, $currentRoles, true)
            || in_array($targetRoleId, $currentRoles, false)) {
            return; // Already assigned
        }

        $user->setRoleId($targetRoleId);
        $this->userResource->save($user);
        $this->oauthUtility->customlog(
            'AdminProfileSync: role updated to ID ' . $targetRoleId
            . ' for ' . $email
        );
    }

    // ──────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────
    /**
     * Decide whether an attribute type should be synced.
     *
     * Rule: if a normalized mapping row exists for $attributeType and its
     * `sync_on_sso` flag is 0 → skip.  If no row exists → sync (legacy behaviour).
     *
     * @param  mixed[] $attrMap        Normalized attribute map from MappingRepository
     * @param  string  $attributeType  e.g. 'firstname', 'lastname', 'email'
     */
    private function shouldSync(array $attrMap, string $attributeType): bool
    {
        if (isset($attrMap[$attributeType])) {
            return (bool) $attrMap[$attributeType]['sync_on_sso'];
        }
        // No normalized row → fall through to legacy sync
        return true;
    }

    /**
     * Load admin user by email. Returns null if not found.
     *
     * @param string $email Admin email address
     */
    private function loadAdminByEmail(string $email): ?\Magento\User\Model\User
    {
        $user = $this->userFactory->create();
        $this->userResource->load($user, $email, 'email');
        return $user->getId() ? $user : null;
    }

    /**
     * Extract a single value from flattened or raw attributes.
     *
     * @param string  $key  Attribute key to look up
     * @param mixed[] $flat Flattened OIDC attributes
     * @param mixed[] $raw  Raw (nested) OIDC attributes
     */
    private function extract(string $key, array $flat, array $raw): ?string
    {
        if ($key === '') {
            return null;
        }
        $value = $flat[$key] ?? $raw[$key] ?? null;
        if (is_array($value)) {
            $value = reset($value) ?: null;
        }
        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Extract groups array from OIDC claims.
     *
     * @param string  $key  Attribute key for groups claim
     * @param mixed[] $flat Flattened OIDC attributes
     * @param mixed[] $raw  Raw (nested) OIDC attributes
     * @return string[]
     */
    private function extractGroups(string $key, array $flat, array $raw): array
    {
        if ($key === '') {
            return [];
        }
        $rawGroups = $flat[$key] ?? $raw[$key] ?? null;
        return $this->oidcAuthenticationService->normalizeGroups($rawGroups);
    }

    /**
     * Assign a role by its name (fallback for default role).
     *
     * @param \Magento\User\Model\User $user     Admin user to assign role to
     * @param string                   $roleName Role name to look up and assign
     */
    private function assignRoleByName(\Magento\User\Model\User $user, string $roleName): void
    {
        $roles = $this->roleCollectionFactory->create()
            ->addFieldToFilter('role_name', $roleName)
            ->addFieldToFilter('role_type', 'G')
            ->setPageSize(1);

        $role = $roles->getFirstItem();
        if (!$role->getId()) {
            $this->oauthUtility->customlog(
                'AdminProfileSync: default role "' . $roleName . '" not found'
            );
            return;
        }

        $currentRoles = $user->getRoles();
        if (in_array((string) $role->getId(), $currentRoles, true)) {
            return;
        }

        $user->setRoleId((int) $role->getId());
        $this->userResource->save($user);
        $this->oauthUtility->customlog(
            'AdminProfileSync: default role "' . $roleName . '" assigned for '
            . $user->getEmail()
        );
    }
}
