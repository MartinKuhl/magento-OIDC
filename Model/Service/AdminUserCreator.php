<?php

namespace MiniOrange\OAuth\Model\Service;

use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
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

    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    private readonly \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var \Magento\Framework\Math\Random */
    private readonly \Magento\Framework\Math\Random $randomUtility;

    /** @var \Magento\User\Model\ResourceModel\User */
    private readonly \Magento\User\Model\ResourceModel\User $userResource;

    /** @var UserCollectionFactory */
    private readonly UserCollectionFactory $userCollectionFactory;

    /**
     * Initialize admin user creator.
     *
     * @param UserFactory                            $userFactory
     * @param OAuthUtility                           $oauthUtility
     * @param Random                                 $randomUtility
     * @param \Magento\User\Model\ResourceModel\User $userResource
     * @param UserCollectionFactory                  $userCollectionFactory
     */
    public function __construct(
        UserFactory $userFactory,
        OAuthUtility $oauthUtility,
        Random $randomUtility,
        \Magento\User\Model\ResourceModel\User $userResource,
        UserCollectionFactory $userCollectionFactory
    ) {
        $this->userFactory = $userFactory;
        $this->oauthUtility = $oauthUtility;
        $this->randomUtility = $randomUtility;
        $this->userResource = $userResource;
        $this->userCollectionFactory = $userCollectionFactory;
    }

    /**
     * Create an admin user based on OIDC attributes
     *
     * @param  string      $email
     * @param  mixed       $userName
     * @param  string|null $firstName
     * @param  string|null $lastName
     * @param  array       $userGroups
     * @return \Magento\User\Model\User|null
     */
    public function createAdminUser(string $email, $userName, $firstName, $lastName, array $userGroups)
    {
        $this->oauthUtility->customlog("AdminUserCreator: Starting creation for " . $email);

        // Apply name fallbacks
        list($firstName, $lastName) = $this->applyNameFallbacks($firstName, $lastName, $email);

        // Determine Role ID
        $roleId = $this->getAdminRoleFromGroups($userGroups);

        if (!$roleId) {
            $this->oauthUtility->customlog("AdminUserCreator: No suitable role found for user. Creation aborted.");
            return null;
        }

        return $this->saveAdminUser($userName, $email, $firstName, $lastName, $roleId);
    }

    /**
     * Save the admin user to database
     *
     * @param  mixed  $userName
     * @param  string $email
     * @param  mixed  $firstName
     * @param  mixed  $lastName
     * @param  int    $roleId
     * @return \Magento\User\Model\User|null
     */
    private function saveAdminUser($userName, string $email, $firstName, $lastName, int $roleId)
    {
        // Generate secure random password (32 chars)
        $randomPassword = $this->randomUtility->getRandomString(28)
            . $this->randomUtility->getRandomString(2, '!@#$%^&*')
            . $this->randomUtility->getRandomString(2, '0123456789');

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
     * Apply name fallbacks from email when firstName/lastName are empty
     *
     * @param  mixed  $firstName
     * @param  mixed  $lastName
     * @param  string $email
     * @return array [firstName, lastName]
     */
    private function applyNameFallbacks($firstName, $lastName, string $email): array
    {
        if (empty($firstName)) {
            $parts = explode("@", $email);
            $firstName = $parts[0];
            $this->oauthUtility->customlog("AdminUserCreator: firstName fallback from email prefix: " . $firstName);
        }
        if (empty($lastName)) {
            $parts = explode("@", $email);
            $lastName = isset($parts[1]) ? $parts[1] : $firstName;
            $this->oauthUtility->customlog("AdminUserCreator: lastName fallback from email domain: " . $lastName);
        }
        return [$firstName, $lastName];
    }

    /**
     * Get admin role ID from OIDC groups using configured mappings
     *
     * @param  array $userGroups Groups from OIDC response
     * @return int|null Admin role ID or null if denied
     */
    private function getAdminRoleFromGroups(array $userGroups): ?int
    {
        // Get role mappings from configuration
        $roleMappingsJson = $this->oauthUtility->getStoreConfig('adminRoleMapping');
        $roleMappings = [];
        if (!$this->oauthUtility->isBlank($roleMappingsJson)) {
            $decoded = json_decode((string) $roleMappingsJson, true);
            $roleMappings = is_array($decoded) ? $decoded : [];
        }

        // FIX: empty.variable – $roleMappings direkt mit count() prüfen statt empty()
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
     * Check if the email/username belongs to an existing admin user
     *
     * @param  string $email
     */
    public function isAdminUser(string $email): bool
    {
        $this->oauthUtility->customlog("AdminUserCreator: Checking if user is admin: " . $email);

        // Try username lookup first
        $user = $this->userFactory->create()->loadByUsername($email);
        if ($user->getId()) {
            $this->oauthUtility->customlog("AdminUserCreator: Admin user found by username - ID: " . $user->getId());
            return true;
        }

        // FIX: Collection via Factory statt über Model::getCollection()
        $userCollection = $this->userCollectionFactory->create()
            ->addFieldToFilter('email', $email);

        if ($userCollection->getSize() > 0) {
            $user = $userCollection->getFirstItem();
            $this->oauthUtility->customlog("AdminUserCreator: Admin user found by email - ID: " . $user->getId());
            return true;
        }

        return false;
    }
}
