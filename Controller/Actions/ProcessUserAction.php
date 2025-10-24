<?php

namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Authorization\Model\ResourceModel\Role\Collection;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseFactory;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreManagerInterface;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthMessages;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MiniOrange\OAuth\Helper\Curl;
use Magento\Framework\Stdlib\DateTime\dateTime;


/**
 * This action class processes the user attributes coming in
 * the SAML response to either log the customer or admin in
 * to their respective dashboard or create a customer or admin
 * based on the default role set by the admin and log them in
 * automatically.
 */
class ProcessUserAction extends BaseAction
{
    private $attrs;
    private $flattenedattrs;
    private $userEmail;
    private $checkIfMatchBy;
    private $defaultRole;
    private $emailAttribute;
    private $usernameAttribute;
    private $firstNameKey;
    private $lastNameKey;

    private $userGroupModel;
    private $adminRoleModel;
    private $adminUserModel;
    private $customerModel;
    private $customerLoginAction;
    private $responseFactory;
    private $customerFactory;
    private $userFactory;
    private $randomUtility;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    protected $scopeConfig;
    protected $dateTime;

    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        \Magento\Customer\Model\ResourceModel\Group\Collection $userGroupModel,
        Collection $adminRoleModel,
        User $adminUserModel,
        Customer $customerModel,
        StoreManagerInterface $storeManager,
        ResponseFactory $responseFactory,
        CustomerLoginAction $customerLoginAction,
        CustomerFactory $customerFactory,
        UserFactory $userFactory,
        Random $randomUtility,
        dateTime $dateTime,
        ScopeConfigInterface $scopeConfig
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
        $this->checkIfMatchBy = $oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);
        $this->userGroupModel = $userGroupModel;
        $this->adminRoleModel = $adminRoleModel;
        $this->adminUserModel = $adminUserModel;
        $this->customerModel = $customerModel;
        $this->storeManager = $storeManager;
        $this->checkIfMatchBy = $oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);
        $this->responseFactory = $responseFactory;
        $this->customerLoginAction = $customerLoginAction;
        $this->customerFactory = $customerFactory;
        $this->userFactory = $userFactory;
        $this->randomUtility = $randomUtility;
        $this->scopeConfig = $scopeConfig;
        $this->dateTime=$dateTime;
            parent::__construct($context, $oauthUtility);
    }
    /**
     * Execute function to execute the classes function.
     *
     * @throws MissingAttributesException
     */
    public function execute()
    {    
        try {
            $this->oauthUtility->customlog("ProcessUserAction: execute")  ;
            // throw an exception if attributes are empty
            if (empty($this->attrs)) {
                $this->oauthUtility->customlog("No Attributes Received :")  ;
                throw new MissingAttributesException;
            }
            $firstName = $this->flattenedattrs[$this->firstNameKey] ?? null;
            $lastName = $this->flattenedattrs[$this->lastNameKey] ?? null;
            $userName = $this->flattenedattrs[$this->usernameAttribute] ?? null;
            $this->oauthUtility->customlog("ProcessUserAction: first name: ".$firstName)  ;
            $this->oauthUtility->customlog("ProcessUserAction: last name :".$lastName)  ;
            $this->oauthUtility->customlog("ProcessUserAction: username :".$userName)  ;
            if ($this->oauthUtility->isBlank($this->defaultRole)) {
                $this->defaultRole = OAuthConstants::DEFAULT_ROLE;
            }
            
            // Prüfen, ob Headers bereits gesendet wurden
            if (headers_sent($file, $line)) {
                $this->oauthUtility->customlog("WARNING: Headers already sent in $file:$line");
            }
    
            // process the user
            $this->processUserAction($this->userEmail, $firstName, $lastName, $userName, $this->defaultRole);
            
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CRITICAL ERROR in execute: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }


    /**
     * This function processes the user values to either create
     * a new user on the site and log him/her in or log an existing
     * user to the site. Mapping is done based on $checkIfMatchBy
     * variable. Either email or username.
     *
     * @param $user_email
     * @param $firstName
     * @param $lastName
     * @param $userName
     * @param $checkIfMatchBy
     * @param $defaultRole
     */
    private function processUserAction($user_email, $firstName, $lastName, $userName, $defaultRole)
    {
        $admin = false;

        // check if the a customer or admin user exists based on the email in OAuth response
        $user = $this->getCustomerFromAttributes($user_email);

        if (!$user) {
            $this->oauthUtility->customlog("User Not found. Inside autocreate user tab")  ;
            $donotCreateUsers=$this->oauthUtility->getStoreConfig(OAuthConstants::MAGENTO_COUNTER);
            if (is_null($donotCreateUsers)) {
                 $this->oauthUtility->setStoreConfig(OAuthConstants::MAGENTO_COUNTER, 10);
                 $this->oauthUtility->reinitConfig();
                 $donotCreateUsers=$this->oauthUtility->getStoreConfig(OAuthConstants::MAGENTO_COUNTER);
            }
            if ($donotCreateUsers<1) {
                $this->oauthUtility->customlog("Your Auto Create User Limit for the free Miniorange Magento OAuth/OpenID plugin is exceeded. Please Upgrade to any of the Premium Plan to continue the service.")  ;
                $email = $this->scopeConfig->getValue('trans_email/ident_general/email',ScopeInterface::SCOPE_STORE);
                $site = $this->oauthUtility->getBaseUrl();
                $magentoVersion = $this->oauthUtility->getProductVersion();
                $this->oauthUtility->reinitConfig();
                $previousDate = $this->oauthUtility->getStoreConfig(OAuthConstants::PREVIOUS_DATE);
                $timeStamp = $this->oauthUtility->getStoreConfig(OAuthConstants::TIME_STAMP);
                if($timeStamp == null){
                    $timeStamp = time();
                    $this->oauthUtility->setStoreConfig(OAuthConstants::TIME_STAMP,$timeStamp);
                    $this->oauthUtility->flushCache();
                }
                $adminEmail = '';
                $domain = $this->oauthUtility->getBaseUrl();
                $pluginFirstPageVisit   = '';
                $freeInstalledDate = $this->oauthUtility->getCurrentDate();
                $identityProvider = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
                $testSuccessful = '';
                $testFailed = '';
                $autoCreateLimit = 'Yes';
                $environmentName = $this->oauthUtility->getEdition();
                $environmentVersion = $this->oauthUtility->getProductVersion();
                $miniorangeAccountEmail= $this->oauthUtility->getStoreConfig(OAuthConstants::CUSTOMER_EMAIL);
                $ssoProvider =  $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
                $trackingDate = $this->oauthUtility->getCurrentDate();

                if($previousDate == NULL){
                    $previousDate = $this->dateTime->gmtDate('Y-m-d H:i:s');
                    $this->oauthUtility->setStoreConfig(OAuthConstants::PREVIOUS_DATE,$previousDate);
                    Curl::submit_to_magento_team( $timeStamp,
                    $adminEmail,
   $domain,
   $miniorangeAccountEmail,
   $pluginFirstPageVisit,
   $environmentName,
   $environmentVersion,
   $freeInstalledDate,
   $identityProvider,
   $testSuccessful,
   $testFailed,
   $autoCreateLimit);
                }
                $currentDate = $this->dateTime->gmtDate('Y-m-d H:i:s');
                $previousDate=date_create($previousDate);
                $currentDate=date_create($currentDate);
                $diff=date_diff($previousDate,$currentDate);
                $diff = $diff->format("%R%a days");
                if($diff > 0){
                $this->oauthUtility->setStoreConfig(OAuthConstants::PREVIOUS_DATE, $currentDate);
                Curl::submit_to_magento_team( $timeStamp,
                $adminEmail,
   $domain,
   $miniorangeAccountEmail,
   $pluginFirstPageVisit,
   $environmentName,
   $environmentVersion,
   $freeInstalledDate,
   $identityProvider,
   $testSuccessful,
   $testFailed,
   $autoCreateLimit);
            }
                $this->messageManager->addErrorMessage(OAuthMessages::AUTO_CREATE_USER_LIMIT);
                 $url = $this->oauthUtility->getCustomerLoginUrl();
                return $this->getResponse()->setRedirect($url)->sendResponse();
            } else {
                $count=$this->oauthUtility->getStoreConfig(OAuthConstants::MAGENTO_COUNTER);
                $this->oauthUtility->setStoreConfig(OAuthConstants::MAGENTO_COUNTER, $count-1);
                $this->oauthUtility->reinitConfig();
                $this->oauthUtility->customlog("Creating new customer");
                 $user = $this->createNewUser($user_email, $firstName, $lastName, $userName, $user, $admin);
               $this->oauthUtility->customlog("processUserAction: user created")  ;
            }
        }else{
            $this->oauthUtility->customlog("processUserAction: User Found")  ;
        }

        // log the user in to it's respective dashboard
    $this->oauthUtility->customlog("processUserAction: redirecting user")  ;
    // Process the user
    $store_url = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
    $store_url = rtrim($store_url, '/\\');
    
    // Check for external URL redirects
    if (isset($this->attrs['relayState']) && !str_contains($this->attrs['relayState'], $store_url) && $this->attrs['relayState']!='/') {
        $this->attrs['relayState'] = $store_url;
        $this->oauthUtility->customlog("processUserAction: changing relayState with store url " .$store_url);
    }

    // Check if relayState contains admin to determine if this is an admin login
    $isAdminLogin = false;
    $relayState = '';
    
    if (is_array($this->attrs) && isset($this->attrs['relayState'])) {
        $relayState = $this->attrs['relayState'];
    } elseif (isset($this->attrs->relayState)) {
        $relayState = $this->attrs->relayState;
    }
    
    // Prüfen, ob der relayState '/admin' enthält oder mit 'admin' beginnt
    if (!empty($relayState)) {
        $isAdminLogin = (strpos($relayState, '/admin') !== false) || 
                        (strpos($relayState, 'admin/') !== false) || 
                        (strpos($relayState, 'admin') === 0);
        $this->oauthUtility->customlog("processUserAction: Relay state: " . $relayState . ", isAdmin: " . ($isAdminLogin ? "true" : "false"));
    }

    if ($isAdminLogin) {
        // This is an admin login request
        $this->oauthUtility->customlog("processUserAction: Processing as admin login");
        
        try {
            // Neue vereinfachte Methode: Verwendung des AdminAuthHelper für die Weiterleitung
            require_once dirname(__FILE__) . '/../../Helper/AdminAuthHelper.php';
            
            $adminUser = $this->getAdminUserByEmail($user_email);
            if ($adminUser && $adminUser->getIsActive() == 1) {
                $this->oauthUtility->customlog("processUserAction: Admin user found and is active, redirecting to standalone auto-login script");
                
                // Verwenden des neuen Helpers für die Generierung der Login-URL
                $redirectUrl = \MiniOrange\OAuth\Helper\AdminAuthHelper::getStandaloneLoginUrl($user_email, $relayState);
                
                $this->oauthUtility->customlog("processUserAction: Redirecting to standalone login script: " . $redirectUrl);
                $this->getResponse()->setRedirect($redirectUrl)->sendResponse();
                return;
            } else {
                $this->oauthUtility->customlog("processUserAction: Admin user not found for email: " . $user_email);
                // No admin user found for this email, redirect to admin login page with error
                $this->messageManager->addErrorMessage('Keine Admin-Berechtigung für diesen Benutzer.');
                return $this->getResponse()->setRedirect($this->oauthUtility->getBaseUrl() . 'admin')->sendResponse();
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("processUserAction: Exception during admin login: " . $e->getMessage());
            $this->messageManager->addErrorMessage('Fehler bei der Anmeldung: ' . $e->getMessage());
            return $this->getResponse()->setRedirect($this->oauthUtility->getBaseUrl() . 'admin')->sendResponse();
        }
    } else {
        // Standard customer login flow
        $this->oauthUtility->customlog("processUserAction: Processing as customer login");
        if ($this->oauthUtility->getSessionData('guest_checkout')) {
            $this->oauthUtility->setSessionData('guest_checkout', NULL);
            $this->customerLoginAction->setUser($user)->setRelayState($this->oauthUtility->getBaseUrl().'checkout')->execute();
        } else if (is_array($this->attrs)) {
            $this->customerLoginAction->setUser($user)->setRelayState($this->attrs['relayState'])->execute();
        } else {
            $this->customerLoginAction->setUser($user)->setRelayState($this->attrs->relayState)->execute();
        }
    }
    }


    /**
     * Create a temporary email address based on the username
     * in the SAML response. Email Address is a required so we
     * need to generate a temp/fake email if no email comes from
     * the IDP in the SAML response.
     *
     * @param  $userName
     * @return string
     */
    private function generateEmail($userName)
    { $this->oauthUtility->customlog("processUserAction : generateEmail");

        $siteurl = $this->oauthUtility->getBaseUrl();
        $siteurl = substr($siteurl, strpos($siteurl, '//'), strlen($siteurl)-1);
        return $userName .'@'.$siteurl;
    }

    /**
     * Create a new user based on the SAML response and attributes. Log the user in
     * to it's appropriate dashboard. This class handles generating both admin and
     * customer users.
     *
     * @param $user_email
     * @param $firstName
     * @param $lastName
     * @param $userName
     * @param $defaultRole
     * @param $user
     */
    private function createNewUser($user_email, $firstName, $lastName, $userName, $user, &$admin)
    {

        // generate random string to be inserted as a password
   $this->oauthUtility->customlog("processUserAction: createNewUser") ;
  if(empty($firstName)){ $parts  = explode("@", $user_email);
    $firstName = $parts[0];
  } 
  if(empty($lastName)){
    $parts  = explode("@",$user_email);
    $lastName = $parts[1];
  } 
   $random_password = $this->randomUtility->getRandomString(8);
        $userName = !$this->oauthUtility->isBlank($userName)? $userName : $user_email;
        $firstName = !$this->oauthUtility->isBlank($firstName) ? $firstName : $userName;
        $lastName = !$this->oauthUtility->isBlank($lastName) ? $lastName : $userName;

        // create admin or customer user based on the role
        $user = $this->createCustomer($userName, $user_email, $firstName, $lastName, $random_password);

        return $user;
    }


    /**
     * Create a new customer.
     *
     * @param $email
     * @param $userName
     * @param $random_password
     * @param $role_assigned
     */
    private function createCustomer($userName, $email, $firstName, $lastName, $random_password)
    { $this->oauthUtility->customlog("processUserAction: createCustomer") ;

        $websiteId = $this->storeManager->getWebsite()->getWebsiteId();
        $store = $this->storeManager->getStore();
        $storeId = $store->getStoreId();
        $this->oauthUtility->customlog("processUserAction: websiteID :".$websiteId." : email: ".$email." :firstName: ".$firstName." lastname: ".$lastName) ;
        return $this->customerFactory->create()
            ->setWebsiteId($websiteId)
            ->setEmail($email)
            ->setFirstname($firstName)
            ->setLastname($lastName)
            ->setPassword($random_password)
            ->save();
    }

    /**
     * Get the Customer User from the Attributes in the SAML response
     * Return false if the customer doesn't exist. The customer is fetched
     * by email only. There are no usernames to set for a Magento Customer.
     *
     * @param $user_email
     * @param $userName
     */
    private function getCustomerFromAttributes($user_email)
    {  $this->oauthUtility->customlog("processUserAction: getCustomerFromAttributes") ;
        $this->customerModel->setWebsiteId($this->storeManager->getStore()->getWebsiteId());
        $customer = $this->customerModel->loadByEmail($user_email);
        return !is_null($customer->getId()) ? $customer : false;
    }


    /**
     * The setter function for the Attributes Parameter
     */
    public function setAttrs($attrs)
    {
        $this->attrs = $attrs;
        return $this;
    }

    /**
     * The setter function for the Attributes Parameter
     */
    public function setFlattenedAttrs($flattenedattrs)
    {
        $this->flattenedattrs = $flattenedattrs;
        return $this;
    }

    /**
     * The setter function for the Attributes Parameter
     */
    public function setUserEmail($userEmail)
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    /**
     * Get the Admin User by Email address
     * 
     * @param string $email
     * @return \Magento\User\Model\User|false
     */
    private function getAdminUserByEmail($email)
    {
        $this->oauthUtility->customlog("processUserAction: getAdminUserByEmail for " . $email);
        try {
            // Zuerst nach Benutzername suchen
            $user = $this->userFactory->create()->loadByUsername($email);
            if ($user->getId()) {
                $this->oauthUtility->customlog("processUserAction: Found admin user with ID " . $user->getId() . ", Username: " . $user->getUsername() . ", Is Active: " . $user->getIsActive());
                return $user;
            }

            // Wenn nicht gefunden, nach E-Mail-Adresse suchen
            $collection = $this->userFactory->create()->getCollection()
                ->addFieldToFilter('email', $email);
            
            if ($collection->getSize() > 0) {
                $user = $collection->getFirstItem();
                $this->oauthUtility->customlog("processUserAction: Found admin user by email with ID " . $user->getId() . ", Username: " . $user->getUsername() . ", Is Active: " . $user->getIsActive());
                
                // Prüfen, ob Benutzer aktiv ist
                if ($user->getIsActive() != 1) {
                    $this->oauthUtility->customlog("processUserAction: Admin user found but is not active");
                }
                
                return $user;
            }
            
            $this->oauthUtility->customlog("processUserAction: No admin user found for email " . $email);
            return false;
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("processUserAction: Error finding admin user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new admin user with the specified role
     *
     * @param $email
     * @param $firstName
     * @param $lastName
     * @param $userName
     * @param $random_password
     * @param $role_assigned
     */
    private function createAdminUser($userName, $email, $firstName, $lastName, $random_password, $role_assigned)
    {
        $this->oauthUtility->customlog("processUserAction: Creating Admin user"); 
        $user = $this->userFactory->create();
        $user->setUsername($userName)
            ->setFirstname($firstName)
            ->setLastname($lastName)
            ->setEmail($email)
            ->setPassword($random_password)
            ->setIsActive(1);

        $user->save();

        $this->setAdminUserRole($role_assigned, $user);
    
        return $user;
    }

    /**
     * Set the role of an admin user.
     *
     * @param $role_assigned
     * @param $user
     */
    private function setAdminUserRole($role_assigned, $user)
    {
        $this->oauthUtility->customlog("processUserAction: Set Admin User Role"); 
        $this->oauthUtility->customlog("processUserAction: role ID: ".$role_assigned); 

        $user->setRoleId($role_assigned);
        $user->save();

        return $user;
    }
}