<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\TestResults;
use MiniOrange\OAuth\Model\Service\AdminUserCreator;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Controller\Result\Raw as RawResult;
use Magento\Framework\Controller\ResultFactory;

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
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class CheckAttributeMappingAction extends BaseAction
{
    /**
     * @var array|null Raw userinfo response from provider
     */
    private $userInfoResponse;

    /**
     * @var array|null Flattened userinfo attributes
     */
    private $flattenedUserInfoResponse;

    /**
     * @var string|null User email extracted from attributes
     */
    private $userEmail;

    /**
     * @var string|null Login type (admin|customer)
     */
    private $loginType;

    /**
     * @var string Email attribute mapping key
     */
    private $emailAttribute;

    /**
     * @var string Username attribute mapping key
     */
    private $usernameAttribute;

    /**
     * @var string First name attribute mapping key
     */
    private $firstName;

    /**
     * @var string Last name attribute mapping key
     */
    private $lastName;

    /**
     * @var string Group attribute mapping key
     */
    private $groupName;

    /**
     * @var array|null Decoded access_control_rules from the provider row (FEAT-04)
     */
    private ?array $accessControlRules = null;

    /** @var \MiniOrange\OAuth\Helper\TestResults */
    private readonly \MiniOrange\OAuth\Helper\TestResults $testResults;

    /** @var \MiniOrange\OAuth\Controller\Actions\ShowTestResults */
    private readonly \MiniOrange\OAuth\Controller\Actions\ShowTestResults $testAction;

    /** @var \MiniOrange\OAuth\Controller\Actions\ProcessUserAction */
    private readonly \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction;

    /** @var \Magento\User\Model\UserFactory */
    protected \Magento\User\Model\UserFactory $userFactory;

    /** @var \Magento\Backend\Model\UrlInterface */
    protected \Magento\Backend\Model\UrlInterface $backendUrl;

    /** @var \Magento\Authorization\Model\ResourceModel\Role\Collection */
    protected \Magento\Authorization\Model\ResourceModel\Role\Collection $roleCollection;

    /** @var \Magento\Framework\Math\Random */
    protected \Magento\Framework\Math\Random $randomUtility;

    /** @var \MiniOrange\OAuth\Model\Service\AdminUserCreator */
    protected \MiniOrange\OAuth\Model\Service\AdminUserCreator $adminUserCreator;

    /** @var \Magento\Customer\Model\Session */
    protected \Magento\Customer\Model\Session $customerSession;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    protected \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory;

    /** @var \MiniOrange\OAuth\Helper\OAuthSecurityHelper */
    private readonly \MiniOrange\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    /**
     * Constructor with dependency injection
     *
     * @param \Magento\Framework\App\Action\Context                               $context
     * @param \MiniOrange\OAuth\Helper\OAuthUtility                               $oauthUtility
     * @param \MiniOrange\OAuth\Helper\TestResults                                $testResults
     * @param \MiniOrange\OAuth\Controller\Actions\ProcessUserAction              $processUserAction
     * @param \Magento\User\Model\UserFactory                                     $userFactory
     * @param \Magento\Backend\Model\UrlInterface                                 $backendUrl
     * @param \Magento\Authorization\Model\ResourceModel\Role\Collection          $roleCollection
     * @param \Magento\Framework\Math\Random                                      $randomUtility
     * @param AdminUserCreator                                                    $adminUserCreator
     * @param \Magento\Customer\Model\Session                                     $customerSession
     * @param \MiniOrange\OAuth\Controller\Actions\ShowTestResults                $testAction
     * @param OAuthSecurityHelper                                                 $securityHelper
     * @param CookieManagerInterface                                              $cookieManager
     * @param CookieMetadataFactory                                               $cookieMetadataFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \MiniOrange\OAuth\Helper\TestResults $testResults,
        \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\UrlInterface $backendUrl,
        \Magento\Authorization\Model\ResourceModel\Role\Collection $roleCollection,
        \Magento\Framework\Math\Random $randomUtility,
        AdminUserCreator $adminUserCreator,
        \Magento\Customer\Model\Session $customerSession,
        \MiniOrange\OAuth\Controller\Actions\ShowTestResults $testAction,
        OAuthSecurityHelper $securityHelper,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory
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

        $this->groupName = $oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP);
        $this->groupName = $oauthUtility->isBlank($this->groupName) ? 'groups' : $this->groupName;

        $this->testResults = $testResults;
        $this->testAction = $testAction;
        $this->processUserAction = $processUserAction;
        $this->userFactory = $userFactory;
        $this->backendUrl = $backendUrl;
        $this->roleCollection = $roleCollection;
        $this->randomUtility = $randomUtility;
        $this->adminUserCreator = $adminUserCreator;
        $this->customerSession = $customerSession;
        $this->securityHelper = $securityHelper;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute attribute mapping and route users accordingly
     *
     * Admin users are redirected to a separate callback endpoint that handles
     * admin authentication. Regular users proceed with the normal customer login flow.
     */
    #[\Override]
    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $attrs = $this->userInfoResponse;
        $flattenedAttrs = $this->flattenedUserInfoResponse;
        $userEmail = $this->userEmail;

        $isTest = $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

        // Test configuration: Do not redirect to backend!
        if ($isTest === true) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, false);
            $this->oauthUtility->flushCache();
            $this->testAction->setAttrs($flattenedAttrs);
            $this->testAction->setUserEmail($userEmail);
            return $this->testAction->execute();
        }

        // Only execute admin logic and redirect when NOT in test mode:
        $this->oauthUtility->customlog(
            "=== CheckAttributeMappingAction: Processing authentication for: " . $userEmail
        );

        // Use explicit loginType for routing decision instead of just checking admin_user table
        $isAdminLoginIntent = ($this->loginType === OAuthConstants::LOGIN_TYPE_ADMIN);
        $logMsg = "Login type: " . ($this->loginType ?? 'not set');
        $logMsg .= ", Admin intent: " . ($isAdminLoginIntent ? 'YES' : 'NO');
        $this->oauthUtility->customlog($logMsg);

        // FEAT-04: Claims-based access control — evaluate per-provider rules before routing
        if ($this->accessControlRules !== null && is_array($flattenedAttrs)) {
            $denialMessage = $this->evaluateAccessControlRules($flattenedAttrs);
            if ($denialMessage !== null) {
                $this->oauthUtility->customlog(
                    "CheckAttributeMappingAction: Access denied for {$userEmail}: {$denialMessage}"
                );
                $this->messageManager->addErrorMessage(__($denialMessage));
                if ($isAdminLoginIntent) {
                    $adminLoginUrl = $this->backendUrl->getUrl('admin');
                    return $this->resultRedirectFactory->create()->setUrl($adminLoginUrl);
                }
                return $this->resultRedirectFactory->create()->setPath('customer/account/login');
            }
        }

        if ($isAdminLoginIntent) {
            // User initiated login from admin page - verify they have admin account
            $hasAdminAccount = $this->adminUserCreator->isAdminUser($userEmail);
            $hasAccountMsg = "Admin login intent detected. Has admin account: ";
            $hasAccountMsg .= ($hasAdminAccount ? 'YES' : 'NO');
            $this->oauthUtility->customlog($hasAccountMsg);

            if ($hasAdminAccount) {
                // Redirect admin users to dedicated admin login endpoint
                $this->oauthUtility->customlog("Routing admin user to admin callback endpoint");

                $nonce = $this->securityHelper->createAdminLoginNonce($userEmail);
                $this->cookieManager->setPublicCookie(
                    'oidc_admin_nonce',
                    $nonce,
                    $this->cookieMetadataFactory->createPublicCookieMetadata()
                        ->setDuration(120)
                        ->setPath('/' . $this->backendUrl->getAreaFrontName())
                        ->setHttpOnly(true)
                        ->setSecure(true)
                        ->setSameSite('Lax')
                );
                $adminCallbackUrl = $this->backendUrl->getUrl('mooauth/actions/oidccallback');

                $this->oauthUtility->customlog("Admin callback URL: " . $adminCallbackUrl);

                return $this->resultRedirectFactory->create()->setUrl($adminCallbackUrl);
            } else {
                // User tried to login as admin but has no admin account
                // Check if auto-create admin is enabled
                $autoCreateAdmin = $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_ADMIN);
                $autoCreateMsg = "Auto-create admin setting: ";
                $autoCreateMsg .= ($autoCreateAdmin ? 'ENABLED' : 'DISABLED');
                $this->oauthUtility->customlog($autoCreateMsg);

                if ($autoCreateAdmin) {
                    $this->oauthUtility->customlog("=== Auto-creating admin user for: " . $userEmail . " ===");

                    // Extract attributes using configured mappings
                    $adminFirstName = $flattenedAttrs[$this->firstName] ?? null;
                    $adminLastName = $flattenedAttrs[$this->lastName] ?? null;
                    $adminUserName = $flattenedAttrs[$this->usernameAttribute] ?? $userEmail;

                    $mappedLog = sprintf(
                        'Mapped attributes - userName: %s, firstName: %s, lastName: %s',
                        $adminUserName,
                        $adminFirstName,
                        $adminLastName
                    );
                    $this->oauthUtility->customlog($mappedLog);

                    // Get groups from OIDC response
                    $groupAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP);
                    $userGroups = [];
                    if (!empty($groupAttribute)) {
                        $userGroups = $flattenedAttrs[$groupAttribute] ?? $attrs[$groupAttribute] ?? [];
                        if (is_string($userGroups)) {
                            $userGroups = [$userGroups];
                        }
                    }
                    $groupsJson = json_encode($userGroups);
                    $this->oauthUtility->customlog("User groups from OIDC: " . $groupsJson);

                    // Create the admin user via Service
                    $adminUser = $this->adminUserCreator->createAdminUser(
                        $userEmail,
                        $adminUserName,
                        $adminFirstName,
                        $adminLastName,
                        $userGroups
                    );

                    if ($adminUser && $adminUser->getId()) {
                        $this->oauthUtility->customlog("Admin user created successfully. ID: " . $adminUser->getId());

                        // Redirect to admin callback for login
                        $nonce = $this->securityHelper->createAdminLoginNonce($userEmail);
                        $this->cookieManager->setPublicCookie(
                            'oidc_admin_nonce',
                            $nonce,
                            $this->cookieMetadataFactory->createPublicCookieMetadata()
                                ->setDuration(120)
                                ->setPath('/' . $this->backendUrl->getAreaFrontName())
                                ->setHttpOnly(true)
                                ->setSecure(true)
                                ->setSameSite('Lax')
                        );
                        $adminCallbackUrl = $this->backendUrl->getUrl('mooauth/actions/oidccallback');
                        $this->oauthUtility->customlog("Redirecting to admin callback: " . $adminCallbackUrl);

                        return $this->resultRedirectFactory->create()->setUrl($adminCallbackUrl);
                    } else {
                        $this->oauthUtility->customlog("ERROR: Failed to create admin user for: " . $userEmail);
                        $errorMessage = 'Failed to create admin account. Please contact your administrator.';
                        $adminLoginUrl = $this->backendUrl->getUrl('admin') . '?oidc_error=' . urlencode($errorMessage);
                        return $this->resultRedirectFactory->create()->setUrl($adminLoginUrl);
                    }
                } else {
                    // Auto-create disabled - show error
                    $errorMsg = "ERROR: Admin login attempted but no admin account exists for: ";
                    $this->oauthUtility->customlog($errorMsg . $userEmail);
                    $errorMessage = OAuthMessages::parse(
                        'ADMIN_ACCOUNT_NOT_FOUND',
                        ['email' => $userEmail]
                    );
                    $adminLoginUrl = $this->backendUrl->getUrl('admin') . '?oidc_error=' . urlencode($errorMessage);
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
            $this->messageManager->addErrorMessage(
                __('Authentication failed: Required user attributes not received.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }
    }

    /**
     * Process OAuth/OIDC attribute mapping for customer users
     *
     * Maps OAuth attributes to Magento customer fields based on
     * the configuration set in the admin panel.
     *
     * @param  array  $attrs          Raw OAuth response attributes
     * @param  array  $flattenedAttrs Flattened attribute array
     * @param  string $userEmail      User email from OAuth response
     * @throws MissingAttributesException
     */
    private function moOAuthCheckMapping(
        $attrs,
        $flattenedAttrs,
        string $userEmail
    ): \Magento\Framework\Controller\ResultInterface {
        $this->oauthUtility->customlog("Starting attribute mapping for customer user");

        // Save debug data
        $this->saveDebugData($attrs);

        if (empty($attrs)) {
            $this->oauthUtility->customlog("ERROR: Empty attributes received from OAuth provider");
            throw new MissingAttributesException;
        }

        // Process required attributes
        $this->processUserName($flattenedAttrs);
        $this->processEmail($flattenedAttrs);
        $this->processFirstName($flattenedAttrs);
        $this->processLastName($flattenedAttrs);
        $this->processGroupName($flattenedAttrs);

        $this->oauthUtility->customlog("Attribute mapping completed, proceeding to user processing");

        return $this->processResult($attrs, $flattenedAttrs, $userEmail);
    }

    /**
     * Process the result - either show test screen or login/create user
     *
     * @param  array  $attrs          Raw attributes
     * @param  array  $flattenedattrs Flattened attributes
     * @param  string $email          User email
     */
    private function processResult(
        array $attrs,
        array $flattenedattrs,
        string $email
    ): \Magento\Framework\Controller\ResultInterface {
        $isTest = $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

        if ($isTest == true) {
            // Test mode - show attribute mapping test results
            $this->oauthUtility->customlog("Test mode enabled - showing attribute test results");
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, false);
            $this->oauthUtility->flushCache();

            // Note: The data is passed to the helper
            $output = $this->testResults->output(
                null,
                false,
                [
                'mail' => $email,
                'userinfo' => $flattenedattrs
                ]
            );

            /**
 * @var RawResult $result
*/
            $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $result->setContents($output);
            return $result;

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
     *
     * Falls back to email prefix if not provided
     *
     * @param array $attrs Attribute array
     */
    private function processFirstName(&$attrs): void
    {
        if (!isset($attrs[$this->firstName])) {
            $parts = explode("@", (string) $this->userEmail);
            $attrs[$this->firstName] = $parts[0];
            $this->oauthUtility->customlog("First name not provided, using email prefix: " . $parts[0]);
        }
    }

    /**
     * Process last name attribute
     *
     * Falls back to email domain if not provided
     *
     * @param array $attrs Attribute array
     */
    private function processLastName(&$attrs): void
    {
        if (!isset($attrs[$this->lastName])) {
            $parts = explode("@", (string) $this->userEmail);
            $attrs[$this->lastName] = $parts[1] ?? '';
            $this->oauthUtility->customlog(
                "Last name not provided, using email domain: " . ($parts[1] ?? 'empty')
            );
        }
    }

    /**
     * Process username attribute
     *
     * Falls back to email if not provided
     *
     * @param array $attrs Attribute array
     */
    private function processUserName(&$attrs): void
    {
        if (!isset($attrs[$this->usernameAttribute])) {
            $attrs[$this->usernameAttribute] = $this->userEmail;
            $this->oauthUtility->customlog("Username not provided, using email: " . $this->userEmail);
        }
    }

    /**
     * Process email attribute
     *
     * Falls back to userEmail if not provided
     *
     * @param array $attrs Attribute array
     */
    private function processEmail(&$attrs): void
    {
        if (!isset($attrs[$this->emailAttribute])) {
            $attrs[$this->emailAttribute] = $this->userEmail;
            $this->oauthUtility->customlog(
                "Email attribute not mapped, using userEmail: " . $this->userEmail
            );
        }
    }

    /**
     * Process group/role name attribute
     *
     * Defaults to empty array if not provided
     *
     * @param array $attrs Attribute array
     */
    private function processGroupName(&$attrs): void
    {
        if (!isset($attrs[$this->groupName])) {
            $attrs[$this->groupName] = [];
            $this->oauthUtility->customlog("Group name not provided, using empty array");
        }
    }

    // Setter methods for dependency injection pattern

    /**
     * Set user info response
     *
     * @param  array $userInfoResponse
     * @return $this
     */
    public function setUserInfoResponse($userInfoResponse): static
    {
        $this->userInfoResponse = $userInfoResponse;
        return $this;
    }

    /**
     * Set flattened user info response
     *
     * @param  array $flattenedUserInfoResponse
     * @return $this
     */
    public function setFlattenedUserInfoResponse($flattenedUserInfoResponse): static
    {
        $this->flattenedUserInfoResponse = $flattenedUserInfoResponse;
        return $this;
    }

    /**
     * Set user email
     *
     * @param  string $userEmail
     * @return $this
     */
    public function setUserEmail($userEmail): static
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    /**
     * Set login type (admin or customer)
     *
     * @param  string $loginType
     * @return $this
     */
    public function setLoginType($loginType): static
    {
        $this->loginType = $loginType;
        return $this;
    }

    /**
     * Override attribute mappings with per-provider values from the client details row (MP-07).
     *
     * When a numeric provider_id is known, ReadAuthorizationResponse passes the provider's
     * DB row here. Any non-empty attribute column in the row takes precedence over the
     * global store-config defaults set in the constructor, enabling per-provider mapping.
     *
     * Fields read from the provider row:
     *   email_attribute, username_attribute, firstname_attribute,
     *   lastname_attribute, group_attribute
     *
     * @param  array $clientDetails Provider row data array
     * @return $this
     */
    public function setClientDetails(array $clientDetails): static
    {
        if (!empty($clientDetails['email_attribute'])) {
            $this->emailAttribute = (string) $clientDetails['email_attribute'];
        }
        if (!empty($clientDetails['username_attribute'])) {
            $this->usernameAttribute = (string) $clientDetails['username_attribute'];
        }
        if (!empty($clientDetails['firstname_attribute'])) {
            $this->firstName = (string) $clientDetails['firstname_attribute'];
        }
        if (!empty($clientDetails['lastname_attribute'])) {
            $this->lastName = (string) $clientDetails['lastname_attribute'];
        }
        if (!empty($clientDetails['group_attribute'])) {
            $this->groupName = (string) $clientDetails['group_attribute'];
        }

        // MP-08: persist provider ID in customer session so the logout observer
        // can load the correct end_session_endpoint for IdP-initiated logout.
        $providerId = (int) ($clientDetails['id'] ?? 0);
        if ($providerId > 0) {
            $this->customerSession->setData('oidc_provider_id', $providerId);
        }

        // FEAT-04: load access control rules from the provider row
        $rulesJson = (string) ($clientDetails['access_control_rules'] ?? '');
        if ($rulesJson !== '') {
            $decoded = json_decode($rulesJson, true);
            $this->accessControlRules = is_array($decoded) ? $decoded : null;
        }

        return $this;
    }

    /**
     * Evaluate per-provider claims-based access control rules (FEAT-04).
     *
     * Each rule is an associative array with:
     *   - claim        (string) : flattened OIDC attribute key to test
     *   - operator     (string) : eq | neq | contains | not_contains | exists | not_exists
     *   - value        (string) : expected value (ignored for exists/not_exists)
     *   - deny_message (string) : user-visible message shown when this rule fails
     *
     * Rules are AND-combined; the first failing rule short-circuits and returns
     * its deny_message. Returns null when all rules pass (access granted).
     *
     * Array-valued claims (e.g. groups) are joined with commas for string comparison.
     *
     * @param  array       $claims Flattened OIDC claims from the IdP response
     * @return string|null         Denial message if access is denied, null if granted
     */
    private function evaluateAccessControlRules(array $claims): ?string
    {
        foreach ($this->accessControlRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $claim    = (string) ($rule['claim']        ?? '');
            $operator = (string) ($rule['operator']     ?? 'eq');
            $expected = (string) ($rule['value']        ?? '');
            $message  = (string) ($rule['deny_message'] ?? '');

            if ($claim === '') {
                continue;
            }

            $actual    = $claims[$claim] ?? null;
            $strActual = is_array($actual)
                ? implode(',', $actual)
                : (string) ($actual ?? '');

            $passes = match ($operator) {
                'eq'           => $strActual === $expected,
                'neq'          => $strActual !== $expected,
                'contains'     => str_contains($strActual, $expected),
                'not_contains' => !str_contains($strActual, $expected),
                'exists'       => $actual !== null,
                'not_exists'   => $actual === null,
                default        => true, // unknown operator: treat as pass
            };

            if (!$passes) {
                return $message !== '' ? $message : (string) __('Access denied by provider policy.');
            }
        }

        return null; // all rules passed — access granted
    }

    /**
     * Save OAuth response for debugging
     *
     * @param mixed $attrs
     *
     * @return void
     */
    protected function saveDebugData($attrs)
    {
        if (!$this->oauthUtility->isLogEnable()) {
            return;
        }

        try {
            // Filter sensitive data
            $sensitiveKeys = [
                'access_token',
                'refresh_token',
                'id_token',
                'client_secret',
                'password',
                'token'
            ];
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
            }

            $debugData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'raw_attributes' => $filteredUserInfo,
                'flattened_attributes' => $filteredAttrs,
                'email_found' => isset($filteredAttrs[$this->emailAttribute])
                    ? $filteredAttrs[$this->emailAttribute]
                    : null,
                'username_found' => isset($filteredAttrs[$this->usernameAttribute])
                    ? $filteredAttrs[$this->usernameAttribute]
                    : null,
            ];

            $json = json_encode($debugData);
            if (strlen($json) <= 8192) {
                $this->customerSession->setData('mo_oauth_debug_response', $json);
            } else {
                $sizeMsg = 'Debug data too large for session (' . strlen($json) . " bytes), skipping";
                $this->oauthUtility->customlog($sizeMsg);
            }

            $this->oauthUtility->customlog("Debug data (filtered) saved to session");
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("Could not save debug data: " . $e->getMessage());
        }
    }
}
