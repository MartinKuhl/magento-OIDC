<?php
namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Model\Service\CustomerUserCreator;
use Magento\Customer\Model\Customer;
use Magento\Framework\App\ResponseFactory;
use Magento\Store\Model\StoreManagerInterface;
use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthMessages;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

class ProcessUserAction
{
    private $attrs;
    private $flattenedattrs;
    private $userEmail;
    private $defaultRole;
    private $emailAttribute;
    private $usernameAttribute;
    private $firstNameKey;
    private $lastNameKey;
    private $dobAttribute;
    private $genderAttribute;
    private $phoneAttribute;
    private $streetAttribute;
    private $zipAttribute;
    private $cityAttribute;
    private $countryAttribute;
    private $customerModel;
    private $customerLoginAction;
    private $responseFactory;
    private $storeManager;
    private $scopeConfig;
    private $oauthUtility;
    private $customerUserCreator;
    private $resultRedirectFactory;
    private $messageManager;


    public function __construct(
        OAuthUtility $oauthUtility,
        Customer $customerModel,
        StoreManagerInterface $storeManager,
        ResponseFactory $responseFactory,
        CustomerLoginAction $customerLoginAction,
        ScopeConfigInterface $scopeConfig,
        CustomerUserCreator $customerUserCreator,
        RedirectFactory $resultRedirectFactory,
        ManagerInterface $messageManager
    ) {
        $this->emailAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $this->emailAttribute = $oauthUtility->isBlank($this->emailAttribute) ? OAuthConstants::DEFAULT_MAP_EMAIL : $this->emailAttribute;
        $this->usernameAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        $this->usernameAttribute = $oauthUtility->isBlank($this->usernameAttribute) ? OAuthConstants::DEFAULT_MAP_USERN : $this->usernameAttribute;
        $this->firstNameKey = $oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME);
        $this->firstNameKey = $oauthUtility->isBlank($this->firstNameKey) ? OAuthConstants::DEFAULT_MAP_FN : $this->firstNameKey;
        $this->lastNameKey = $oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        $this->lastNameKey = $oauthUtility->isBlank($this->lastNameKey) ? OAuthConstants::DEFAULT_MAP_LN : $this->lastNameKey;
        $this->defaultRole = $oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_ROLE);

        // Initialize customer data mapping attributes
        $this->dobAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_DOB);
        $this->dobAttribute = $oauthUtility->isBlank($this->dobAttribute) ? OAuthConstants::DEFAULT_MAP_DOB : $this->dobAttribute;
        $this->genderAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_GENDER);
        $this->genderAttribute = $oauthUtility->isBlank($this->genderAttribute) ? OAuthConstants::DEFAULT_MAP_GENDER : $this->genderAttribute;
        $this->phoneAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_PHONE);
        $this->phoneAttribute = $oauthUtility->isBlank($this->phoneAttribute) ? OAuthConstants::DEFAULT_MAP_PHONE : $this->phoneAttribute;
        $this->streetAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_STREET);
        $this->streetAttribute = $oauthUtility->isBlank($this->streetAttribute) ? OAuthConstants::DEFAULT_MAP_STREET : $this->streetAttribute;
        $this->zipAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_ZIP);
        $this->zipAttribute = $oauthUtility->isBlank($this->zipAttribute) ? OAuthConstants::DEFAULT_MAP_ZIP : $this->zipAttribute;
        $this->cityAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_CITY);
        $this->cityAttribute = $oauthUtility->isBlank($this->cityAttribute) ? OAuthConstants::DEFAULT_MAP_CITY : $this->cityAttribute;
        $this->countryAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_COUNTRY);
        $this->countryAttribute = $oauthUtility->isBlank($this->countryAttribute) ? OAuthConstants::DEFAULT_MAP_COUNTRY : $this->countryAttribute;

        $this->customerModel = $customerModel;
        $this->storeManager = $storeManager;
        $this->responseFactory = $responseFactory;
        $this->customerLoginAction = $customerLoginAction;
        $this->scopeConfig = $scopeConfig;
        $this->oauthUtility = $oauthUtility;
        $this->customerUserCreator = $customerUserCreator;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->messageManager = $messageManager;
    }

    public function execute()
    {
        try {
            $this->oauthUtility->customlog("ProcessUserAction: execute");
            if (empty($this->attrs)) {
                $this->oauthUtility->customlog("No Attributes Received");
                throw new MissingAttributesException;
            }

            $firstName = $this->flattenedattrs[$this->firstNameKey] ?? null;
            $lastName = $this->flattenedattrs[$this->lastNameKey] ?? null;
            $userName = $this->flattenedattrs[$this->usernameAttribute] ?? null;

            if ($this->oauthUtility->isBlank($this->defaultRole)) {
                $this->defaultRole = OAuthConstants::DEFAULT_ROLE;
            }

            return $this->processUserAction($this->userEmail, $firstName, $lastName, $userName, $this->defaultRole);

        } catch (MissingAttributesException $e) {
            $this->oauthUtility->customlog("ERROR: Missing required attributes from OAuth provider");
            $this->messageManager->addErrorMessage(__('Authentication failed: Required user information not received from identity provider.'));
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CRITICAL ERROR in execute: " . $e->getMessage());
            throw $e;
        }
    }

    private function processUserAction($user_email, $firstName, $lastName, $userName, $defaultRole)
    {
        $admin = false;
        $user = $this->getCustomerFromAttributes($user_email);

        if (!$user) {
            $this->oauthUtility->customlog("User not found. Checking auto-create configuration");

            // Check if auto-create customer is enabled in configuration
            $autoCreateEnabled = $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER);

            if (!$autoCreateEnabled) {
                $this->oauthUtility->customlog("Auto Create Customer is disabled. Rejecting login.");
                // Use same error handling pattern as other OIDC errors (oidc_error query param)
                $encodedError = base64_encode(OAuthMessages::AUTO_CREATE_USER_DISABLED);
                $loginUrl = $this->oauthUtility->getCustomerLoginUrl();
                $loginUrl .= (strpos($loginUrl, '?') !== false ? '&' : '?') . 'oidc_error=' . $encodedError;
                return $this->resultRedirectFactory->create()->setUrl($loginUrl);
            }

            $user = $this->createNewUser($user_email, $firstName, $lastName, $userName, $user, $admin);
        }

        $store_url = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $store_url = rtrim($store_url, '/\\');

        if (isset($this->attrs['relayState']) && !str_contains($this->attrs['relayState'], $store_url) && $this->attrs['relayState'] != '/') {
            $this->attrs['relayState'] = $store_url;
            $this->oauthUtility->customlog("processUserAction: changing relayState with store url " . $store_url);
        }

        // Customer login flow (admin routing is now handled by CheckAttributeMappingAction)
        $this->oauthUtility->customlog("processUserAction: Processing as customer login");

        $relayState = '';
        if (is_array($this->attrs) && isset($this->attrs['relayState'])) {
            $relayState = $this->attrs['relayState'];
        } elseif (isset($this->attrs->relayState)) {
            $relayState = $this->attrs->relayState;
        }

        if ($this->oauthUtility->getSessionData('guest_checkout')) {
            $this->oauthUtility->setSessionData('guest_checkout', NULL);
            return $this->customerLoginAction->setUser($user)->setRelayState($this->oauthUtility->getBaseUrl() . 'checkout')->execute();
        } else if (!empty($relayState)) {
            return $this->customerLoginAction->setUser($user)->setRelayState($relayState)->execute();
        } else {
            return $this->customerLoginAction->setUser($user)->setRelayState('/')->execute();
        }
    }

    //additionally handle admin user creation if required
    private function createNewUser($user_email, $firstName, $lastName, $userName, $user, &$admin)
    {

        if (empty($firstName)) {
            $parts = explode("@", $user_email);
            $firstName = $parts[0];
        }

        if (empty($lastName)) {
            $parts = explode("@", $user_email);
            $lastName = isset($parts[1]) ? $parts[1] : $parts[0];
        }

        $userName = !$this->oauthUtility->isBlank($userName) ? $userName : $user_email;
        $firstName = !$this->oauthUtility->isBlank($firstName) ? $firstName : $userName;
        $lastName = !$this->oauthUtility->isBlank($lastName) ? $lastName : $userName;

        $user = $this->customerUserCreator->createCustomer($user_email, $userName, $firstName, $lastName, $this->flattenedattrs, $this->attrs);
        return $user;
    }

    // Customer creation and address handling moved to CustomerUserCreator service.


    private function getCustomerFromAttributes($user_email)
    {
        $this->customerModel->setWebsiteId($this->storeManager->getStore()->getWebsiteId());
        $customer = $this->customerModel->loadByEmail($user_email);
        return !is_null($customer->getId()) ? $customer : false;
    }

    public function setAttrs($attrs)
    {
        $this->attrs = $attrs;
        return $this;
    }

    public function setFlattenedAttrs($flattenedattrs)
    {
        $this->flattenedattrs = $flattenedattrs;
        return $this;
    }

    public function setUserEmail($userEmail)
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    // Admin creation methods removed as they are now handled by CheckAttributeMappingAction and AdminUserCreator.

}
