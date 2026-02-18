<?php
/**
 * OIDC Credential Storage Adapter
 *
 * Implements Magento's credential storage interface to allow OIDC-authenticated
 * users to work with Magento's standard Auth::login() flow.
 *
 * This adapter bridges external OIDC authentication with Magento's internal
 * authentication system, ensuring all security checks and events fire properly.
 *
 * @package MiniOrange\OAuth\Model\Auth
 */
namespace MiniOrange\OAuth\Model\Auth;

use Magento\Backend\Model\Auth\Credential\StorageInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\User\Model\UserFactory;
use Magento\User\Model\ResourceModel\User as UserResourceModel;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcCredentialAdapter implements StorageInterface
{
    /**
     * OIDC verification token marker
     */
    public const OIDC_TOKEN_MARKER = 'OIDC_VERIFIED_USER';

    /**
     * @var UserFactory
     */
    protected $userFactory;

    /**
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @var OAuthUtility
     */
    protected $oauthUtility;

    /**
     * @var \Magento\User\Model\User
     */
    protected $user;

    /**
     * @var bool
     */
    protected $hasAvailableResources = false;

    /**
     * @var UserResourceModel
     */
    protected $userResource;

    /**
     * @var UserCollectionFactory
     */
    protected $userCollectionFactory;

    /**
     * @param UserFactory           $userFactory
     * @param ManagerInterface      $eventManager
     * @param OAuthUtility          $oauthUtility
     * @param UserResourceModel     $userResource
     * @param UserCollectionFactory $userCollectionFactory
     */
    public function __construct(
        UserFactory $userFactory,
        ManagerInterface $eventManager,
        OAuthUtility $oauthUtility,
        UserResourceModel $userResource,
        UserCollectionFactory $userCollectionFactory
    ) {
        $this->userFactory = $userFactory;
        $this->eventManager = $eventManager;
        $this->oauthUtility = $oauthUtility;
        $this->userResource = $userResource;
        $this->userCollectionFactory = $userCollectionFactory;
    }

    /**
     * Restore DI dependencies after session deserialization.
     *
     * __sleep() only persists 'user' and 'hasAvailableResources'.
     * After __wakeup(), all injected dependencies are null.
     * This method lazily restores them via ObjectManager when needed.
     *
     * @return void
     */
    protected function restoreDependencies()
    {
        if ($this->userFactory !== null) {
            return;
        }

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->userFactory = $objectManager->get(UserFactory::class);
        $this->eventManager = $objectManager->get(ManagerInterface::class);
        $this->oauthUtility = $objectManager->get(OAuthUtility::class);
        $this->userResource = $objectManager->get(UserResourceModel::class);
        $this->userCollectionFactory = $objectManager->get(UserCollectionFactory::class);
    }

    /**
     * Safe logging helper
     *
     * After deserialization, dependencies may be null. This method safely logs
     * only when the oauthUtility dependency is available.
     *
     * @param  string $message
     * @return void
     */
    protected function log($message)
    {
        if ($this->oauthUtility) {
            $this->oauthUtility->customlog($message);
        }
    }

    /**
     * Authenticate OIDC user
     *
     * Validates that the user was authenticated via OIDC provider and exists in Magento.
     * Does NOT verify password - authentication already happened at OIDC provider.
     *
     * @param  string $username User email from OIDC provider
     * @param  string $password OIDC token marker (should be OIDC_TOKEN_MARKER)
     * @return bool
     * @throws AuthenticationException
     */
    public function authenticate($username, $password)
    {
        $this->restoreDependencies();
        $this->log("OidcCredentialAdapter: Starting authentication for: " . $username);

        if ($password !== self::OIDC_TOKEN_MARKER) {
            $this->log("ERROR: Invalid OIDC token marker");
            throw new AuthenticationException(__('Invalid authentication method'));
        }

        $this->eventManager->dispatch(
            'admin_user_authenticate_before', [
            'username' => $username,
            'user' => null,
            'oidc_auth' => true
            ]
        );

        $userCollection = $this->userCollectionFactory->create()
            ->addFieldToFilter('email', $username);

        if ($userCollection->getSize() === 0) {
            $this->log("ERROR: Admin user not found for email: " . $username);
            throw new AuthenticationException(
                __('Admin user not found for email: %1', $username)
            );
        }

        $user = $userCollection->getFirstItem();
        $this->user = $user;

        $this->log("User found - ID: " . $user->getId() . ", Username: " . $user->getUsername());

        // Check if user is active
        if (!$user->getIsActive()) {
            $this->log("ERROR: Admin user is inactive (ID: " . $user->getId() . ")");
            throw new AuthenticationException(
                __('Admin account is inactive. Please contact your administrator.')
            );
        }

        // Check if user has assigned role
        $hasRole = $user->hasAssigned2Role($user->getId());
        if (!$hasRole) {
            $this->log("ERROR: Admin user has no assigned role (ID: " . $user->getId() . ")");
            throw new AuthenticationException(
                __('Admin user has no assigned role. Please contact your administrator.')
            );
        }

        $this->eventManager->dispatch(
            'admin_user_authenticate_after', [
            'username' => $username,
            'password' => '',
            'user' => $this->user,
            'result' => true,
            'oidc_auth' => true
            ]
        );

        $this->log("Authentication successful for: " . $username);
        return true;
    }

    /**
     * Login action
     *
     * Performs the login after successful authentication.
     * Records login in database and reloads user data.
     *
     * @param  string $username
     * @param  string $password
     * @return $this
     * @throws AuthenticationException
     */
    public function login($username, $password)
    {
        $this->restoreDependencies();

        if ($this->authenticate($username, $password)) {
            $this->userResource->recordLogin($this->user);
            $this->log("Login recorded for user ID: " . $this->user->getId());
            $this->reload();
        }

        return $this;
    }

    /**
     * Reload user data from database.
     *
     * Called after login AND after session deserialization (via Authentication plugin).
     * Dependencies may be null after deserialization, so we restore them first.
     *
     * @return $this
     */
    public function reload()
    {
        $this->restoreDependencies();

        if ($this->user && $this->user->getId()) {
            $userId = $this->user->getId();
            $this->user = $this->userFactory->create();
            $this->userResource->load($this->user, $userId);
        }

        return $this;
    }

    /**
     * Check if user has available resources
     *
     * @return bool
     */
    public function hasAvailableResources()
    {
        return $this->hasAvailableResources;
    }

    /**
     * Set if user has available resources
     *
     * @param  bool $hasResources
     * @return $this
     */
    public function setHasAvailableResources($hasResources)
    {
        $this->hasAvailableResources = (bool)$hasResources;
        return $this;
    }

    /**
     * Get the authenticated user
     *
     * This method is not part of StorageInterface but may be called by Auth class.
     *
     * @return \Magento\User\Model\User|null
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Get user ID
     *
     * This method is not part of StorageInterface but may be called by Auth class.
     *
     * @return int|null
     */
    public function getId()
    {
        return $this->user ? $this->user->getId() : null;
    }

    /**
     * Check if user is active
     *
     * This method is not part of StorageInterface but may be called by Auth class.
     *
     * @return bool
     */
    public function getIsActive()
    {
        return $this->user ? (bool)$this->user->getIsActive() : false;
    }

    /**
     * Control serialization to prevent closure serialization errors.
     *
     * DI-injected dependencies contain closures and cannot be serialized.
     * Only essential state (user + hasAvailableResources) is persisted.
     * Dependencies are restored lazily via restoreDependencies().
     *
     * @return array Properties to serialize
     */
    public function __sleep()
    {
        return ['user', 'hasAvailableResources'];
    }

    /**
     * Handle deserialization – dependencies remain null until restoreDependencies() is called.
     */
    public function __wakeup()
    { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedFunction
        // Intentionally empty: Dependencies will be restored lazily by restoreDependencies()
        // when any method requiring them is called. This prevents issues with closures
        // in DI-injected dependencies that cannot be serialized.
    }

    /**
     * Magic method to proxy unknown method calls to the User object
     *
     * @param  string $method
     * @param  array  $args
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function __call($method, $args)
    {
        // If we have a user object, proxy the method call to it
        // Don't check method_exists() because User model has magic methods (__call)
        // that handle getters/setters like getReloadAclFlag()
        if ($this->user) {
            return $this->user->{$method}(...$args);
        }

        // If no user, throw exception
        throw new \BadMethodCallException(
            sprintf(
                'Call to undefined method %s::%s()',
                get_class($this),
                $method
            )
        );
    }
}
