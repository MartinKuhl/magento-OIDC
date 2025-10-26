<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Framework\App\Action\HttpPostActionInterface;

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

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \MiniOrange\OAuth\Controller\Actions\ShowTestResultsAction $testAction,
        \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\UrlInterface $backendUrl
    ) {
        // Initialisiere Attribute-Mappings
        $this->emailAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $this->emailAttribute = $oauthUtility->isBlank($this->emailAttribute) ? OAuthConstants::DEFAULT_MAP_EMAIL : $this->emailAttribute;
        
        $this->usernameAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        $this->usernameAttribute = $oauthUtility->isBlank($this->usernameAttribute) ? OAuthConstants::DEFAULT_MAP_USERN : $this->usernameAttribute;
        
        $this->firstName = $oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME);
        $this->firstName = $oauthUtility->isBlank($this->firstName) ? OAuthConstants::DEFAULT_MAP_FN : $this->firstName;
        
        $this->lastName = $oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        $this->lastName = $oauthUtility->isBlank($this->lastName) ? OAuthConstants::DEFAULT_MAP_LN : $this->lastName;
        
        $this->checkIfMatchBy = $oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);
        
        $this->testAction = $testAction;
        $this->processUserAction = $processUserAction;
        $this->userFactory = $userFactory;
        $this->backendUrl = $backendUrl;
        
        parent::__construct($context, $oauthUtility);
    }

    public function execute()
    {
        $attrs = $this->userInfoResponse;
        $flattenedAttrs = $this->flattenedUserInfoResponse;
        $userEmail = $this->userEmail;
        
        $this->oauthUtility->customlog("Checking if admin user: " . $userEmail);
        $isAdminLogin = $this->isAdminUser($userEmail);
        $this->oauthUtility->customlog("Is admin user: " . ($isAdminLogin ? 'YES' : 'NO'));
        
        if ($isAdminLogin) {
            $this->oauthUtility->customlog("Admin user detected, redirecting to admin callback");
            
            // Redirect zum Admin-Login-Endpoint
            $adminCallbackUrl = $this->backendUrl->getUrl('mooauth/actions/oidccallback', [
                'email' => $userEmail,
                '_nosid' => true
            ]);
            
            $this->oauthUtility->customlog("Redirecting to admin callback: " . $adminCallbackUrl);
            
            $this->getResponse()->setRedirect($adminCallbackUrl);
            return $this->getResponse();
        }
        
        // Kein Admin-User, normaler Flow
        $this->oauthUtility->customlog("Not admin user, proceeding with normal flow");
        return $this->moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail);
    }
    
    private function isAdminUser($email)
    {
        $this->oauthUtility->customlog("isAdminUser: Checking email: " . $email);
        
        // Versuche Username
        $user = $this->userFactory->create()->loadByUsername($email);
        if ($user && $user->getId()) {
            $this->oauthUtility->customlog("Found admin by username, ID: " . $user->getId());
            return true;
        }
        
        // Versuche E-Mail
        $userCollection = $this->userFactory->create()->getCollection()
            ->addFieldToFilter('email', $email);
        
        $found = ($userCollection->getSize() > 0);
        $this->oauthUtility->customlog("Found admin by email: " . ($found ? 'YES' : 'NO'));
        
        return $found;
    }

    private function moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail)
    {
        $this->oauthUtility->customlog("moOAuthCheckMapping: START");
       
        if (empty($attrs)) {
            throw new MissingAttributesException;
        }

        $this->checkIfMatchBy = OAuthConstants::DEFAULT_MAP_BY;
        $this->processUserName($flattenedAttrs);
        $this->processEmail($flattenedAttrs);
        $this->processGroupName($flattenedAttrs);

        return $this->processResult($attrs, $flattenedAttrs, $userEmail);
    }

    private function processResult($attrs, $flattenedattrs, $email)
    {
        $this->oauthUtility->customlog("processResult: START");
     
        $isTest = $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

        if ($isTest == true) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, false);
            $this->oauthUtility->flushCache();
            return $this->testAction->setAttrs($flattenedattrs)->setUserEmail($email)->execute();
        } else {
            return $this->processUserAction->setFlattenedAttrs($flattenedattrs)->setAttrs($attrs)->setUserEmail($email)->execute();
        }
    }

    private function processFirstName(&$attrs)
    {
        if (!isset($attrs[$this->firstName])) {
            $parts = explode("@", $this->userEmail);
            $attrs[$this->firstName] = $parts[0];
        }
    }

    private function processLastName(&$attrs)
    {
        if (!isset($attrs[$this->lastName])) {
            $parts = explode("@", $this->userEmail);
            $attrs[$this->lastName] = isset($parts[1]) ? $parts[1] : '';
        }
    }

    private function processUserName(&$attrs)
    {
        if (!isset($attrs[$this->usernameAttribute])) {
            $attrs[$this->usernameAttribute] = $this->userEmail;
        }
    }

    private function processEmail(&$attrs)
    {
        if (!isset($attrs[$this->emailAttribute])) {
            $attrs[$this->emailAttribute] = $this->userEmail;
        }
    }

    private function processGroupName(&$attrs)
    {
        if (!isset($attrs[$this->groupName])) {
            $this->groupName = [];
        }
    }

    public function setUserInfoResponse($userInfoResponse)
    {
        $this->userInfoResponse = $userInfoResponse;
        return $this;
    }

    public function setFlattenedUserInfoResponse($flattenedUserInfoResponse)
    {
        $this->flattenedUserInfoResponse = $flattenedUserInfoResponse;
        return $this;
    }

    public function setUserEmail($userEmail)
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    public function setRelayState($relayState)
    {
        $this->relayState = $relayState;
        return $this;
    }
}
