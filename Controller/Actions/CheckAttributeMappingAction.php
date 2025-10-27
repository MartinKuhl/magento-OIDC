<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Framework\App\Action\HttpPostActionInterface;

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

    private $emailAttribute;
    private $usernameAttribute;
    private $firstName;
    private $lastName;
    private $checkIfMatchBy;
    private $groupName;

    private $testAction;
    private $processUserAction;

    protected $userFactory;
    protected $backendUrl;

    /**
     * Constructor with dependency injection
     * 
     * @param \Magento\Framework\App\Action\Context $context
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     * @param \MiniOrange\OAuth\Controller\Actions\ShowTestResultsAction $testAction
     * @param \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction
     * @param \Magento\User\Model\UserFactory $userFactory
     * @param \Magento\Backend\Model\UrlInterface $backendUrl
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \MiniOrange\OAuth\Controller\Actions\ShowTestResultsAction $testAction,
        \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\UrlInterface $backendUrl
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
        
        $this->testAction = $testAction;
        $this->processUserAction = $processUserAction;
        $this->userFactory = $userFactory;
        $this->backendUrl = $backendUrl;
        
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
        
        $this->oauthUtility->customlog("=== CheckAttributeMappingAction: Processing authentication for: " . $userEmail);
        
        // Check if user is an admin
        $isAdminLogin = $this->isAdminUser($userEmail);
        $this->oauthUtility->customlog("User type detection - Admin: " . ($isAdminLogin ? 'YES' : 'NO'));
        
        if ($isAdminLogin) {
            // Redirect admin users to dedicated admin login endpoint
            $this->oauthUtility->customlog("Routing admin user to admin callback endpoint");
            
            $adminCallbackUrl = $this->backendUrl->getUrl('mooauth/actions/oidccallback', [
                'email' => $userEmail
            ]);
            
            $this->oauthUtility->customlog("Admin callback URL: " . $adminCallbackUrl);
            
            $this->getResponse()->setRedirect($adminCallbackUrl);
            return $this->getResponse();
        }
        
        // Regular customer login flow
        $this->oauthUtility->customlog("Routing customer user to normal login flow");
        return $this->moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail);
    }
    
    /**
     * Check if the email belongs to an admin user
     * 
     * Checks both username and email fields in the admin_user table.
     * 
     * @param string $email User email or username
     * @return bool True if admin user exists and is active
     */
    private function isAdminUser($email)
    {
        $this->oauthUtility->customlog("Checking if user is admin: " . $email);
        
        // Try username lookup first
        $user = $this->userFactory->create()->loadByUsername($email);
        if ($user && $user->getId()) {
            $this->oauthUtility->customlog("Admin user found by username - ID: " . $user->getId() . ", Active: " . ($user->getIsActive() ? 'YES' : 'NO'));
            return true;
        }
        
        // Try email lookup
        $userCollection = $this->userFactory->create()->getCollection()
            ->addFieldToFilter('email', $email);
        
        if ($userCollection->getSize() > 0) {
            $user = $userCollection->getFirstItem();
            $this->oauthUtility->customlog("Admin user found by email - ID: " . $user->getId() . ", Active: " . ($user->getIsActive() ? 'YES' : 'NO'));
            return true;
        }
        
        $this->oauthUtility->customlog("User is not an admin");
        return false;
    }

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
            return $this->testAction->setAttrs($flattenedattrs)->setUserEmail($email)->execute();
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
     * Save OAuth response for debugging
     * 
     * @param array $attrs
     */
    protected function saveDebugData($attrs)
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $customerSession = $objectManager->get(\Magento\Customer\Model\Session::class);
            
            $debugData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'raw_attributes' => $this->userInfoResponse,
                'flattened_attributes' => $attrs,
                'email_found' => isset($attrs[$this->emailAttribute]) ? $attrs[$this->emailAttribute] : null,
                'username_found' => isset($attrs[$this->usernameAttribute]) ? $attrs[$this->usernameAttribute] : null
            ];
            
            $customerSession->setData('mo_oauth_debug_response', json_encode($debugData));
            
            $this->oauthUtility->customlog("Debug data saved to session");
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("Could not save debug data: " . $e->getMessage());
        }
    }

}
