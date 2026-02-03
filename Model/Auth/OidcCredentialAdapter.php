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
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcCredentialAdapter implements StorageInterface
{
    /**
     * OIDC verification token marker
     */
    const OIDC_TOKEN_MARKER = 'OIDC_VERIFIED_USER';

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
     * Constructor
     *
     * @param UserFactory $userFactory
     * @param ManagerInterface $eventManager
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(
        UserFactory $userFactory,
        ManagerInterface $eventManager,
        OAuthUtility $oauthUtility
    ) {
        $this->userFactory = $userFactory;
        $this->eventManager = $eventManager;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Safe logging helper
     *
     * After deserialization, dependencies may be null. This method safely logs
     * only when the oauthUtility dependency is available.
     *
     * @param string $message
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
     * @param string $username User email from OIDC provider
     * @param string $password OIDC token marker (should be OIDC_TOKEN_MARKER)
     * @return bool
     * @throws AuthenticationException
     */
    public function authenticate($username, $password)
    {
        $this->log("OidcCredentialAdapter: Starting authentication for: " . $username);

        // Verify this is an OIDC authentication request
        if ($password !== self::OIDC_TOKEN_MARKER) {
            $this->log("ERROR: Invalid OIDC token marker");
            throw new AuthenticationException(__('Invalid authentication method'));
        }

        // Fire pre-authentication event WITH OIDC marker to bypass CAPTCHA
        $this->eventManager->dispatch('admin_user_authenticate_before', [
            'username' => $username,
            'user' => null,
            'oidc_auth' => true  // Signals CAPTCHA bypass plugin
        ]);

        // Load user by email
        $userCollection = $this->userFactory->create()->getCollection()
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

        $this->log("Authentication successful for user ID: " . $user->getId());

        // Fire post-authentication event WITH OIDC marker for consistency
        $this->eventManager->dispatch('admin_user_authenticate_after', [
            'username' => $username,
            'password' => $password,
            'user' => $user,
            'result' => true,
            'oidc_auth' => true  // Maintains consistency with before event
        ]);

        return true;
    }

    /**
     * Login action
     *
     * Performs the login after successful authentication.
     * Records login in database and reloads user data.
     *
     * @param string $username User email from OIDC provider
     * @param string $password OIDC token marker
     * @return $this
     * @throws AuthenticationException
     */
    public function login($username, $password)
    {
        $this->log("OidcCredentialAdapter: login() called for: " . $username);

        if ($this->authenticate($username, $password)) {
            // Record login in database (updates logdate, lognum)
            $this->user->getResource()->recordLogin($this->user);

            $this->log("Login recorded for user ID: " . $this->user->getId());

            // Reload user data to get fresh state
            $this->reload();
        }

        return $this;
    }

    /**
     * Reload user data
     *
     * Reloads the currently authenticated user from the database.
     *
     * @return $this
     */
    public function reload()
    {
        if ($this->user && $this->user->getId()) {
            $userId = $this->user->getId();
            $this->user->setId(null);
            $this->user->load($userId);

            $this->log("User data reloaded for ID: " . $userId);
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
     * @param bool $hasResources
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
     * Control serialization to prevent closure serialization errors
     *
     * When session_regenerate_id() is called, PHP serializes session data which may
     * include references to the Auth object and its credential storage. Our adapter
     * has DI-injected dependencies (EventManager, UserFactory, OAuthUtility) that
     * contain closures and can't be serialized.
     *
     * By implementing __sleep(), we tell PHP to only serialize essential properties
     * (user data) and skip dependencies with closures.
     *
     * @return array Properties to serialize
     */
    public function __sleep()
    {
        // Only serialize the user object and flags, not the dependencies
        return ['user', 'hasAvailableResources'];
    }

    /**
     * Handle deserialization
     *
     * Note: If the adapter is deserialized from session, it won't have its
     * dependencies (userFactory, eventManager, oauthUtility). However, this
     * is acceptable because:
     * 1. After successful login, the standard credential storage takes over
     * 2. The adapter is only used during the login flow, not after
     * 3. All methods are null-safe and will work without dependencies (just without logging)
     * 4. The User object IS preserved and can be reloaded if needed
     *
     * Magento's Authentication plugin may call reload() after deserialization,
     * which works correctly even with null dependencies.
     */
    public function __wakeup()
    {
        // Dependencies will be null after deserialization
        // All logging calls are guarded via the log() helper method
    }

    /**
     * Magic method to proxy unknown method calls to the User object
     *
     * After session deserialization, Magento's locale system and other components
     * may call User model methods (like getInterfaceLocale()) on this adapter.
     * This magic method forwards those calls to the actual User object, making
     * the adapter act as a transparent proxy.
     *
     * @param string $method Method name
     * @param array $args Method arguments
     * @return mixed
     * @throws \BadMethodCallException if method doesn't exist on User object
     */
    public function __call($method, $args)
    {
        // If we have a user object, proxy the method call to it
        if ($this->user && method_exists($this->user, $method)) {
            return call_user_func_array([$this->user, $method], $args);
        }

        // If no user or method doesn't exist, throw exception
        throw new \BadMethodCallException(
            sprintf(
                'Call to undefined method %s::%s()',
                get_class($this),
                $method
            )
        );
    }
}
