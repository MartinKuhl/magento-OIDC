<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;
use MiniOrange\OAuth\Helper\TestResults;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MiniOrange\OAuth\Model\Service\AdminUserCreator;

/**
 * Check and process OAuth/OIDC attribute mapping
 * 
 * This controller handles attribute mapping after successful authentication.
 * Admin users are redirected to a separate login endpoint that runs in the
 * adminhtml area context. Customer users proceed with the normal login flow.
 * 
 * All logging respects the plugin's logging configuration and writes to
 * var/log/mo_oauth.log when enabled.
 * 
 * @package MiniOrange\OAuth\Controller\Actions
 */
class CheckAttributeMappingAction extends BaseAction implements HttpPostActionInterface
{
    const TEST_VALIDATE_RELAYSTATE = OAuthConstants::TEST_RELAYSTATE;

    private $userInfoResponse;
    private $flattenedUserInfoResponse;
    private $relayState;
    private $userEmail;
    private $loginType;

    private $emailAttribute;
    private $usernameAttribute;
    private $firstName;
    private $lastName;
    private $checkIfMatchBy;
    private $groupName;

    private $testResults;
    private $testAction;
    private $processUserAction;

    protected $userFactory;
    protected $backendUrl;
    protected $adminUserCreator;
    protected $customerSession;


    /**
     * Constructor with dependency injection
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     * @param \MiniOrange\OAuth\Helper\TestResults $testResults
     * @param \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction
     * @param \Magento\User\Model\UserFactory $userFactory
     * @param \Magento\Backend\Model\UrlInterface $backendUrl
     * @param \Magento\Authorization\Model\ResourceModel\Role\Collection $roleCollection
     * @param \Magento\Framework\Math\Random $randomUtility
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \MiniOrange\OAuth\Helper\TestResults $testResults,
        \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\UrlInterface $backendUrl,
        AdminUserCreator $adminUserCreator,
        \Magento\Customer\Model\Session $customerSession,
        \MiniOrange\OAuth\Controller\Actions\ShowTestResults $testAction
    ) {
        // Initialize attribute mappings from configuration
        $this->emailAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $this->emailAttribute = $oauthUtility->isBlank($this->emailAttribute)
            ? OAuthConstants::DEFAULT_MAP_EMAIL
            : $this->emailAttribute;

        $this->usernameAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        $this->usernameAttribute = $oauthUtility->isBlank($this->usernameAttribute)
            ? OAuthConstants::DEFAULT_MAP_USERN
            : $this->usernameAttribute;

        $this->firstName = $oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME);
        $this->firstName = $oauthUtility->isBlank($this->firstName)
            ? OAuthConstants::DEFAULT_MAP_FN
            : $this->firstName;

        $this->lastName = $oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        $this->lastName = $oauthUtility->isBlank($this->lastName)
            ? OAuthConstants::DEFAULT_MAP_LN
            : $this->lastName;

        $this->checkIfMatchBy = $oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);

        $this->testResults = $testResults;
        $this->testAction = $testAction;
        $this->processUserAction = $processUserAction;
        $this->userFactory = $userFactory;
        $this->backendUrl = $backendUrl;
        $this->adminUserCreator = $adminUserCreator;
        $this->customerSession = $customerSession;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute attribute mapping and route users accordingly
     * 
     * Admin users are redirected to a separate callback endpoint that handles
     * admin authentication. Regular users proceed with the normal customer login flow.
     * 
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $attrs = $this->userInfoResponse;
        $flattenedAttrs = $this->flattenedUserInfoResponse;
        $userEmail = $this->userEmail;

        $isTest = $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

        // Test-Konfiguration: Nicht ins Backend umleiten!
        if ($isTest === true) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, false);
            $this->oauthUtility->flushCache();
            return $this->testAction
                ->setAttrs($flattenedAttrs)
                ->setUserEmail($userEmail)
                ->execute();
        }

        // Nur wenn KEIN Test, Admin-Logik und Redirect ausführen:
        $this->oauthUtility->customlog("=== CheckAttributeMappingAction: Processing authentication for: " . $userEmail);

        // Use explicit loginType for routing decision instead of just checking admin_user table
        $isAdminLoginIntent = ($this->loginType === OAuthConstants::LOGIN_TYPE_ADMIN);
        $this->oauthUtility->customlog("Login type: " . ($this->loginType ?? 'not set') . ", Admin intent: " . ($isAdminLoginIntent ? 'YES' : 'NO'));

        if ($isAdminLoginIntent) {
            // User initiated login from admin page - verify they have admin account
            $hasAdminAccount = $this->adminUserCreator->isAdminUser($userEmail);
            $this->oauthUtility->customlog("Admin login intent detected. Has admin account: " . ($hasAdminAccount ? 'YES' : 'NO'));

            if ($hasAdminAccount) {
                // Redirect admin users to dedicated admin login endpoint
                $this->oauthUtility->customlog("Routing admin user to admin callback endpoint");

                $adminCallbackUrl = $this->backendUrl->getUrl('mooauth/actions/oidccallback', [
                    'email' => $userEmail
                ]);

                $this->oauthUtility->customlog("Admin callback URL: " . $adminCallbackUrl);

                return $this->resultRedirectFactory->create()->setUrl($adminCallbackUrl);
            } else {
                // User tried to login as admin but has no admin account
                // Check if auto-create admin is enabled
                $autoCreateAdmin = $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_ADMIN);
                $this->oauthUtility->customlog("Auto-create admin setting: " . ($autoCreateAdmin ? 'ENABLED' : 'DISABLED'));

                if ($autoCreateAdmin) {
                    $this->oauthUtility->customlog("=== Auto-creating admin user for: " . $userEmail . " ===");

                    // Extract attributes using configured mappings
                    $adminFirstName = $flattenedAttrs[$this->firstName] ?? null;
                    $adminLastName = $flattenedAttrs[$this->lastName] ?? null;
                    $adminUserName = $flattenedAttrs[$this->usernameAttribute] ?? $userEmail;

                    $this->oauthUtility->customlog("Mapped attributes - userName: $adminUserName, firstName: $adminFirstName, lastName: $adminLastName");

                    // Get groups from OIDC response
                    $groupAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP);
                    $userGroups = [];
                    if (!empty($groupAttribute)) {
                        $userGroups = $flattenedAttrs[$groupAttribute] ?? $attrs[$groupAttribute] ?? [];
                        if (is_string($userGroups)) {
                            $userGroups = [$userGroups];
                        }
                    }
                    $this->oauthUtility->customlog("User groups from OIDC: " . json_encode($userGroups));

                    // Create the admin user via Service
                    $adminUser = $this->adminUserCreator->createAdminUser($userEmail, $adminUserName, $adminFirstName, $adminLastName, $userGroups);

                    if ($adminUser && $adminUser->getId()) {
                        $this->oauthUtility->customlog("Admin user created successfully. ID: " . $adminUser->getId());

                        // Redirect to admin callback for login
                        $adminCallbackUrl = $this->backendUrl->getUrl('mooauth/actions/oidccallback', [
                            'email' => $userEmail
                        ]);
                        $this->oauthUtility->customlog("Redirecting to admin callback: " . $adminCallbackUrl);

                        return $this->resultRedirectFactory->create()->setUrl($adminCallbackUrl);
                    } else {
                        $this->oauthUtility->customlog("ERROR: Failed to create admin user for: " . $userEmail);
                        $errorMessage = 'Failed to create admin account. Please contact your administrator.';
                        $encodedError = base64_encode($errorMessage);
                        $adminLoginUrl = $this->backendUrl->getUrl('admin') . '?oidc_error=' . $encodedError;
                        return $this->resultRedirectFactory->create()->setUrl($adminLoginUrl);
                    }
                } else {
                    // Auto-create disabled - show error
                    $this->oauthUtility->customlog("ERROR: Admin login attempted but no admin account exists for: " . $userEmail);
                    $errorMessage = OAuthMessages::parse('ADMIN_ACCOUNT_NOT_FOUND', ['email' => $userEmail]);
                    $encodedError = base64_encode($errorMessage);
                    $adminLoginUrl = $this->backendUrl->getUrl('admin') . '?oidc_error=' . $encodedError;
                    return $this->resultRedirectFactory->create()->setUrl($adminLoginUrl);
                }
            }
        }

        // Customer login flow (either explicit customer intent or default)
        $this->oauthUtility->customlog("Routing to customer login flow for: " . $userEmail);

        try {
            return $this->moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail);
        } catch (MissingAttributesException $e) {
            $this->oauthUtility->customlog("ERROR: Missing attributes - " . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Authentication failed: Required user attributes not received.'));
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }
    }

    /**
     * Check if the email belongs to an admin user
     * 
     * Checks both username and email fields in the admin_user table.
     * 
     * @param string $email User email or username
     * @return bool True if admin user exists and is active
     */
    // isAdminUser logic moved to AdminUserCreator service

    /**
     * Apply name fallbacks from email when firstName/lastName are empty
     *
     * @param string|null $firstName
     * @param string|null $lastName
     * @param string $email
     * @return array [firstName, lastName]
     */
    // applyNameFallbacks logic moved to AdminUserCreator service

    /**
     * Get admin role ID from OIDC groups using configured mappings
     *
     * @param array $userGroups Groups from OIDC response
     * @return int Admin role ID
     */
    // getAdminRoleFromGroups logic moved to AdminUserCreator service

    /**
     * Create a new admin user with the given attributes
     *
     * @param string $userName
     * @param string $email
     * @param string $firstName
     * @param string $lastName
     * @param int $roleId
     * @return \Magento\User\Model\User|null
     */
    // createAdminUser logic moved to AdminUserCreator service

    /**
     * Process OAuth/OIDC attribute mapping for customer users
     * 
     * Maps OAuth attributes to Magento customer fields based on
     * the configuration set in the admin panel.
     * 
     * @param array $attrs Raw OAuth response attributes
     * @param array $flattenedAttrs Flattened attribute array
     * @param string $userEmail User email from OAuth response
     * @return \Magento\Framework\Controller\ResultInterface
     * @throws MissingAttributesException
     */
    private function moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail)
    {
        $this->oauthUtility->customlog("Starting attribute mapping for customer user");

        // Save debug data
        $this->saveDebugData($attrs);

        if (empty($attrs)) {
            $this->oauthUtility->customlog("ERROR: Empty attributes received from OAuth provider");
            throw new MissingAttributesException;
        }

        $this->checkIfMatchBy = OAuthConstants::DEFAULT_MAP_BY;

        // Process required attributes
        $this->processUserName($flattenedAttrs);
        $this->processEmail($flattenedAttrs);
        $this->processGroupName($flattenedAttrs);

        $this->oauthUtility->customlog("Attribute mapping completed, proceeding to user processing");

        return $this->processResult($attrs, $flattenedAttrs, $userEmail);
    }

    /**
     * Process the result - either show test screen or login/create user
     * 
     * @param array $attrs Raw attributes
     * @param array $flattenedattrs Flattened attributes
     * @param string $email User email
     * @return \Magento\Framework\Controller\ResultInterface
     */
    private function processResult($attrs, $flattenedattrs, $email)
    {
        $isTest = $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

        if ($isTest == true) {
            // Test mode - show attribute mapping test results
            $this->oauthUtility->customlog("Test mode enabled - showing attribute test results");
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, false);
            $this->oauthUtility->flushCache();

            // Hilfe: Die Daten werden an den Helper übergeben!
            $output = $this->testResults->output(null, false, [
                'mail' => $email,
                'userinfo' => $flattenedattrs
            ]);

            // Im Controller:
            return $this->getResponse()->setBody($output);
            // Im Observer ggf. direkt:
            // echo $output;
        } else {
            // Production mode - process user login/registration
            $this->oauthUtility->customlog("Production mode - processing user login/registration");
            return $this->processUserAction
                ->setFlattenedAttrs($flattenedattrs)
                ->setAttrs($attrs)
                ->setUserEmail($email)
                ->execute();
        }
    }

    /**
     * Process first name attribute
     * Falls back to email prefix if not provided
     * 
     * @param array $attrs Attribute array
     */
    private function processFirstName(&$attrs)
    {
        if (!isset($attrs[$this->firstName])) {
            $parts = explode("@", $this->userEmail);
            $attrs[$this->firstName] = $parts[0];
            $this->oauthUtility->customlog("First name not provided, using email prefix: " . $parts[0]);
        }
    }

    /**
     * Process last name attribute
     * Falls back to email domain if not provided
     * 
     * @param array $attrs Attribute array
     */
    private function processLastName(&$attrs)
    {
        if (!isset($attrs[$this->lastName])) {
            $parts = explode("@", $this->userEmail);
            $attrs[$this->lastName] = isset($parts[1]) ? $parts[1] : '';
            $this->oauthUtility->customlog("Last name not provided, using email domain: " . ($parts[1] ?? 'empty'));
        }
    }

    /**
     * Process username attribute
     * Falls back to email if not provided
     * 
     * @param array $attrs Attribute array
     */
    private function processUserName(&$attrs)
    {
        if (!isset($attrs[$this->usernameAttribute])) {
            $attrs[$this->usernameAttribute] = $this->userEmail;
            $this->oauthUtility->customlog("Username not provided, using email: " . $this->userEmail);
        }
    }

    /**
     * Process email attribute
     * Falls back to userEmail if not provided
     * 
     * @param array $attrs Attribute array
     */
    private function processEmail(&$attrs)
    {
        if (!isset($attrs[$this->emailAttribute])) {
            $attrs[$this->emailAttribute] = $this->userEmail;
            $this->oauthUtility->customlog("Email attribute not mapped, using userEmail: " . $this->userEmail);
        }
    }

    /**
     * Process group/role name attribute
     * Defaults to empty array if not provided
     * 
     * @param array $attrs Attribute array
     */
    private function processGroupName(&$attrs)
    {
        if (!isset($attrs[$this->groupName])) {
            $this->groupName = [];
            $this->oauthUtility->customlog("Group name not provided, using empty array");
        }
    }

    // Setter methods for dependency injection pattern

    /**
     * Set user info response
     * 
     * @param array $userInfoResponse
     * @return $this
     */
    public function setUserInfoResponse($userInfoResponse)
    {
        $this->userInfoResponse = $userInfoResponse;
        return $this;
    }

    /**
     * Set flattened user info response
     * 
     * @param array $flattenedUserInfoResponse
     * @return $this
     */
    public function setFlattenedUserInfoResponse($flattenedUserInfoResponse)
    {
        $this->flattenedUserInfoResponse = $flattenedUserInfoResponse;
        return $this;
    }

    /**
     * Set user email
     * 
     * @param string $userEmail
     * @return $this
     */
    public function setUserEmail($userEmail)
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    /**
     * Set relay state
     *
     * @param string $relayState
     * @return $this
     */
    public function setRelayState($relayState)
    {
        $this->relayState = $relayState;
        return $this;
    }

    /**
     * Set login type (admin or customer)
     *
     * @param string $loginType
     * @return $this
     */
    public function setLoginType($loginType)
    {
        $this->loginType = $loginType;
        return $this;
    }

    /**
     * Save OAuth response for debugging
     * 
     * @param array $attrs
     */
    protected function saveDebugData($attrs)
    {
        if (!$this->oauthUtility->isLogEnable()) {
            return;
        }

        try {
            // Filter sensitive data
            $sensitiveKeys = ['access_token', 'refresh_token', 'id_token', 'client_secret', 'password', 'token'];
            $filteredAttrs = $attrs;
            $filteredUserInfo = $this->userInfoResponse;

            if (is_array($filteredAttrs)) {
                foreach ($sensitiveKeys as $key) {
                    if (isset($filteredAttrs[$key])) {
                        $filteredAttrs[$key] = '********';
                    }
                }
            }

            if (is_array($filteredUserInfo)) {
                foreach ($sensitiveKeys as $key) {
                    if (isset($filteredUserInfo[$key])) {
                        $filteredUserInfo[$key] = '********';
                    }
                }
            } elseif (is_object($filteredUserInfo)) {
                foreach ($sensitiveKeys as $key) {
                    if (isset($filteredUserInfo->$key)) {
                        $filteredUserInfo->$key = '********';
                    }
                }
            }

            $debugData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'raw_attributes' => $filteredUserInfo,
                'flattened_attributes' => $filteredAttrs,
                'email_found' => isset($filteredAttrs[$this->emailAttribute]) ? $filteredAttrs[$this->emailAttribute] : null,
                'username_found' => isset($filteredAttrs[$this->usernameAttribute]) ? $filteredAttrs[$this->usernameAttribute] : null
            ];

            $this->customerSession->setData('mo_oauth_debug_response', json_encode($debugData));

            $this->oauthUtility->customlog("Debug data (filtered) saved to session");
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("Could not save debug data: " . $e->getMessage());
        }
    }

}
