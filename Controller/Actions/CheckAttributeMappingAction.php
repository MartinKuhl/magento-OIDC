<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * This class handles checking of the SAML attributes and NameID
 * coming in the response and mapping it to the attribute mapping
 * done in the plugin settings by the admin to update the user.
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

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \MiniOrange\OAuth\Controller\Actions\ShowTestResultsAction $testAction,
        \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction
    ) {
        //You can use dependency injection to get any class this observer may need.
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
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute function to execute the classes function.
     */
    public function execute()
    {$this->oauthUtility->customlog("CheckAttributeMappingAction: execute") ;
        $attrs = $this->userInfoResponse;
        $flattenedAttrs =  $this->flattenedUserInfoResponse;
        $userEmail = $this->userEmail;

        $this->moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail);
    }
    

    /**
     * This function checks the SAML Attribute Mapping done
     * in the plugin and matches it to update the user's
     * attributes.
     *
     * @param $attrs
     * @throws MissingAttributesException;
     */
    private function moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail)
    {  $this->oauthUtility->customlog("CheckAttributeMappingAction: moOAuthCheckMapping");
       
        if (empty($attrs)) {
            throw new MissingAttributesException;
        }

        $this->checkIfMatchBy = OAuthConstants::DEFAULT_MAP_BY;
        $this->processUserName($flattenedAttrs);
        $this->processEmail($flattenedAttrs);
        $this->processGroupName($flattenedAttrs);

        $this->processResult($attrs, $flattenedAttrs, $userEmail);
    }


    /**
     * Process the result to either show a Test result
     * screen or log/create user in Magento.
     *
     * @param $attrs
     */
    private function processResult($attrs, $flattenedattrs, $email)
    {$this->oauthUtility->customlog("CheckAttributeMappingAction: processResult") ;
     
        $isTest =  $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

        if ($isTest == true) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, false);
            $this->oauthUtility->flushCache();
            $this->testAction->setAttrs($flattenedattrs)->setUserEmail($email)->execute();
        } else {
            $this->processUserAction->setFlattenedAttrs($flattenedattrs)->setAttrs($attrs)->setUserEmail($email)->execute();
        }
    }

    /**
     * Check if the attribute list has a FirstName. If
     * no firstName is found then NameID is considered as
     * the firstName. This is done because Magento needs
     * a firstName for creating a new user.
     *
     * @param $attrs
     */
    private function processFirstName(&$attrs)
    { $this->oauthUtility->customlog("CheckAttributeMappingAction: processFirstName")  ;
        if (!isset( $attrs[$this->firstName])) {
            $parts  = explode("@", $this->userEmail);
            $name = $parts[0];
           $this->oauthUtility->customlog("CheckAttributeMappingAction: processFirstName: ".$name) ;
 
            $attrs[$this->firstName] = $name;
        }
    }

    private function processLastName(&$attrs)
    { $this->oauthUtility->customlog("CheckAttributeMappingAction: processLastName") ;
   
        if (!isset($attrs[$this->lastName])) {
            $parts  = explode("@", $this->userEmail);
            $name = $parts[1];
            $this->oauthUtility->customlog("CheckAttributeMappingAction: processLastName: ".$name)  ;

            $attrs[$this->lastName] = $name;
        }
    }


    /**
     * Check if the attribute list has a UserName. If
     * no UserName is found then NameID is considered as
     * the UserName. This is done because Magento needs
     * a UserName for creating a new user.
     *
     * @param $attrs
     */
    private function processUserName(&$attrs)
    { $this->oauthUtility->customlog("CheckAttributeMappingAction: procesUserName") ;

        if (!isset($attrs[$this->usernameAttribute])) {
           
            $attrs[$this->usernameAttribute] = $this->userEmail;
            $this->oauthUtility->customlog("CheckAttributeMappingAction: procesUserName; ".$attrs[$this->usernameAttribute]) ;
        }
    }


    /**
     * Check if the attribute list has a Email. If
     * no Email is found then NameID is considered as
     * the Email. This is done because Magento needs
     * a Email for creating a new user.
     *
     * @param $attrs
     */
    private function processEmail(&$attrs)
    { $this->oauthUtility->customlog("CheckAttributeMappingAction: processEmail") ;
     
        if (!isset($attrs[$this->emailAttribute])) {
            $attrs[$this->emailAttribute] = $this->userEmail;
            $this->oauthUtility->customlog("CheckAttributeMappingAction: processEmail : ".$attrs[$this->emailAttribute]) ;
        }
    }


    /**
     * Check if the attribute list has a Group/Role. If
     * no Group/Role is found then NameID is considered as
     * the Group/Role. This is done because Magento needs
     * a Group/Role for creating a new user.
     *
     * @param $attrs
     */
    private function processGroupName(&$attrs)
    {$this->oauthUtility->customlog("CheckAttributeMappingAction: processGroupName")  ;

        if (!isset($attrs[$this->groupName])) {
            $this->groupName = [];
        }
    }


    /** Setter for the OAuth Response Parameter */
    public function setUserInfoResponse($userInfoResponse)
    {
        $this->userInfoResponse = $userInfoResponse;
        return $this;
    }

    /** Setter for the OAuth Response Parameter */
    public function setFlattenedUserInfoResponse($flattenedUserInfoResponse)
    {
        $this->flattenedUserInfoResponse = $flattenedUserInfoResponse;
        return $this;
    }

    /** Setter for the user email Parameter */
    public function setUserEmail($userEmail)
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    /** Setter for the RelayState Parameter */
    public function setRelayState($relayState)
    {
        $this->relayState = $relayState;
        return $this;
    }
}
