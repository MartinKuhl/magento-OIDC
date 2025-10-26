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
        
        $this->oauthUtility->customlog("Checking if admin user: " . $userEmail);
        $isAdminLogin = $this->isAdminUser($userEmail);
        $this->oauthUtility->customlog("Is admin user: " . ($isAdminLogin ? 'YES' : 'NO'));
        
        if ($isAdminLogin) {
            // Redirect admin users to dedicated admin login endpoint
            $this->oauthUtility->customlog("Admin user detected, redirecting to admin callback");
            
            $adminCallbackUrl = $this->backendUrl->getUrl('mooauth/actions/oidccallback', [
                'email' => $userEmail
            ]);
            
            $this->oauthUtility->customlog("Redirecting to: " . $adminCallbackUrl);
            
            $this->getResponse()->setRedirect($adminCallbackUrl);
            return $this->getResponse();
        }
        
        // Regular customer login flow
        $this->oauthUtility->customlog("Customer user, proceeding with normal flow");
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
        $this->oauthUtility->customlog("Checking for admin user: " . $email);
        
        // Try username lookup first
        $user = $this->userFactory->create()->loadByUsername($email);
        if ($user && $user->getId()) {
            $this->oauthUtility->customlog("Found admin by username, ID: " . $user->getId());
            return true;
        }
        
        // Try email lookup
        $userCollection = $this->userFactory->create()->getCollection()
            ->addFieldToFilter('email', $email);
        
        $found = ($userCollection->getSize() > 0);
        $this->oauthUtility->customlog("Found admin by email: " . ($found ? 'YES' : 'NO'));
        
        return $found;
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
        $this->oauthUtility->customlog("Processing attribute mapping");
       
        if (empty($attrs)) {
            throw new MissingAttributesException;
        }

        $this->checkIfMatchBy = OAuthConstants::DEFAULT_MAP_BY;
        $this->processUserName($flattenedAttrs);
        $this->processEmail($flattenedAttrs);
        $this->processGroupName($flattenedAttrs);

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
        $this->oauthUtility->customlog("Processing result");
     
        $isTest = $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

        if ($isTest == true) {
            // Test mode - show attribute mapping test results
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, false);
            $this->oauthUtility->flushCache();
            return $this->testAction->setAttrs($flattenedattrs)->setUserEmail($email)->execute();
        } else {
            // Production mode - process user login/registration
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
}
